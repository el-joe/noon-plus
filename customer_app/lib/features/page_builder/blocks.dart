import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../routing/navigation.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';
import 'settings.dart';
import 'vertical_cards.dart';

/// The 51 page-builder block types (backend `BlockRegistry`, website
/// `features/page-builder/render.tsx`) rendered as Flutter widgets for the
/// app's narrow layout. Unknown types are skipped (forward compatibility,
/// rule 4); sponsored units are always labelled "Ad" (rule 1).
typedef BlockBuilder = Widget? Function(BuildContext context, BlockSettings s, Json? data);

const Set<String> knownBlockTypes = {
  'hero_carousel', 'hero_bento', 'banner_slider', 'banner_scroller', //
  'promo_banner', 'banner_grid', 'banner_strip', 'banner_full', 'countdown_strip',
  'tile_scroller', 'category_tiles', 'category_showcase', 'brand_carousel', 'segment_tabs', 'quick_links',
  'product_carousel', 'product_grid', 'item_carousel', 'mega_deals', 'hero_offer_list', 'frenzy_deals', 'deal_grid', 'offer_cards', 'tile_grid',
  'panel_row', 'section_band',
  'sponsored_brand_banner', 'sponsored_product', 'sponsored_brand_video', 'sponsored_video', 'ad_slot',
  'product_listing',
  'verticals', 'creators_carousel', 'classified_carousel', 'travel_packages_carousel', 'properties_carousel', 'video_block', 'live_streams_strip',
  'html', 'seo_text', 'popular_searches', 'app_download', 'newsletter_signup', 'vendor_spotlight',
  'subcategory_tiles', 'subcategory_hub', 'subcategory_chips',
  'category_header', 'brand_header', 'campaign_hero',
};

/// Build one block, or null when the type is unknown or it has nothing to show.
Widget? buildBlock(BuildContext context, Json block, {int depth = 0}) {
  final type = block.str('type');
  if (!knownBlockTypes.contains(type)) return null;
  final s = BlockSettings(block.m('settings') ?? <String, dynamic>{}, context.l10n.locale);
  final data = block.m('data');
  try {
    return switch (type) {
      'hero_carousel' || 'banner_slider' => _Slider(items: s.items('slides'), aspect: type == 'hero_carousel' ? 750 / 420 : 375 / 140, title: type == 'banner_slider' ? s.title : null),
      'hero_bento' => _heroBento(s),
      'banner_scroller' => _BannerRow(s: s, items: s.items('items'), aspect: 16 / 9, fraction: 0.8),
      'promo_banner' => _promoBanner(s),
      'banner_grid' => _bannerGrid(s),
      'banner_strip' || 'banner_full' => _bannerFull(s, type),
      'countdown_strip' => _countdownStrip(s),
      'tile_scroller' || 'category_tiles' => _TileScroller(s: s, circle: (s.str('shape', localised: false) ?? (type == 'tile_scroller' ? 'circle' : 'rounded')) == 'circle'),
      'category_showcase' => _CategoryShowcase(s: s),
      'brand_carousel' => _BannerRow(s: s, items: s.items('items'), aspect: 4 / 3, fraction: 0.36, contain: true),
      'segment_tabs' => _chips(s.items('tabs'), title: null),
      'quick_links' => _chips(s.items('items'), title: s.title),
      'product_carousel' || 'product_grid' => _products(context, s, data, grid: type == 'product_grid'),
      'item_carousel' => _RecentlyViewed(s: s),
      'mega_deals' || 'frenzy_deals' || 'hero_offer_list' || 'deal_grid' => _FlashDeals(s: s, grid: type == 'deal_grid', fallbackItems: s.items('items')),
      'offer_cards' => _OfferCards(s: s),
      'tile_grid' => _tileGrid(s),
      'panel_row' || 'section_band' => _container(context, s, depth),
      'sponsored_brand_banner' || 'sponsored_brand_video' || 'sponsored_video' => _AdPlacements(placement: s.str('ad_slot_key', localised: false) ?? s.str('placement', localised: false) ?? type, tile: false),
      'sponsored_product' => _AdPlacements(placement: s.str('ad_slot_key', localised: false) ?? s.str('placement', localised: false) ?? type, tile: true),
      'ad_slot' => _AdPlacements(placement: s.str('ad_slot_key', localised: false) ?? 'home', tile: false),
      'product_listing' => _ProductListing(s: s),
      'verticals' => const VerticalsBlock(),
      'creators_carousel' => _Fetched(title: s.title ?? context.t('blocks.creators'), load: (api) => api.getList('creators').then((r) => r.data), builder: (items) => CreatorRail(items: items)),
      'classified_carousel' => _Fetched(
          title: s.title ?? context.t('souq.title'),
          viewAll: '/classified',
          load: (api) => api.getList('classifieds/ads', query: {'category': s.str('category', localised: false), 'per_page': s.number('max_items', 10)}).then((r) => r.data),
          builder: (items) => AdRail(ads: items)),
      'travel_packages_carousel' => _Fetched(
          title: s.title ?? context.t('travel.title'),
          viewAll: '/travel',
          load: (api) => api.getList('travel/packages', query: {'destination': s.str('destination', localised: false), 'trip_type': s.str('trip_type', localised: false)}).then((r) => r.data),
          builder: (items) => TravelRail(packages: items)),
      'properties_carousel' => _Fetched(
          title: s.title ?? context.t('property.title'),
          viewAll: '/property',
          load: (api) => api.getList('classifieds/ads', query: {'vertical': 'property', 'per_page': s.number('max_items', 10)}).then((r) => r.data),
          builder: (items) => AdRail(ads: items)),
      'video_block' => _videoBlock(context, s),
      'live_streams_strip' => _liveStrip(context, s, data),
      'html' || 'seo_text' => _textBlock(s, type),
      'popular_searches' => _popularSearches(context, s),
      'app_download' => null, // Already in the app.
      'newsletter_signup' => _Newsletter(s: s),
      'vendor_spotlight' => _chips(s.items('items'), title: s.title),
      'subcategory_tiles' || 'subcategory_hub' => _TileScroller(s: s, circle: false),
      'subcategory_chips' => _CategoryChips(s: s),
      'category_header' || 'brand_header' || 'campaign_hero' => _header(s),
      _ => null,
    };
  } catch (_) {
    return null; // A malformed block never breaks the page.
  }
}

/// A list of blocks (one zone) as slivers-friendly column children.
List<Widget> buildZone(BuildContext context, List<Json> blocks, {int depth = 0}) => [
      for (final block in blocks)
        if (buildBlock(context, block, depth: depth) case final Widget w) w,
    ];

// ---------------------------------------------------------------------------
// Shared pieces

class _ImageTile extends StatelessWidget {
  const _ImageTile({required this.item, this.aspect = 1, this.contain = false, this.radius = NawyRadius.card});

  final BlockItem item;
  final double aspect;
  final bool contain;
  final double radius;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: item.link == null ? null : () => openLink(context, item.link),
        borderRadius: BorderRadius.circular(radius),
        child: AspectRatio(
          aspectRatio: aspect,
          child: item.image != null
              ? NetImage(item.image, radius: radius, fit: contain ? BoxFit.contain : BoxFit.cover, background: NawyColors.bgSurface)
              : Container(
                  decoration: BoxDecoration(color: NawyColors.bgBrandSubtle, borderRadius: BorderRadius.circular(radius)),
                  alignment: Alignment.center,
                  padding: const EdgeInsets.all(12),
                  child: Text(item.label ?? '', textAlign: TextAlign.center, style: NawyText.h4.copyWith(color: NawyColors.textBrand)),
                ),
        ),
      );
}

Widget _titled(BlockSettings s, Widget child, {String? title, String? fallbackLink}) {
  final heading = title ?? s.title;
  final viewAll = s.viewAll ?? fallbackLink;
  return Builder(
    builder: (context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (heading != null) SectionHeader(title: heading, subtitle: s.subtitle, onViewAll: viewAll == null ? null : () => openLink(context, viewAll)),
      child,
    ]),
  );
}

/// Hero carousel with a peek of the next slide and a dot pager.
class _Slider extends StatefulWidget {
  const _Slider({required this.items, required this.aspect, this.title});

  final List<BlockItem> items;
  final double aspect;
  final String? title;

  @override
  State<_Slider> createState() => _SliderState();
}

class _SliderState extends State<_Slider> {
  final _controller = PageController(viewportFraction: 0.92);
  int _page = 0;
  Timer? _timer;

  List<BlockItem> get _items => widget.items.where((i) => !i.isEmpty).toList();

  @override
  void initState() {
    super.initState();
    if (_items.length > 1) {
      _timer = Timer.periodic(const Duration(seconds: 5), (_) {
        if (!_controller.hasClients) return;
        final next = (_page + 1) % _items.length;
        _controller.animateToPage(next, duration: const Duration(milliseconds: 350), curve: Curves.easeOut);
      });
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    if (items.isEmpty) return const SizedBox.shrink();
    final width = MediaQuery.of(context).size.width * 0.92;
    return Column(children: [
      if (widget.title != null) SectionHeader(title: widget.title!),
      Padding(
        padding: const EdgeInsets.only(top: 12),
        child: SizedBox(
          height: width / widget.aspect,
          child: PageView.builder(
            controller: _controller,
            itemCount: items.length,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (context, i) => Padding(padding: const EdgeInsets.symmetric(horizontal: 4), child: _ImageTile(item: items[i], aspect: widget.aspect, radius: NawyRadius.lg)),
          ),
        ),
      ),
      if (items.length > 1)
        Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            for (var i = 0; i < items.length; i++)
              AnimatedContainer(
                duration: const Duration(milliseconds: 200),
                margin: const EdgeInsets.symmetric(horizontal: 2),
                width: i == _page ? 14 : 6,
                height: 6,
                decoration: BoxDecoration(color: i == _page ? NawyColors.textPrimary : NawyColors.borderDefault, borderRadius: BorderRadius.circular(3)),
              ),
          ]),
        ),
    ]);
  }
}

Widget? _heroBento(BlockSettings s) {
  final slots = s.list('slots').map((slot) {
    final child = s.child(slot);
    final slides = child.items('slides');
    return slides.isNotEmpty ? slides : [child.asItem];
  }).toList();
  if (slots.isEmpty) return null;
  return Column(children: [
    _Slider(items: slots.first, aspect: 2),
    if (slots.length > 1)
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
        child: Row(children: [
          for (final slot in slots.skip(1).take(2)) ...[Expanded(child: _ImageTile(item: slot.first, aspect: 2)), const SizedBox(width: 8)],
        ]..removeLast()),
      ),
  ]);
}

class _BannerRow extends StatelessWidget {
  const _BannerRow({required this.s, required this.items, required this.aspect, required this.fraction, this.contain = false});

  final BlockSettings s;
  final List<BlockItem> items;
  final double aspect;
  final double fraction;
  final bool contain;

  @override
  Widget build(BuildContext context) {
    final list = items.where((i) => !i.isEmpty).toList();
    if (list.isEmpty) return const SizedBox.shrink();
    final w = MediaQuery.of(context).size.width * fraction;
    return _titled(
      s,
      SizedBox(
        height: w / aspect + (list.any((i) => i.caption != null || (contain && i.label != null)) ? 40 : 0),
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          itemCount: list.length,
          separatorBuilder: (_, _) => const SizedBox(width: 10),
          itemBuilder: (context, i) => SizedBox(
            width: w,
            child: Column(crossAxisAlignment: CrossAxisAlignment.center, children: [
              _ImageTile(item: list[i], aspect: aspect, contain: contain),
              if (contain && list[i].label != null && list[i].image != null) Text(list[i].label!, style: NawyText.label, maxLines: 1, overflow: TextOverflow.ellipsis),
              if (list[i].caption != null) Text(list[i].caption!, style: NawyText.caption.copyWith(color: NawyColors.textSuccess), maxLines: 1),
            ]),
          ),
        ),
      ),
    );
  }
}

Widget? _promoBanner(BlockSettings s) {
  final banners = s.items('items').where((i) => !i.isEmpty).toList();
  if (banners.isEmpty) return null;
  final layout = s.str('layout', localised: false) ?? s.str('variant', localised: false) ?? (banners.length == 1 ? 'single' : 'n_up');
  if (layout == 'single' || banners.length == 1) {
    return _titled(s, Padding(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6), child: _ImageTile(item: banners.first, aspect: 4)));
  }
  // 2-up promo tiles (Noon home section 9), wrapping in pairs.
  return _titled(
    s,
    Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: _pairs(banners, (b) => _ImageTile(item: b, aspect: layout == 'stacked' ? 4 : 1)),
    ),
  );
}

Widget _pairs(List<BlockItem> items, Widget Function(BlockItem) build) => Column(children: [
      for (var i = 0; i < items.length; i += 2)
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(child: build(items[i])),
            const SizedBox(width: 10),
            Expanded(child: i + 1 < items.length ? build(items[i + 1]) : const SizedBox.shrink()),
          ]),
        ),
    ]);

Widget? _bannerGrid(BlockSettings s) {
  final tiles = s.items('tiles').where((i) => !i.isEmpty).toList();
  if (tiles.isEmpty) return null;
  final variant = s.str('variant', localised: false) ?? s.str('layout', localised: false) ?? 'n_up';
  final small = variant == 'quick_links' || variant == 'brand_tiles';
  final columns = variant == 'wide' ? 1 : (variant == 'two_by_two' ? 2 : (small ? 4 : 3));
  final header = s.raw['header'] is Map ? s.child(asJson(s.raw['header'])).asItem.label : null;
  return _titled(
    s,
    title: header ?? s.title,
    Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: LayoutBuilder(builder: (context, c) {
        final w = (c.maxWidth - (columns - 1) * 10) / columns;
        return Wrap(spacing: 10, runSpacing: 10, children: [
          for (final t in tiles)
            SizedBox(
              width: w,
              child: Column(children: [
                _ImageTile(item: t, aspect: variant == 'wide' ? 5 : (small ? 1 : 4 / 5), contain: small),
                if (t.label != null && t.image != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(t.label!, style: NawyText.label, textAlign: TextAlign.center, maxLines: 2)),
                if (t.caption != null) Text(t.caption!, style: NawyText.caption.copyWith(color: NawyColors.textSuccess), textAlign: TextAlign.center),
              ]),
            ),
        ]);
      }),
    ),
  );
}

Widget? _bannerFull(BlockSettings s, String type) {
  final slot = s.str('ad_slot_key', localised: false);
  if (slot != null) return _AdPlacements(placement: slot, tile: false);
  final item = s.asItem;
  if (item.image == null) return null;
  return Padding(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8), child: _ImageTile(item: item, aspect: type == 'banner_strip' ? 6 : 4));
}

Widget? _countdownStrip(BlockSettings s) {
  final text = s.str('text');
  final ends = DateTime.tryParse(s.str('ends_at', localised: false) ?? '');
  if (text == null || ends == null || ends.isBefore(DateTime.now())) return null;
  return Builder(
    builder: (context) => InkWell(
      onTap: () => openLink(context, s.str('link', localised: false)),
      child: Container(
        margin: const EdgeInsets.fromLTRB(16, 8, 16, 0),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(color: NawyColors.bgInverse, borderRadius: BorderRadius.circular(NawyRadius.md)),
        child: Row(children: [
          Expanded(child: Text(text, style: NawyText.bodyMedium.copyWith(color: NawyColors.textInverse))),
          Text(context.t('blocks.endsIn'), style: NawyText.caption.copyWith(color: NawyColors.textMuted)),
          const SizedBox(width: 6),
          Countdown(endsAt: ends, style: NawyText.label.copyWith(color: NawyColors.bgBrand)),
        ]),
      ),
    ),
  );
}

/// Items from settings, or the root categories when `source` names them.
class _TileScroller extends StatelessWidget {
  const _TileScroller({required this.s, required this.circle});

  final BlockSettings s;
  final bool circle;

  @override
  Widget build(BuildContext context) {
    final manual = s.items('items').where((i) => !i.isEmpty).toList();
    if (manual.isNotEmpty) return _render(context, manual);
    final source = s.str('source', localised: false) ?? 'categories';
    if (!source.contains('categor')) return const SizedBox.shrink();
    return OnceFuture<List<Json>>(
        load: () => AppScope.read(context).api.getList('categories').then((r) => r.data),
      builder: (context, snap) {
        final cats = snap.data ?? const <Json>[];
        if (cats.isEmpty) return const SizedBox.shrink();
        return _render(context, [for (final c in cats) BlockItem(image: c.s('image'), label: c.s('name'), link: '/category/${c.str('slug')}')]);
      },
    );
  }

  Widget _render(BuildContext context, List<BlockItem> tiles) {
    // Two rows only when there are enough tiles to fill them (Noon: 2-row circles).
    final rows = s.number('rows', circle ? 2 : 1) >= 2 && tiles.length > 5 ? 2 : 1;
    const size = 72.0;
    final columns = <List<BlockItem>>[for (var i = 0; i < tiles.length; i += rows) tiles.sublist(i, (i + rows).clamp(0, tiles.length))];
    return _titled(
      s,
      SizedBox(
        height: rows * (size + 44) + 16,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          itemCount: columns.length,
          separatorBuilder: (_, _) => const SizedBox(width: 12),
          itemBuilder: (context, i) => Column(children: [
            for (final t in columns[i])
              SizedBox(
                width: size,
                height: size + 44,
                child: InkWell(
                  onTap: () => openLink(context, t.link),
                  child: Column(children: [
                    Container(
                      width: size,
                      height: size,
                      decoration: BoxDecoration(shape: circle ? BoxShape.circle : BoxShape.rectangle, borderRadius: circle ? null : BorderRadius.circular(NawyRadius.card), color: NawyColors.bgSurface),
                      clipBehavior: Clip.antiAlias,
                      child: t.image != null
                          ? NetImage(t.image)
                          : Center(child: Text((t.label ?? '?').characters.first, style: NawyText.h3.copyWith(color: NawyColors.textBrand))),
                    ),
                    const SizedBox(height: 4),
                    Text(t.label ?? '', maxLines: 2, textAlign: TextAlign.center, overflow: TextOverflow.ellipsis, style: NawyText.caption),
                  ]),
                ),
              ),
          ]),
        ),
      ),
    );
  }
}

class _CategoryShowcase extends StatelessWidget {
  const _CategoryShowcase({required this.s});

  final BlockSettings s;

  @override
  Widget build(BuildContext context) {
    final tiles = s.items('tiles').where((i) => !i.isEmpty).take(7).toList();
    if (tiles.isEmpty) return _TileScroller(s: s, circle: false);
    return _titled(
      s,
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: LayoutBuilder(builder: (context, c) {
          final w = (c.maxWidth - 20) / 3;
          return Wrap(spacing: 10, runSpacing: 10, children: [
            for (final t in tiles)
              SizedBox(width: w, child: Column(children: [_ImageTile(item: t, aspect: 1), const SizedBox(height: 4), Text(t.label ?? '', style: NawyText.caption, maxLines: 2, textAlign: TextAlign.center)])),
          ]);
        }),
      ),
    );
  }
}

Widget? _chips(List<BlockItem> items, {String? title}) {
  if (items.isEmpty) return null;
  return Builder(
    builder: (context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (title != null) SectionHeader(title: title),
      SizedBox(
        height: 48,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
          itemCount: items.length,
          separatorBuilder: (_, _) => const SizedBox(width: 8),
          itemBuilder: (context, i) => ActionChip(
            avatar: items[i].image == null ? null : NetImage(items[i].image, width: 20, height: 20, fit: BoxFit.contain),
            label: Text(items[i].label ?? ''),
            onPressed: () => openLink(context, items[i].link),
          ),
        ),
      ),
    ]),
  );
}

Widget? _products(BuildContext context, BlockSettings s, Json? data, {required bool grid}) {
  final cards = data?.l('products') ?? const <Json>[];
  if (cards.isEmpty) return null;
  if (grid) return _titled(s, ProductGrid(cards: cards));
  return _titled(s, ProductRail(cards: cards));
}

/// item_carousel — recently viewed on this device.
class _RecentlyViewed extends StatelessWidget {
  const _RecentlyViewed({required this.s});

  final BlockSettings s;

  @override
  Widget build(BuildContext context) => OnceFuture<List<Json>>(
        load: () => AppScope.read(context).readList('recently_viewed'),
        builder: (context, snap) {
          final cards = snap.data ?? const <Json>[];
          if (cards.isEmpty) return const SizedBox.shrink();
          return _titled(s, ProductRail(cards: cards), title: s.title ?? context.t('blocks.recentlyViewed'));
        },
      );
}

/// Flash-sale backed blocks: mega_deals (countdown + rail), deal_grid (2×2),
/// frenzy_deals, hero_offer_list.
class _FlashDeals extends StatelessWidget {
  const _FlashDeals({required this.s, required this.grid, required this.fallbackItems});

  final BlockSettings s;
  final bool grid;
  final List<BlockItem> fallbackItems;

  @override
  Widget build(BuildContext context) {
    final id = s.number('flash_sale_id', 0).toInt();
    if (id <= 0) {
      if (fallbackItems.isEmpty) return const SizedBox.shrink();
      return _BannerRow(s: s, items: fallbackItems, aspect: 1, fraction: 0.4);
    }
    return OnceFuture<Json>(
        load: () => AppScope.read(context).api.getObject('flash-sales/$id'),
      builder: (context, snap) {
        final sale = snap.data;
        if (sale == null || sale.l('deals').isEmpty) return const SizedBox.shrink();
        return FlashSaleSection(sale: sale, title: s.title, grid: grid, background: hexColor(s.background));
      },
    );
  }
}

/// A flash sale's deals as product cards (Figma "Mega deals").
class FlashSaleSection extends StatelessWidget {
  const FlashSaleSection({super.key, required this.sale, this.title, this.grid = false, this.background});

  final Json sale;
  final String? title;
  final bool grid;
  final Color? background;

  static Json dealCard(Json deal) => {...?deal.m('product'), 'listing_id': deal.i('listing_id'), 'price': deal['price']};

  @override
  Widget build(BuildContext context) {
    final deals = sale.l('deals');
    final ends = DateTime.tryParse(sale.str('ends_at'));
    final cards = deals.map(dealCard).toList();
    final was = deals.map((d) => d.m('was_price')).toList();
    final tags = deals.map((d) => d.b('sold_out') ? context.t('deals.soldOut') : context.t('deals.deal')).toList();
    return Container(
      color: background ?? NawyColors.bgDangerSubtle,
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SectionHeader(
          title: title ?? sale.str('name'),
          trailing: ends == null
              ? null
              : Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  margin: const EdgeInsetsDirectional.only(end: 8),
                  decoration: BoxDecoration(color: NawyColors.bgDanger, borderRadius: BorderRadius.circular(NawyRadius.sm)),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Text('${context.t('blocks.endsIn')} ', style: NawyText.caption.copyWith(color: NawyColors.textInverse)),
                    Countdown(endsAt: ends, style: NawyText.caption.copyWith(color: NawyColors.textInverse)),
                  ]),
                ),
          onViewAll: () => Navigator.of(context).pushNamed(Routes.flashSale, arguments: {'id': sale.s('slug') ?? sale.str('id')}),
        ),
        if (grid)
          ProductGrid(cards: cards.take(4).toList())
        else
          ProductRail(cards: cards, wasPrices: was, tags: tags),
      ]),
    );
  }
}

/// offer_cards — coupon cards with a copyable code ("Offers for you").
class _OfferCards extends StatelessWidget {
  const _OfferCards({required this.s});

  final BlockSettings s;

  @override
  Widget build(BuildContext context) {
    final items = s.items('items');
    if (items.isEmpty) return const SizedBox.shrink();
    return _titled(
      s,
      SizedBox(
        height: 84,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          itemCount: items.length,
          separatorBuilder: (_, _) => const SizedBox(width: 10),
          itemBuilder: (context, i) => CouponChip(title: items[i].label ?? '', code: items[i].code, onTap: () => openLink(context, items[i].link)),
        ),
      ),
    );
  }
}

/// Figma component "Coupon chip" (94:12100).
class CouponChip extends StatelessWidget {
  const CouponChip({super.key, required this.title, this.code, this.onTap, this.width = 200});

  final String title;
  final String? code;
  final VoidCallback? onTap;
  final double width;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        child: Container(
          width: width,
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: NawyColors.bgSuccessSubtle,
            borderRadius: BorderRadius.circular(NawyRadius.md),
            border: Border.all(color: NawyColors.bgSuccess),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [
            Text(title, style: NawyText.label, maxLines: 2, overflow: TextOverflow.ellipsis),
            if (code != null) ...[
              const SizedBox(height: 4),
              Text(context.t('blocks.useCode', {'code': code}), style: NawyText.caption.copyWith(color: NawyColors.textSuccess)),
            ],
          ]),
        ),
      );
}

Widget? _tileGrid(BlockSettings s) {
  final tiles = s.items('items').take(4).toList();
  if (tiles.isEmpty) return null;
  return _titled(
    s,
    Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: _pairs(
        tiles,
        (t) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          _ImageTile(item: t, aspect: 1, contain: true),
          if (t.label != null) Text(t.label!, style: NawyText.bodyMedium),
          if (t.caption != null) Text(t.caption!, style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
        ]),
      ),
    ),
  );
}

Widget? _container(BuildContext context, BlockSettings s, int depth) {
  if (depth > 2) return null;
  final children = <Json>[
    ...s.list('children'),
    for (final column in s.list('columns')) ...column.l('blocks'),
    for (final column in s.list('columns')) ...column.l('children'),
  ];
  final built = buildZone(context, children, depth: depth + 1);
  if (built.isEmpty) return null;
  final bg = hexColor(s.background);
  return Container(
    color: bg,
    padding: bg == null ? null : const EdgeInsets.only(bottom: 12),
    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [if (s.title != null) SectionHeader(title: s.title!), ...built]),
  );
}

/// Sponsored creatives from active bookings (`GET ads/placements/{placement}`),
/// always labelled.
class _AdPlacements extends StatelessWidget {
  const _AdPlacements({required this.placement, required this.tile});

  final String placement;
  final bool tile;

  @override
  Widget build(BuildContext context) => OnceFuture<List<Json>>(
        load: () => AppScope.read(context).api.getList('ads/placements/${enc(placement)}').then((r) => r.data),
        builder: (context, snap) {
          final ads = (snap.data ?? const <Json>[]).where((a) => a.s('image') != null || a.s('headline') != null).toList();
          if (ads.isEmpty) return const SizedBox.shrink();
          return Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
            child: Column(children: [
              for (final ad in ads)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: InkWell(
                    onTap: () => openLink(context, ad.s('link_url')),
                    child: Stack(children: [
                      if (safeImageUrl(ad.s('image')) != null)
                        AspectRatio(aspectRatio: tile ? 2 : 5, child: NetImage(ad.s('image'), radius: NawyRadius.card))
                      else
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(color: NawyColors.bgSurface, borderRadius: BorderRadius.circular(NawyRadius.card), border: Border.all(color: NawyColors.borderDefault)),
                          child: Text(ad.str('headline'), style: NawyText.h4),
                        ),
                      PositionedDirectional(top: 6, end: 6, child: AdLabel(text: ad.s('label'))),
                    ]),
                  ),
                ),
            ]),
          );
        },
      );
}

/// product_listing — a product grid for the block's scope (category/search).
class _ProductListing extends StatelessWidget {
  const _ProductListing({required this.s});

  final BlockSettings s;

  @override
  Widget build(BuildContext context) => OnceFuture<List<Json>>(
        load: () => AppScope.read(context).api.getList('products', query: {
          'category': s.str('category', localised: false),
          'q': s.str('q', localised: false),
          'per_page': s.number('per_page', 12),
        }).then((r) => r.data),
        builder: (context, snap) {
          final cards = snap.data ?? const <Json>[];
          if (snap.connectionState != ConnectionState.done) return const LoadingView();
          if (cards.isEmpty) return const SizedBox.shrink();
          return _titled(s, ProductGrid(cards: cards));
        },
      );
}

/// Generic fetch-then-render block that hides itself on error/empty.
class _Fetched extends StatelessWidget {
  const _Fetched({required this.title, required this.load, required this.builder, this.viewAll});

  final String title;
  final String? viewAll;
  final Future<List<Json>> Function(ApiClient api) load;
  final Widget Function(List<Json> items) builder;

  @override
  Widget build(BuildContext context) => OnceFuture<List<Json>>(
        load: () => load(AppScope.read(context).api),
        builder: (context, snap) {
          final items = snap.data ?? const <Json>[];
          if (items.isEmpty) return const SizedBox.shrink();
          return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SectionHeader(title: title, onViewAll: viewAll == null ? null : () => openLink(context, viewAll)),
            builder(items),
          ]);
        },
      );
}

Widget? _videoBlock(BuildContext context, BlockSettings s) {
  final slug = s.str('stream_slug', localised: false);
  final url = s.str('video_url', localised: false);
  if (slug == null && url == null) return null;
  return Padding(
    padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
    child: InkWell(
      onTap: () => slug != null ? Navigator.of(context).pushNamed(Routes.liveWatch, arguments: {'slug': slug}) : openLink(context, url),
      child: AspectRatio(
        aspectRatio: 16 / 9,
        child: Container(
          decoration: BoxDecoration(color: NawyColors.bgInverse, borderRadius: BorderRadius.circular(NawyRadius.card)),
          child: Stack(alignment: Alignment.center, children: [
            if (safeImageUrl(s.str('poster', localised: false)) != null) Positioned.fill(child: NetImage(s.str('poster', localised: false), radius: NawyRadius.card)),
            const Icon(Icons.play_circle_fill_rounded, size: 56, color: NawyColors.textInverse),
            if (s.title != null) PositionedDirectional(start: 12, bottom: 10, child: Text(s.title!, style: NawyText.h4.copyWith(color: NawyColors.textInverse))),
          ]),
        ),
      ),
    ),
  );
}

/// Nawy Live strip (`data.streams` resolved server-side).
Widget? _liveStrip(BuildContext context, BlockSettings s, Json? data) {
  final streams = data?.l('streams') ?? const <Json>[];
  if (streams.isEmpty) return null;
  return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
    SectionHeader(title: s.title ?? context.t('live.title'), onViewAll: () => Navigator.of(context).pushNamed(Routes.live)),
    LiveRail(streams: streams),
  ]);
}

String stripHtml(String html) => html
    .replaceAll(RegExp(r'<(script|style)[^>]*>.*?</\1>', dotAll: true, caseSensitive: false), '')
    .replaceAll(RegExp(r'<br\s*/?>|</p>|</h\d>|</li>', caseSensitive: false), '\n')
    .replaceAll(RegExp(r'<[^>]+>'), '')
    .replaceAll('&nbsp;', ' ')
    .replaceAll('&amp;', '&')
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&quot;', '"')
    .replaceAll(RegExp(r'\n{3,}'), '\n\n')
    .trim();

Widget? _textBlock(BlockSettings s, String type) {
  final raw = type == 'html' ? s.str('html') : s.str('text');
  if (raw == null) return null;
  final text = stripHtml(raw);
  if (text.isEmpty) return null;
  return Padding(
    padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (s.title != null) Text(s.title!, style: NawyText.h4),
      Text(text, style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
    ]),
  );
}

Widget? _popularSearches(BuildContext context, BlockSettings s) {
  final keywords = s.strings('keywords');
  final words = keywords.isNotEmpty ? keywords : s.strings('keywords_${s.locale}');
  if (words.isEmpty) return null;
  return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
    SectionHeader(title: s.title ?? context.t('search.popular')),
    Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Wrap(spacing: 8, runSpacing: 8, children: [
        for (final w in words)
          ActionChip(
            avatar: const Icon(Icons.trending_up_rounded, size: 16),
            label: Text(w),
            onPressed: () => Navigator.of(context).pushNamed(Routes.products, arguments: {'q': w}),
          ),
      ]),
    ),
  ]);
}

class _Newsletter extends StatefulWidget {
  const _Newsletter({required this.s});

  final BlockSettings s;

  @override
  State<_Newsletter> createState() => _NewsletterState();
}

class _NewsletterState extends State<_Newsletter> {
  final _email = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SectionCard(
        margin: const EdgeInsets.fromLTRB(16, 16, 16, 0),
        title: widget.s.title ?? context.t('blocks.newsletter'),
        child: Row(children: [
          Expanded(child: TextField(controller: _email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(hintText: context.t('auth.email')))),
          const SizedBox(width: 8),
          BusyButton(
            expand: false,
            label: context.t('blocks.subscribe'),
            onPressed: () async {
              final email = _email.text.trim();
              if (!email.contains('@')) return;
              await guarded(context, () => AppScope.read(context).api.post('newsletter/subscribe', body: {'email': email, 'source': 'app'}), success: context.t('blocks.subscribed'));
            },
          ),
        ]),
      );
}

class _CategoryChips extends StatelessWidget {
  const _CategoryChips({required this.s});

  final BlockSettings s;

  @override
  Widget build(BuildContext context) => OnceFuture<List<Json>>(
        load: () => AppScope.read(context).api.getList('categories').then((r) => r.data),
        builder: (context, snap) {
          final cats = snap.data ?? const <Json>[];
          return _chips([for (final c in cats) BlockItem(label: c.s('name'), link: '/category/${c.str('slug')}')]) ?? const SizedBox.shrink();
        },
      );
}

Widget? _header(BlockSettings s) {
  final item = s.asItem;
  final title = s.title ?? item.label;
  if (title == null && item.image == null) return null;
  final ends = DateTime.tryParse(s.str('ends_at', localised: false) ?? '');
  return Container(
    margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
    decoration: BoxDecoration(color: hexColor(s.background) ?? NawyColors.bgBrandSubtle, borderRadius: BorderRadius.circular(NawyRadius.lg)),
    clipBehavior: Clip.antiAlias,
    child: Stack(children: [
      if (item.image != null) Positioned.fill(child: NetImage(item.image)),
      Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (title != null) Text(title, style: NawyText.h2),
          if (s.subtitle != null) Text(s.subtitle!, style: NawyText.body),
          if (ends != null && ends.isAfter(DateTime.now())) Countdown(endsAt: ends, style: NawyText.h4.copyWith(color: NawyColors.textDanger)),
        ]),
      ),
    ]),
  );
}
