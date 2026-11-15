import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../core/storage/secure_store.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

/// Figma "06 Search recent" + "07 Suggestions": recent searches (this
/// device), popular category chips, and as-you-type suggestions with the typed
/// prefix in bold. The API has no suggest endpoint, so suggestions come from
/// `GET products?q=` (titles) and `GET brands?q=` (see owner-review WP10 gaps).
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key, this.initial});

  final String? initial;

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  late final TextEditingController _q = TextEditingController(text: widget.initial ?? '');
  Timer? _debounce;
  List<Json> _recent = [];
  List<Json> _products = [];
  List<Json> _brands = [];
  bool _loading = false;
  int _seq = 0;

  @override
  void initState() {
    super.initState();
    AppScope.read(context).readList(StoreKeys.recentSearches).then((v) {
      if (mounted) setState(() => _recent = v);
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _q.dispose();
    super.dispose();
  }

  void _changed(String value) {
    _debounce?.cancel();
    setState(() {});
    if (value.trim().length < 2) {
      setState(() {
        _products = [];
        _brands = [];
      });
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 300), () => _suggest(value.trim()));
  }

  Future<void> _suggest(String term) async {
    final seq = ++_seq;
    setState(() => _loading = true);
    final api = AppScope.read(context).api;
    try {
      final results = await Future.wait([
        api.getList('products', query: {'q': term, 'per_page': 8}).then((r) => r.data),
        api.getList('brands', query: {'q': term}).then((r) => r.data).catchError((_) => <Json>[]),
      ]);
      if (!mounted || seq != _seq) return;
      setState(() {
        _products = results[0];
        _brands = results[1].take(3).toList();
      });
    } catch (_) {
      // Suggestions are best-effort.
    } finally {
      if (mounted && seq == _seq) setState(() => _loading = false);
    }
  }

  Future<void> _submit(String term) async {
    final q = term.trim();
    if (q.isEmpty) return;
    final app = AppScope.read(context);
    await app.pushRecent(StoreKeys.recentSearches, {'q': q}, idKey: 'q');
    if (!mounted) return;
    Navigator.of(context).pushReplacementNamed(Routes.products, arguments: {'q': q});
  }

  @override
  Widget build(BuildContext context) {
    final term = _q.text.trim();
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: TextField(
          key: const ValueKey('search-input'),
          controller: _q,
          autofocus: true,
          textInputAction: TextInputAction.search,
          onChanged: _changed,
          onSubmitted: _submit,
          decoration: InputDecoration(
            hintText: context.t('search.placeholder'),
            prefixIcon: const Icon(Icons.search_rounded),
            suffixIcon: term.isEmpty
                ? null
                : IconButton(tooltip: context.t('common.clear'), icon: const Icon(Icons.close_rounded), onPressed: () {
                    _q.clear();
                    _changed('');
                  }),
          ),
        ),
        actions: const [SizedBox(width: 12)],
      ),
      body: term.length < 2 ? _idle(context) : _suggestions(context, term),
    );
  }

  Widget _idle(BuildContext context) => ListView(padding: const EdgeInsets.only(bottom: 24), children: [
        if (_recent.isNotEmpty) ...[
          SectionHeader(
            title: context.t('search.recent'),
            trailing: TextButton(
              onPressed: () async {
                await AppScope.read(context).writeList(StoreKeys.recentSearches, []);
                setState(() => _recent = []);
              },
              child: Text(context.t('common.clear')),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Wrap(spacing: 8, runSpacing: 8, children: [
              for (final r in _recent) ActionChip(avatar: const Icon(Icons.history_rounded, size: 16), label: Text(r.str('q')), onPressed: () => _submit(r.str('q'))),
            ]),
          ),
        ],
        SectionHeader(title: context.t('search.trending')),
        OnceFuture<List<Json>>(
          load: () => AppScope.read(context).api.getList('categories').then((r) => r.data),
          builder: (context, snap) => Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Wrap(spacing: 8, runSpacing: 8, children: [
              for (final c in snap.data ?? const <Json>[])
                ActionChip(
                  avatar: const Icon(Icons.trending_up_rounded, size: 16),
                  label: Text(c.str('name')),
                  onPressed: () => Navigator.of(context).pushReplacementNamed(Routes.products, arguments: {'category': c.str('slug'), 'title': c.str('name')}),
                ),
            ]),
          ),
        ),
      ]);

  Widget _suggestions(BuildContext context, String term) {
    if (_loading && _products.isEmpty && _brands.isEmpty) return const LoadingView();
    return ListView(children: [
      ListTile(
        leading: const Icon(Icons.search_rounded),
        title: Text.rich(TextSpan(children: [TextSpan(text: context.t('search.searchFor')), TextSpan(text: ' "$term"', style: NawyText.bodyMedium)])),
        onTap: () => _submit(term),
      ),
      for (final b in _brands)
        ListTile(
          leading: const Icon(Icons.storefront_outlined),
          title: BoldPrefix(text: b.str('name'), prefix: term),
          subtitle: Text(context.t('search.brand')),
          onTap: () => Navigator.of(context).pushReplacementNamed(Routes.products, arguments: {'brand': b.str('slug'), 'title': b.str('name')}),
        ),
      for (final p in _products)
        ListTile(
          leading: SizedBox(width: 40, height: 40, child: NetImage(p.m('image')?.s('url'), radius: NawyRadius.sm)),
          title: BoldPrefix(text: p.str('title'), prefix: term),
          onTap: () => Navigator.of(context).pushNamed(Routes.product, arguments: {'slug': p.str('slug')}),
        ),
      if (!_loading && _products.isEmpty && _brands.isEmpty) EmptyView(icon: Icons.search_off_rounded, title: context.t('search.noSuggestions')),
    ]);
  }
}

/// Text with the matched prefix in bold (Noon suggestions).
class BoldPrefix extends StatelessWidget {
  const BoldPrefix({super.key, required this.text, required this.prefix});

  final String text;
  final String prefix;

  @override
  Widget build(BuildContext context) {
    final i = text.toLowerCase().indexOf(prefix.toLowerCase());
    if (prefix.isEmpty || i < 0) return Text(text, maxLines: 1, overflow: TextOverflow.ellipsis);
    return Text.rich(
      TextSpan(style: NawyText.body, children: [
        TextSpan(text: text.substring(0, i)),
        TextSpan(text: text.substring(i, i + prefix.length), style: NawyText.body.copyWith(fontWeight: FontWeight.w700)),
        TextSpan(text: text.substring(i + prefix.length)),
      ]),
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    );
  }
}
