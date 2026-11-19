import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';
import 'product_filters.dart';

/// Figma "08 Results grid", "09 Results list", "10 Filter sheet", "11 Sort
/// sheet": search results / category PLP with facets, sort, grid/list toggle
/// and infinite pagination (`GET products`, `GET products/facets`).
class ProductListScreen extends StatefulWidget {
  const ProductListScreen({super.key, this.args = const {}});

  final Map<String, dynamic> args;

  @override
  State<ProductListScreen> createState() => _ProductListScreenState();
}

class _ProductListScreenState extends State<ProductListScreen> {
  late ProductFilters _filters = ProductFilters.fromArgs(widget.args);
  final _scroll = ScrollController();
  final List<Json> _items = [];
  Json? _facets;
  int _page = 0;
  int _lastPage = 1;
  int? _total;
  bool _loading = false;
  Object? _error;
  bool _list = false;

  static const _perPage = 20;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 600) _loadMore();
    });
    _reload();
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  ApiClient get _api => AppScope.read(context).api;

  /// `products`, or a store's own listing (`sellers/{slug}/products`, QC-008).
  String get _endpoint => widget.args['endpoint'] as String? ?? 'products';

  Future<void> _reload() async {
    setState(() {
      _items.clear();
      _page = 0;
      _lastPage = 1;
      _error = null;
    });
    _api.getObject('$_endpoint/facets', query: _filters.query(forFacets: true)).then((f) {
      if (mounted) setState(() => _facets = f);
    }).catchError((_) {});
    await _loadMore();
  }

  Future<void> _loadMore() async {
    if (_loading || _page >= _lastPage) return;
    setState(() => _loading = true);
    try {
      final res = await _api.getList(_endpoint, query: _filters.query(page: _page + 1, perPage: _perPage));
      if (!mounted) return;
      setState(() {
        _items.addAll(res.data);
        _page = res.page;
        _lastPage = res.lastPage;
        _total = res.total;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String get _title {
    final t = widget.args['title'] as String?;
    if (t != null) return t;
    if (_filters.q != null) return '"${_filters.q}"';
    return context.t('plp.title');
  }

  Future<void> _openFilters({String? focus}) async {
    final result = await showModalBottomSheet<ProductFilters>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => FilterSheet(filters: _filters.copy(), facets: _facets ?? const {}, focus: focus, api: _api, endpoint: _endpoint),
    );
    if (result != null) {
      _filters = result;
      await _reload();
    }
  }

  Future<void> _openSort() async {
    final sort = await showNawySheet<String>(
      context,
      title: context.t('plp.sortBy'),
      builder: (ctx) => ListView(shrinkWrap: true, children: [
        for (final s in ProductFilters.sorts)
          ListTile(
            key: ValueKey('sort-$s'),
            title: Text(ctx.t('sort.$s')),
            trailing: _filters.sort == s ? const Icon(Icons.check_rounded) : null,
            onTap: () => Navigator.of(ctx).pop(s),
          ),
      ]),
    );
    if (sort != null && sort != _filters.sort) {
      _filters.sort = sort;
      await _reload();
    }
  }

  @override
  Widget build(BuildContext context) {
    final brands = (_facets?.l('brands') ?? const <Json>[]).take(8).toList();
    return Scaffold(
      appBar: AppBar(
        title: Text(_title, maxLines: 1, overflow: TextOverflow.ellipsis),
        actions: [
          IconButton(tooltip: context.t('search.title'), onPressed: () => Navigator.of(context).pushNamed(Routes.search, arguments: {'q': _filters.q}), icon: const Icon(Icons.search_rounded)),
          IconButton(tooltip: context.t('tabs.cart'), onPressed: () => Navigator.of(context).pushNamed(Routes.cart), icon: const Icon(Icons.shopping_cart_outlined)),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: CustomScrollView(controller: _scroll, slivers: [
          SliverPersistentHeader(
            pinned: true,
            delegate: _ChipBar(
              height: 52,
              child: Container(
                color: NawyColors.bgPage,
                child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8), children: [
                  _chip(
                    key: const ValueKey('chip-filter'),
                    icon: Icons.tune_rounded,
                    label: _filters.activeCount > 0 ? '${context.t('plp.filter')} (${_filters.activeCount})' : context.t('plp.filter'),
                    active: _filters.activeCount > 0,
                    onTap: () => _openFilters(),
                  ),
                  _chip(key: const ValueKey('chip-sort'), icon: Icons.swap_vert_rounded, label: context.t('sort.${_filters.sort}'), active: _filters.sort != 'recommended', onTap: _openSort),
                  // QC-027 quick chips: deals, Fulfilled by Nawy, condition, 4★ & up.
                  for (final q in quickChips(_facets))
                    _chip(
                      key: ValueKey('quick-${q.key}'),
                      label: context.t(q.label),
                      active: q.isActive(_filters),
                      onTap: () {
                        q.toggle(_filters);
                        _reload();
                      },
                    ),
                  _chip(label: context.t('plp.brand'), trailingDown: true, active: _filters.brands.isNotEmpty, onTap: () => _openFilters(focus: 'brand')),
                  _chip(label: context.t('plp.price'), trailingDown: true, active: _filters.minPrice != null || _filters.maxPrice != null, onTap: () => _openFilters(focus: 'price')),
                  for (final b in brands)
                    _chip(
                      label: b.str('name'),
                      active: _filters.brands.contains(b.str('slug')),
                      onTap: () {
                        _filters.toggle(_filters.brands, b.str('slug'));
                        _reload();
                      },
                    ),
                ]),
              ),
            ),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 8, 8),
              child: Row(children: [
                Expanded(
                  child: Text(
                    _total == null ? '' : context.t('plp.results', {'count': compactCount(_total!), 'q': _filters.q ?? _title}),
                    style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
                  ),
                ),
                IconButton(
                  key: const ValueKey('toggle-layout'),
                  tooltip: context.t(_list ? 'plp.grid' : 'plp.list'),
                  onPressed: () => setState(() => _list = !_list),
                  icon: Icon(_list ? Icons.grid_view_rounded : Icons.view_list_rounded),
                ),
              ]),
            ),
          ),
          if (_items.isEmpty && _loading)
            const SliverFillRemaining(child: LoadingView())
          else if (_items.isEmpty && _error != null)
            SliverFillRemaining(child: ErrorView(error: _error!, onRetry: _reload))
          else if (_items.isEmpty)
            SliverFillRemaining(
              child: EmptyView(
                icon: Icons.search_off_rounded,
                title: context.t('plp.empty'),
                subtitle: context.t('plp.emptyHint'),
                actionLabel: _filters.activeCount > 0 ? context.t('plp.clearAll') : null,
                onAction: () {
                  _filters.clear();
                  _reload();
                },
              ),
            )
          else if (_list)
            SliverList.separated(
              itemCount: _items.length,
              separatorBuilder: (_, _) => const Divider(height: 1, indent: 16, endIndent: 16),
              itemBuilder: (_, i) => Padding(padding: const EdgeInsets.all(12), child: ProductCard(card: _items[i], variant: ProductCardVariant.list)),
            )
          else
            SliverToBoxAdapter(child: ProductGrid(cards: _items)),
          SliverToBoxAdapter(child: _loading && _items.isNotEmpty ? const LoadingView(padding: EdgeInsets.all(16)) : const SizedBox(height: 32)),
        ]),
      ),
    );
  }

  Widget _chip({Key? key, IconData? icon, required String label, bool active = false, bool trailingDown = false, required VoidCallback onTap}) => Padding(
        key: key,
        padding: const EdgeInsetsDirectional.only(end: 8),
        child: Material(
          color: active ? NawyColors.bgInverse : NawyColors.bgSurface,
          shape: StadiumBorder(side: BorderSide(color: active ? NawyColors.bgInverse : NawyColors.borderDefault)),
          child: InkWell(
            customBorder: const StadiumBorder(),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                if (icon != null) ...[Icon(icon, size: 16, color: active ? NawyColors.textInverse : NawyColors.textPrimary), const SizedBox(width: 4)],
                Text(label, style: NawyText.label.copyWith(color: active ? NawyColors.textInverse : NawyColors.textPrimary)),
                if (trailingDown) Icon(Icons.keyboard_arrow_down_rounded, size: 16, color: active ? NawyColors.textInverse : NawyColors.textPrimary),
              ]),
            ),
          ),
        ),
      );
}

/// A one-tap PLP refinement (QC-027).
class QuickChip {
  const QuickChip(this.key, this.label, this.isActive, this.toggle);

  final String key;
  final String label;
  final bool Function(ProductFilters f) isActive;
  final void Function(ProductFilters f) toggle;
}

/// Quick chips offered for these facets: a chip shows only when the facet
/// has matches (or the chip is already on). Pure, unit tested.
List<QuickChip> quickChips(Json? facets) {
  int count(String list, String value) => (facets?.l(list) ?? const <Json>[]).where((e) => e.s('value') == value).fold(0, (s, e) => s + (e.i('count') ?? 0));
  final known = facets != null;
  return [
    if (!known || (facets.i('deals') ?? 0) > 0) QuickChip('deals', 'plp.quickDeals', (f) => f.deals, (f) => f.deals = !f.deals),
    if (!known || count('fulfilment', 'fbn') > 0) QuickChip('fbn', 'plp.quickFbn', (f) => f.fulfilment.contains('fbn'), (f) => f.toggle(f.fulfilment, 'fbn')),
    if (known && count('conditions', 'new') > 0 && count('conditions', 'refurbished') > 0) ...[
      QuickChip('new', 'plp.condition.new', (f) => f.conditions.contains('new'), (f) => f.toggle(f.conditions, 'new')),
      QuickChip('refurbished', 'plp.condition.refurbished', (f) => f.conditions.contains('refurbished'), (f) => f.toggle(f.conditions, 'refurbished')),
    ],
    QuickChip('rating4', 'plp.quickRating', (f) => f.rating == 4, (f) => f.rating = f.rating == 4 ? null : 4),
  ];
}

class _ChipBar extends SliverPersistentHeaderDelegate {
  _ChipBar({required this.height, required this.child});

  final double height;
  final Widget child;

  @override
  double get minExtent => height;
  @override
  double get maxExtent => height;

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) => SizedBox(height: height, child: child);

  @override
  bool shouldRebuild(_ChipBar oldDelegate) => true;
}

/// Full-height filter sheet: facet rail + options, live "Show N results".
class FilterSheet extends StatefulWidget {
  const FilterSheet({super.key, required this.filters, required this.facets, required this.api, this.focus, this.endpoint = 'products'});

  final ProductFilters filters;
  final Json facets;
  final ApiClient api;
  final String? focus;
  final String endpoint;

  @override
  State<FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<FilterSheet> {
  late String _facet = widget.focus ?? 'sort';
  int? _count;
  int _seq = 0;
  late final _min = TextEditingController(text: widget.filters.minPrice);
  late final _max = TextEditingController(text: widget.filters.maxPrice);

  ProductFilters get f => widget.filters;

  @override
  void initState() {
    super.initState();
    _recount();
  }

  @override
  void dispose() {
    _min.dispose();
    _max.dispose();
    super.dispose();
  }

  Future<void> _recount() async {
    final seq = ++_seq;
    try {
      final res = await widget.api.get(widget.endpoint, query: f.query(page: 1, perPage: 1));
      if (mounted && seq == _seq) setState(() => _count = res.total);
    } catch (_) {}
  }

  void _update(VoidCallback change) {
    setState(change);
    _recount();
  }

  List<(String, String)> get _rail => [
        ('sort', context.t('plp.sortBy')),
        if (widget.facets.l('brands').isNotEmpty) ('brand', context.t('plp.brand')),
        ('price', context.t('plp.price')),
        ('rating', context.t('plp.rating')),
        if (widget.facets.l('sellers').isNotEmpty) ('seller', context.t('plp.seller')),
        if (widget.facets.l('fulfilment').isNotEmpty) ('fulfilment', context.t('plp.fulfilment')),
        if (widget.facets.l('conditions').isNotEmpty) ('condition', context.t('plp.condition')),
        if ((widget.facets.i('deals') ?? 0) > 0 || f.deals) ('deals', context.t('plp.deals')),
        for (final a in widget.facets.l('attributes')) ('attr:${a.str('key')}', context.l10n.status(a.str('key'))),
      ];

  bool _active(String key) => switch (key) {
        'sort' => f.sort != 'recommended',
        'brand' => f.brands.isNotEmpty,
        'price' => f.minPrice != null || f.maxPrice != null,
        'rating' => f.rating != null,
        'seller' => f.sellers.isNotEmpty,
        'fulfilment' => f.fulfilment.isNotEmpty,
        'condition' => f.conditions.isNotEmpty,
        'deals' => f.deals,
        _ => key.startsWith('attr:') && (f.attributes[key.substring(5)]?.isNotEmpty ?? false),
      };

  @override
  Widget build(BuildContext context) => SizedBox(
        height: MediaQuery.of(context).size.height * 0.9,
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 8, 8),
            child: Row(children: [
              Expanded(child: Text(context.t('plp.filters'), style: NawyText.h3)),
              IconButton(tooltip: context.t('common.close'), onPressed: () => Navigator.of(context).pop(), icon: const Icon(Icons.close_rounded)),
            ]),
          ),
          const Divider(),
          Expanded(
            child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Container(
                width: 120,
                color: NawyColors.bgPage,
                child: ListView(children: [
                  for (final (key, label) in _rail)
                    InkWell(
                      key: ValueKey('facet-$key'),
                      onTap: () => setState(() => _facet = key),
                      child: Container(
                        color: _facet == key ? NawyColors.bgSurface : null,
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
                        child: Row(children: [
                          Expanded(child: Text(label, style: NawyText.label.copyWith(fontWeight: _facet == key ? FontWeight.w700 : FontWeight.w500))),
                          if (_active(key)) Container(width: 6, height: 6, decoration: const BoxDecoration(color: NawyColors.bgBrand, shape: BoxShape.circle)),
                        ]),
                      ),
                    ),
                ]),
              ),
              Expanded(child: ListView(padding: const EdgeInsets.symmetric(vertical: 8), children: _options(context))),
            ]),
          ),
          const Divider(),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(children: [
                TextButton(
                  onPressed: () => _update(() {
                    f.clear();
                    _min.clear();
                    _max.clear();
                  }),
                  child: Text(context.t('plp.clearAll')),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton(
                    key: const ValueKey('filters-apply'),
                    onPressed: () => Navigator.of(context).pop(f),
                    child: Text(_count == null ? context.t('plp.showResults') : context.t('plp.showN', {'count': compactCount(_count!)})),
                  ),
                ),
              ]),
            ),
          ),
        ]),
      );

  List<Widget> _options(BuildContext context) {
    if (_facet == 'sort') {
      return [
        for (final s in ProductFilters.sorts)
          ListTile(title: Text(context.t('sort.$s')), trailing: f.sort == s ? const Icon(Icons.check_rounded) : null, onTap: () => _update(() => f.sort = s)),
      ];
    }
    if (_facet == 'brand') {
      return [
        for (final b in widget.facets.l('brands'))
          CheckboxListTile(
            value: f.brands.contains(b.str('slug')),
            onChanged: (_) => _update(() => f.toggle(f.brands, b.str('slug'))),
            title: Text(b.str('name')),
            secondary: Text('${b.i('count') ?? ''}', style: NawyText.caption),
            controlAffinity: ListTileControlAffinity.leading,
          ),
      ];
    }
    if (_facet == 'seller') {
      return [
        for (final s in widget.facets.l('sellers'))
          if (s.s('slug') != null)
            CheckboxListTile(
              value: f.sellers.contains(s.str('slug')),
              onChanged: (_) => _update(() => f.toggle(f.sellers, s.str('slug'))),
              title: Text(s.str('name')),
              subtitle: s.s('rating') == null ? null : Text('★ ${s.s('rating')}'),
              controlAffinity: ListTileControlAffinity.leading,
            ),
      ];
    }
    if (_facet == 'fulfilment' || _facet == 'condition') {
      final fulfilment = _facet == 'fulfilment';
      final set = fulfilment ? f.fulfilment : f.conditions;
      return [
        for (final v in widget.facets.l(fulfilment ? 'fulfilment' : 'conditions'))
          CheckboxListTile(
            key: ValueKey('facet-$_facet-${v.str('value')}'),
            value: set.contains(v.str('value')),
            onChanged: (_) => _update(() => f.toggle(set, v.str('value'))),
            title: Text(context.t('plp.$_facet.${v.str('value')}')),
            secondary: Text('${v.i('count') ?? ''}', style: NawyText.caption),
            controlAffinity: ListTileControlAffinity.leading,
          ),
      ];
    }
    if (_facet == 'deals') {
      return [
        SwitchListTile(
          key: const ValueKey('facet-deals-switch'),
          value: f.deals,
          onChanged: (v) => _update(() => f.deals = v),
          title: Text(context.t('plp.dealsOnly')),
          subtitle: Text(context.t('plp.dealsCount', {'count': widget.facets.i('deals') ?? 0})),
        ),
      ];
    }
    if (_facet == 'price') {
      final price = widget.facets.m('price');
      return [
        Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (price?.m('min') != null)
              Text(formatMoneyRange(context, price!), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: TextField(
                  controller: _min,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(labelText: context.t('plp.min')),
                  onChanged: (v) => _update(() => f.minPrice = v.trim().isEmpty ? null : v.trim()),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: TextField(
                  controller: _max,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(labelText: context.t('plp.max')),
                  onChanged: (v) => _update(() => f.maxPrice = v.trim().isEmpty ? null : v.trim()),
                ),
              ),
            ]),
          ]),
        ),
      ];
    }
    if (_facet == 'rating') {
      return [
        for (final stars in const [4, 3, 2, 1])
          ListTile(
            key: ValueKey('rating-$stars'),
            leading: Icon(f.rating == stars ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded),
            onTap: () => _update(() => f.rating = f.rating == stars ? null : stars),
            title: Text(context.t('plp.starsUp', {'n': stars})),
          ),
      ];
    }
    if (_facet.startsWith('attr:')) {
      final key = _facet.substring(5);
      final attr = widget.facets.l('attributes').firstWhere((a) => a.str('key') == key, orElse: () => {});
      return [
        for (final v in attr.l('values'))
          CheckboxListTile(
            value: f.attributes[key]?.contains(v.str('value')) ?? false,
            onChanged: (_) => _update(() => f.toggleAttribute(key, v.str('value'))),
            title: Text(v.str('value')),
            secondary: Text('${v.i('count') ?? ''}', style: NawyText.caption),
            controlAffinity: ListTileControlAffinity.leading,
          ),
      ];
    }
    return const [];
  }
}

String formatMoneyRange(BuildContext context, Json price) {
  final locale = context.l10n.locale;
  return '${formatMoneyJson(price['min'], locale)} – ${formatMoneyJson(price['max'], locale)}';
}

String formatMoneyJson(dynamic json, String locale) {
  final m = asJson(json);
  final amount = m.s('amount');
  final currency = m.s('currency');
  if (amount == null || currency == null) return '';
  return locale == 'ar' ? '$amount $currency' : '$currency $amount';
}
