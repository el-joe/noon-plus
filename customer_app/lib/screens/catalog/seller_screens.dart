import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/paged_list.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';
import '../auth/sign_in_screen.dart';

/// Seller storefront (QC-008): `GET sellers/{slug}` header (banner, logo,
/// badges, rating, followers, policies), follow / unfollow, and the store's
/// products (`sellers/{slug}/products`, paged by `meta.last_page`).
class SellerScreen extends StatefulWidget {
  const SellerScreen({super.key, required this.slug});

  final String slug;

  @override
  State<SellerScreen> createState() => _SellerScreenState();
}

class _SellerScreenState extends State<SellerScreen> {
  Json? _store;
  Object? _error;
  final List<Json> _products = [];
  int _page = 0;
  int _last = 1;
  int? _total;
  bool _loadingProducts = false;
  bool _following = false;
  int _followers = 0;
  bool _busyFollow = false;

  String get _path => 'sellers/${enc(widget.slug)}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _error = null;
      _products.clear();
      _page = 0;
      _last = 1;
    });
    try {
      final store = await AppScope.read(context).api.getObject(_path);
      if (!mounted) return;
      setState(() {
        _store = store;
        _following = store.b('following');
        _followers = store.i('followers') ?? 0;
      });
      await _more();
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _more() async {
    if (_loadingProducts || _page >= _last) return;
    setState(() => _loadingProducts = true);
    try {
      final res = await AppScope.read(context).api.getList('$_path/products', query: {'page': _page + 1, 'per_page': 20});
      if (!mounted) return;
      setState(() {
        _products.addAll(res.data);
        _page = res.page;
        _last = res.data.isEmpty ? _page : res.lastPage;
        _total = res.total;
      });
    } catch (_) {
      // The header still shows; the grid stays as it is.
    } finally {
      if (mounted) setState(() => _loadingProducts = false);
    }
  }

  Future<void> _toggleFollow() async {
    if (_busyFollow) return;
    if (!await requireSignIn(context) || !mounted) return;
    final api = AppScope.read(context).api;
    final next = !_following;
    setState(() => _busyFollow = true);
    final res = await guarded(context, () => next ? api.post('$_path/follow') : api.delete('$_path/follow'), success: context.t(next ? 'seller.followed' : 'seller.unfollowed'));
    if (!mounted) return;
    setState(() {
      _busyFollow = false;
      if (res != null) {
        final data = asJson(res.data);
        _following = data.containsKey('following') ? data.b('following') : next;
        _followers = data.i('followers') ?? _followers;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final store = _store;
    return Scaffold(
      appBar: AppBar(title: Text(store?.str('name') ?? '')),
      body: store == null
          ? (_error != null ? ErrorView(error: _error!, onRetry: _load) : const LoadingView())
          : NotificationListener<ScrollNotification>(
              onNotification: (n) {
                if (n.metrics.pixels > n.metrics.maxScrollExtent - 500) _more();
                return false;
              },
              child: RefreshIndicator(
                onRefresh: _load,
                child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
                  _header(context, store),
                  _policies(context, store),
                  SectionHeader(
                    title: context.t('seller.products', {'count': _total ?? store.i('product_count') ?? 0}),
                    trailing: TextButton.icon(
                      key: const ValueKey('seller-filter'),
                      onPressed: () => Navigator.of(context).pushNamed(Routes.products, arguments: {'endpoint': '$_path/products', 'title': store.str('name')}),
                      icon: const Icon(Icons.tune_rounded, size: 18),
                      label: Text(context.t('plp.filter')),
                    ),
                  ),
                  if (_products.isEmpty && !_loadingProducts)
                    EmptyView(icon: Icons.storefront_outlined, title: context.t('seller.noProducts'))
                  else
                    ProductGrid(cards: _products),
                  if (_loadingProducts) const LoadingView(padding: EdgeInsets.all(16)),
                ]),
              ),
            ),
    );
  }

  Widget _header(BuildContext context, Json store) {
    final rating = store.m('rating') ?? const {};
    final average = rating.s('average');
    final positive = rating.i('positive_percent');
    final banner = store.s('banner');
    return Container(
      color: NawyColors.bgSurface,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (banner != null) SizedBox(height: 120, child: NetImage(banner)),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              SizedBox(width: 56, height: 56, child: NetImage(store.s('logo'), radius: NawyRadius.lg, icon: Icons.storefront_outlined)),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(store.str('name'), style: NawyText.h3),
                  Text(
                    [
                      if (store.s('city') != null) store.s('city')!,
                      if (store.s('joined_at') != null) context.t('seller.joined', {'date': shortDate(context, store.s('joined_at'))}),
                    ].join(' · '),
                    style: NawyText.caption.copyWith(color: NawyColors.textSecondary),
                  ),
                ]),
              ),
            ]),
            const SizedBox(height: 10),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final b in store.l('badges')) Pill(b.str('label'), key: ValueKey('seller-badge-${b.str('key')}'), icon: Icons.verified_outlined),
            ]),
            const SizedBox(height: 10),
            Row(children: [
              RatingPill(average: average, count: rating.i('count')),
              if (positive != null) ...[
                const SizedBox(width: 8),
                Flexible(child: Text(context.t('seller.positive', {'percent': positive}), maxLines: 1, overflow: TextOverflow.ellipsis, style: NawyText.caption.copyWith(color: NawyColors.textSuccess))),
              ],
              const Spacer(),
              Text(context.t('seller.followers', {'count': compactCount(_followers)}), key: const ValueKey('seller-followers'), style: NawyText.caption),
            ]),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: _following
                  ? OutlinedButton.icon(
                      key: const ValueKey('seller-follow'),
                      onPressed: _busyFollow ? null : _toggleFollow,
                      icon: const Icon(Icons.check_rounded, size: 18),
                      label: Text(context.t('seller.following')),
                    )
                  : FilledButton.icon(
                      key: const ValueKey('seller-follow'),
                      onPressed: _busyFollow ? null : _toggleFollow,
                      icon: const Icon(Icons.add_rounded, size: 18),
                      label: Text(context.t('seller.follow')),
                    ),
            ),
            if (store.s('description') != null) ...[
              const SizedBox(height: 10),
              Text(store.str('description'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            ],
          ]),
        ),
      ]),
    );
  }

  Widget _policies(BuildContext context, Json store) {
    final policies = store.m('policies') ?? const {};
    final returns = policies.m('returns');
    final shipping = policies.m('shipping');
    final warranty = policies.m('warranty');
    final locale = context.l10n.locale;
    final freeOver = Money.fromJson(shipping?['free_over']);
    final fee = Money.fromJson(shipping?['fee']);
    final rows = <(IconData, String)>[
      if (returns != null && returns.i('window_days') != null) (Icons.replay_rounded, context.t('seller.returnsDays', {'days': returns.i('window_days')})),
      if (returns?.s('note') != null) (Icons.info_outline_rounded, returns!.str('note')),
      if (shipping != null && shipping.b('fulfilled_by_nawy')) (Icons.local_shipping_outlined, context.t('seller.fbn')),
      if (fee != null) (Icons.local_shipping_outlined, fee.isZero ? context.t('seller.freeShipping') : context.t('seller.shippingFee', {'amount': fee.format(locale: locale)})),
      if (freeOver != null && !freeOver.isZero) (Icons.card_giftcard_rounded, context.t('seller.freeOver', {'amount': freeOver.format(locale: locale)})),
      if (warranty?.s('note') != null) (Icons.verified_user_outlined, warranty!.str('note')),
    ];
    if (rows.isEmpty) return const SizedBox(height: 12);
    return SectionCard(
      key: const ValueKey('seller-policies'),
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      title: context.t('seller.policies'),
      child: Column(children: [
        for (final (icon, text) in rows)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(icon, size: 18, color: NawyColors.textSecondary),
              const SizedBox(width: 10),
              Expanded(child: Text(text, style: NawyText.bodySm)),
            ]),
          ),
      ]),
    );
  }
}

/// Account → Followed stores (`GET me/followed-sellers`, paged).
class FollowedStoresScreen extends StatelessWidget {
  const FollowedStoresScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('seller.followedTitle'))),
      body: !app.signedIn
          ? const SignInPrompt()
          : PagedList(
              load: (page) => app.api.getList('me/followed-sellers', query: {'page': page, 'per_page': 20}),
              empty: EmptyView(icon: Icons.storefront_outlined, title: context.t('seller.followedEmpty'), subtitle: context.t('seller.followedEmptyHint')),
              itemBuilder: (context, s) => SectionCard(
                margin: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                padding: EdgeInsets.zero,
                child: ListTile(
                  key: ValueKey('followed-${s.str('slug')}'),
                  leading: SizedBox(width: 44, height: 44, child: NetImage(s.s('logo'), radius: NawyRadius.md, icon: Icons.storefront_outlined)),
                  title: Text(s.str('name'), style: NawyText.bodyMedium),
                  subtitle: Row(children: [
                    RatingPill(average: s.m('rating')?.s('average'), count: s.m('rating')?.i('count')),
                    if (s.s('followed_at') != null) ...[
                      const SizedBox(width: 6),
                      Flexible(child: Text(context.t('seller.followedOn', {'date': shortDate(context, s.s('followed_at'))}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary))),
                    ],
                  ]),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () => Navigator.of(context).pushNamed(Routes.seller, arguments: {'slug': s.str('slug')}),
                ),
              ),
            ),
    );
  }
}
