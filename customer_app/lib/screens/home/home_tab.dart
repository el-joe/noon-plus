import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../features/page_builder/blocks.dart';
import '../../features/page_builder/vertical_cards.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';
import '../live/live_screens.dart';
import '../souq/souq_screens.dart';
import '../travel/travel_screens.dart';

/// The Home tab of an app context (ADR 16): its `home_page` (an `app_context`
/// page-builder page) when set; otherwise the vertical's own home, and for
/// shopping contexts `pages/home` with a built-in Noon-order fallback.
class ContextHome extends StatelessWidget {
  const ContextHome({super.key, required this.appContext});

  final Json appContext;

  @override
  Widget build(BuildContext context) {
    final key = appContext.str('key', 'main');
    final page = appContext.s('home_page');
    if (page != null) return PageBuilderView(slug: page, fallback: (_) => _verticalHome(key));
    return _verticalHome(key);
  }

  Widget _verticalHome(String key) => switch (key) {
        'souq' => const SouqHome(embedded: true),
        'property' => const SouqHome(embedded: true, vertical: 'property'),
        'travel' => const TravelHome(embedded: true),
        'live' => const LiveListScreen(asTab: true),
        'main' => PageBuilderView(slug: 'home', fallback: (_) => const FallbackHome()),
        _ => FallbackHome(contextKey: key),
      };
}

/// Renders a published page (`GET pages/{slug}`): `header` zone then `main`.
/// A 404 shows [fallback] (or an empty state).
class PageBuilderView extends StatelessWidget {
  const PageBuilderView({super.key, required this.slug, this.fallback});

  final String slug;
  final WidgetBuilder? fallback;

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    return AsyncBody<Json?>(
      key: ValueKey('page-$slug-${AppScope.of(context).market}'),
      load: () async {
        try {
          return await api.getObject('pages/${enc(slug)}');
        } on ApiException catch (e) {
          if (e.isNotFound) return null;
          rethrow;
        }
      },
      builder: (context, page, reload) {
        if (page == null) return fallback?.call(context) ?? EmptyView(title: context.t('state.notFound'));
        final zones = page.m('zones') ?? {};
        final blocks = [...asJsonList(zones['header']), ...asJsonList(zones['main']), ...asJsonList(zones['listing'])];
        final children = buildZone(context, blocks);
        if (children.isEmpty) return fallback?.call(context) ?? EmptyView(title: context.t('state.empty'));
        return RefreshIndicator(
          onRefresh: reload,
          child: ListView(padding: const EdgeInsets.only(bottom: 24), children: children),
        );
      },
    );
  }
}

/// A tab whose target is `page` (an admin-picked page).
class PageTab extends StatelessWidget {
  const PageTab({super.key, this.slug});

  final String? slug;

  @override
  Widget build(BuildContext context) =>
      SafeArea(child: slug == null ? EmptyView(title: context.t('state.notFound')) : PageBuilderView(slug: slug!));
}

/// `/page` route: any published page (campaigns, brand stores).
class PageScreen extends StatelessWidget {
  const PageScreen({super.key, required this.slug, this.title});

  final String slug;
  final String? title;

  @override
  Widget build(BuildContext context) => Scaffold(appBar: AppBar(title: Text(title ?? '')), body: PageBuilderView(slug: slug));
}

/// Built-in home in the Noon section order (§14c) from live API data, used
/// until the admin publishes a page for the context. Sections hide when empty.
class FallbackHome extends StatefulWidget {
  const FallbackHome({super.key, this.contextKey = 'main'});

  final String contextKey;

  @override
  State<FallbackHome> createState() => _FallbackHomeState();
}

class _FallbackHomeState extends State<FallbackHome> {
  int _generation = 0;

  Future<void> _refresh() async {
    setState(() => _generation++);
    await AppScope.read(context).cart.refresh().catchError((_) => null);
  }

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    final api = app.api;
    final g = '$_generation-${app.market}';
    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        key: ValueKey('fallback-home-$g'),
        padding: const EdgeInsets.only(bottom: 32),
        children: [
          // 3. Category circles (2 rows).
          OnceFuture<List<Json>>(
            key: ValueKey('cats-$g'),
            load: () => api.getList('categories').then((r) => r.data),
            builder: (context, snap) {
              final cats = snap.data ?? const <Json>[];
              if (snap.connectionState != ConnectionState.done) return const LoadingView();
              if (snap.hasError && cats.isEmpty) return ErrorView(error: snap.error!, onRetry: _refresh);
              return buildBlock(context, {
                    'type': 'tile_scroller',
                    'settings': {
                      'shape': 'circle',
                      'rows': 2,
                      'items': [for (final c in cats) {'image': c.s('image'), 'label': c.s('name'), 'link': '/category/${c.str('slug')}'}],
                    },
                  }) ??
                  const SizedBox.shrink();
            },
          ),
          // 4. Ad banner (labelled).
          buildBlock(context, const {'type': 'ad_slot', 'settings': {'ad_slot_key': 'home'}}) ?? const SizedBox.shrink(),
          // 5. Recommended for you.
          _ProductSection(key: ValueKey('rec-$g'), title: context.t('home.recommended'), query: const {'sort': 'recommended', 'per_page': 12}),
          // 7. Mega deals (live flash sale).
          OnceFuture<List<Json>>(
            key: ValueKey('flash-$g'),
            load: () => api.getList('flash-sales').then((r) => r.data),
            builder: (context, snap) {
              final live = (snap.data ?? const <Json>[]).where((s) => s.s('state') == 'live' && s.l('deals').isNotEmpty).toList();
              if (live.isEmpty) return const SizedBox.shrink();
              return FlashSaleSection(sale: live.first, title: context.t('home.megaDeals'));
            },
          ),
          // 8. Bestsellers.
          _ProductSection(key: ValueKey('best-$g'), title: context.t('home.bestsellers'), query: const {'sort': 'rating', 'per_page': 12}, tag: context.t('product.bestSeller')),
          // 10. More reasons to shop: the other contexts.
          if (widget.contextKey == 'main') ...[
            SectionHeader(title: context.t('home.moreReasons')),
            const VerticalsBlock(),
          ],
          // 11. Nawy Live strip.
          OnceFuture<List<Json>>(
            key: ValueKey('live-$g'),
            load: () => api.getList('live-streams').then((r) => r.data),
            builder: (context, snap) {
              final streams = snap.data ?? const <Json>[];
              if (streams.isEmpty) return const SizedBox.shrink();
              return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SectionHeader(title: context.t('live.title'), onViewAll: () => Navigator.of(context).pushNamed(Routes.live)),
                LiveRail(streams: streams),
              ]);
            },
          ),
          // 12. Previously browsed.
          buildBlock(context, const {'type': 'item_carousel', 'settings': {}}) ?? const SizedBox.shrink(),
          // 13. Category showcases.
          OnceFuture<List<Json>>(
            key: ValueKey('showcase-$g'),
            load: () => api.getList('categories').then((r) => r.data),
            builder: (context, snap) => Column(children: [
              for (final c in (snap.data ?? const <Json>[]).take(4))
                _ProductSection(
                  title: c.str('name'),
                  query: {'category': c.str('slug'), 'per_page': 10},
                  viewAll: {'category': c.str('slug'), 'title': c.str('name')},
                ),
            ]),
          ),
        ],
      ),
    );
  }
}

class _ProductSection extends StatelessWidget {
  const _ProductSection({super.key, required this.title, required this.query, this.viewAll, this.tag});

  final String title;
  final Map<String, dynamic> query;
  final Map<String, dynamic>? viewAll;
  final String? tag;

  @override
  Widget build(BuildContext context) => OnceFuture<List<Json>>(
        load: () => AppScope.read(context).api.getList('products', query: query).then((r) => r.data),
        builder: (context, snap) {
          final cards = snap.data ?? const <Json>[];
          if (cards.isEmpty) return const SizedBox.shrink();
          return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SectionHeader(title: title, onViewAll: () => Navigator.of(context).pushNamed(Routes.products, arguments: viewAll ?? {...query, 'title': title})),
            ProductRail(cards: cards, tags: tag == null ? null : List.filled(cards.length, tag)),
          ]);
        },
      );
}

/// Section spacer used between blocks.
const sectionGap = SizedBox(height: NawySpace.md);
