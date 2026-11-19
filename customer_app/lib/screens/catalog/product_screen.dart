import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../features/page_builder/blocks.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../state/recently_viewed.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';
import 'pdp_extras.dart';
import 'reviews_screens.dart';

/// Pick the offer for the chosen variant options (cheapest in-stock match),
/// falling back to the buy box. Pure, unit tested.
Json? offerFor(Json product, Map<String, String> selected) {
  final offers = [if (product.m('buy_box') != null) product.m('buy_box')!, ...product.l('other_offers')];
  if (selected.isEmpty) return product.m('buy_box') ?? (offers.isEmpty ? null : offers.first);
  final matches = offers.where((o) {
    final options = o.m('options') ?? const {};
    return selected.entries.every((e) => options[e.key]?.toString() == e.value);
  }).toList();
  if (matches.isEmpty) return null;
  matches.sort((a, b) {
    final stockA = (a.i('in_stock') ?? 0) > 0 ? 0 : 1;
    final stockB = (b.i('in_stock') ?? 0) > 0 ? 0 : 1;
    if (stockA != stockB) return stockA - stockB;
    final pa = Money.fromJson(a['price']);
    final pb = Money.fromJson(b['price']);
    if (pa == null || pb == null || pa.currency != pb.currency) return 0;
    return pa.compareTo(pb);
  });
  return matches.first;
}

/// Variant dimensions across offers: {colour: [Black, White]}.
Map<String, List<String>> variantOptions(Json product) {
  final out = <String, List<String>>{};
  final offers = [if (product.m('buy_box') != null) product.m('buy_box')!, ...product.l('other_offers')];
  for (final o in offers) {
    (o.m('options') ?? const {}).forEach((key, value) {
      if (value == null) return;
      final list = out.putIfAbsent(key, () => <String>[]);
      if (!list.contains(value.toString())) list.add(value.toString());
    });
  }
  out.removeWhere((_, v) => v.length < 2);
  return out;
}

/// Figma "14 Product", "15 Variant sheet", "16 Added to cart": gallery,
/// variants, multi-seller buy box + other offers, delivery ETA with cut-off,
/// protection plans, size guide, overview/specs, seller card, reviews, Q&A,
/// wishlist, sticky qty + Add to cart.
class ProductScreen extends StatefulWidget {
  const ProductScreen({super.key, required this.slug});

  final String slug;

  @override
  State<ProductScreen> createState() => _ProductScreenState();
}

class _ProductScreenState extends State<ProductScreen> {
  final Map<String, String> _selected = {};
  int _qty = 1;
  int? _warranty;
  bool _wished = false;
  int _tab = 0;

  Future<Json> _load() async {
    final app = AppScope.read(context);
    final product = await app.api.getObject('products/${enc(widget.slug)}');
    final offer = product.m('buy_box');
    final options = offer?.m('options');
    if (options != null) {
      final dims = variantOptions(product);
      options.forEach((k, v) {
        if (dims.containsKey(k) && v != null) _selected[k] = v.toString();
      });
    }
    // Recently viewed (this device, and the account when signed in, QC-043),
    // used by "Previously browsed".
    await recordRecentlyViewed(app, {
      'slug': product.str('slug'),
      'title': product.str('title'),
      'brand': product.s('brand'),
      'price': offer?['price'] ?? product['from_price'],
      'image': product.l('images').isNotEmpty ? product.l('images').first : null,
      'rating': product['rating'],
      'listing_id': offer?.i('listing_id'),
    });
    if (app.signedIn) {
      try {
        final wishlist = await app.api.getList('wishlist', query: {'per_page': 100});
        _wished = wishlist.data.any((w) => w.s('slug') == product.s('slug'));
      } catch (_) {}
    }
    return product;
  }

  Future<void> _toggleWishlist(Json product) async {
    if (!await requireSignIn(context) || !mounted) return;
    final api = AppScope.read(context).api;
    final slug = product.str('slug');
    final next = !_wished;
    setState(() => _wished = next);
    final ok = await guarded(
      context,
      () => next ? api.post('wishlist', body: {'product': slug}) : api.delete('wishlist/${enc(slug)}'),
      success: context.t(next ? 'wishlist.added' : 'wishlist.removed'),
    );
    if (ok == null && mounted) setState(() => _wished = !next);
  }

  Future<void> _addToCart(Json product, Json offer) async {
    final app = AppScope.read(context);
    final listing = offer.i('listing_id');
    if (listing == null) return;
    final cart = await guarded(context, () => app.cart.add(listing, quantity: _qty));
    if (cart == null || !mounted) return;
    if (_warranty != null) await guarded(context, () => app.cart.setWarranty(listing, _warranty));
    if (!mounted) return;
    if (product.b('made_to_order') && product.l('measurement_fields').isNotEmpty) {
      await _askMeasurements(product, listing);
      if (!mounted) return;
    }
    await showNawySheet<void>(
      context,
      title: context.t('cart.addedTitle'),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            SizedBox(width: 56, height: 56, child: NetImage(product.l('images').isNotEmpty ? product.l('images').first.s('url') : null, radius: NawyRadius.md)),
            const SizedBox(width: 12),
            Expanded(child: Text(product.str('title'), maxLines: 2, style: NawyText.bodyMedium)),
            const Icon(Icons.check_circle_rounded, color: NawyColors.bgSuccess),
          ]),
          const SizedBox(height: 12),
          InfoRow(ctx.t('cart.total'), formatMoney(app.cart.cart?.m('totals')?['total'], locale: ctx.l10n.locale), bold: true),
          const SizedBox(height: 12),
          FilledButton(
            key: const ValueKey('added-view-cart'),
            onPressed: () {
              Navigator.of(ctx).pop();
              Navigator.of(context).pushNamed(Routes.cart);
            },
            child: Text(ctx.t('cart.viewCart', {'count': app.cart.count})),
          ),
          const SizedBox(height: 8),
          OutlinedButton(onPressed: () => Navigator.of(ctx).pop(), child: Text(ctx.t('cart.continueShopping'))),
        ]),
      ),
    );
  }

  Future<void> _askMeasurements(Json product, int listing) async {
    final fields = product.l('measurement_fields');
    final controllers = {for (final f in fields) f.str('key'): TextEditingController()};
    await showNawySheet<void>(
      context,
      title: context.t('product.measurements'),
      builder: (ctx) => ListView(padding: const EdgeInsets.all(16), shrinkWrap: true, children: [
        for (final f in fields)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: TextField(
              controller: controllers[f.str('key')],
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(labelText: '${f.s('label') ?? f.str('key')}${f.s('unit') != null ? ' (${f.s('unit')})' : ''}${f.b('required') ? ' *' : ''}'),
            ),
          ),
        BusyButton(
          label: ctx.t('common.save'),
          onPressed: () async {
            final values = {for (final e in controllers.entries) if (e.value.text.trim().isNotEmpty) e.key: e.value.text.trim()};
            final ok = await guarded(ctx, () => AppScope.read(ctx).api.put('cart/items/$listing/measurements', body: {'measurements': values}));
            if (ok != null && ctx.mounted) Navigator.of(ctx).pop();
          },
        ),
      ]),
    );
    for (final c in controllers.values) {
      c.dispose();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: AsyncBody<Json>(
          load: _load,
          builder: (context, product, reload) => _body(context, product),
        ),
      );

  Widget _body(BuildContext context, Json product) {
    final offer = offerFor(product, _selected);
    final dims = variantOptions(product);
    final price = Money.fromJson(offer?['price'] ?? product['from_price']);
    final was = Money.fromJson(offer?['was_price']);
    final inStock = (offer?.i('in_stock') ?? 0) > 0;
    final rating = product.m('rating');
    final flash = offer?.m('flash_sale');
    final delivery = offer?.m('delivery');
    final otherOffers = product.l('other_offers');

    return Column(children: [
      Expanded(
        child: CustomScrollView(slivers: [
          SliverAppBar(
            pinned: true,
            expandedHeight: MediaQuery.of(context).size.width,
            backgroundColor: NawyColors.bgSurface,
            actions: [
              IconButton(
                key: const ValueKey('pdp-wishlist'),
                tooltip: context.t('wishlist.title'),
                onPressed: () => _toggleWishlist(product),
                icon: Icon(_wished ? Icons.favorite_rounded : Icons.favorite_border_rounded, color: _wished ? NawyColors.bgDanger : null),
              ),
              CompareToggle(slug: product.str('slug')),
              IconButton(tooltip: context.t('tabs.cart'), onPressed: () => Navigator.of(context).pushNamed(Routes.cart), icon: const Icon(Icons.shopping_cart_outlined)),
            ],
            flexibleSpace: FlexibleSpaceBar(background: _Gallery(images: product.l('images'), selected: _selected)),
          ),
          SliverToBoxAdapter(
            child: Container(
              color: NawyColors.bgSurface,
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                if (product.s('brand') != null)
                  InkWell(
                    onTap: product.s('brand_slug') == null
                        ? null
                        : () => Navigator.of(context).pushNamed(Routes.products, arguments: {'brand': product.s('brand_slug'), 'title': product.s('brand')}),
                    child: Text('${product.s('brand')} · ${context.t('product.visitStore')}', style: NawyText.label.copyWith(color: NawyColors.textLink)),
                  ),
                const SizedBox(height: 4),
                Text(product.str('title'), style: NawyText.h4),
                PdpBadges(product: product),
                const SizedBox(height: 6),
                InkWell(
                  onTap: () => Navigator.of(context).pushNamed(Routes.reviews, arguments: {'slug': product.str('slug'), 'title': product.str('title')}),
                  child: Row(children: [
                    RatingPill(average: rating?.s('average'), count: null),
                    const SizedBox(width: 6),
                    Text(context.t('product.ratingsCount', {'count': rating?.i('count') ?? 0}), style: NawyText.caption.copyWith(color: NawyColors.textLink)),
                  ]),
                ),
                const SizedBox(height: 10),
                PriceLine(price: price, was: was, large: true),
                Text(context.t('product.inclVat'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                if (price != null && !price.isZero) BnplLine(key: ValueKey('bnpl-${price.amount}'), price: price),
                ProductCoupons(slug: product.str('slug')),
                if (flash != null && DateTime.tryParse(flash.str('ends_at')) != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Row(children: [
                      Pill(context.t('deals.deal'), background: NawyColors.bgDanger, foreground: NawyColors.textInverse),
                      const SizedBox(width: 8),
                      Text('${context.t('blocks.endsIn')} ', style: NawyText.caption),
                      Countdown(endsAt: DateTime.parse(flash.str('ends_at')), style: NawyText.label.copyWith(color: NawyColors.textDanger)),
                    ]),
                  ),
              ]),
            ),
          ),
          if (dims.isNotEmpty)
            SliverToBoxAdapter(
              child: SectionCard(
                margin: const EdgeInsets.fromLTRB(0, 8, 0, 0),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  for (final dim in dims.entries) ...[
                    Text('${context.l10n.status(dim.key)}: ${_selected[dim.key] ?? ''}', style: NawyText.bodyMedium),
                    const SizedBox(height: 8),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      for (final v in dim.value)
                        ChoiceChip(
                          key: ValueKey('variant-${dim.key}-$v'),
                          label: Text(v),
                          selected: _selected[dim.key] == v,
                          selectedColor: NawyColors.bgBrandSubtle,
                          onSelected: (_) => setState(() => _selected[dim.key] = v),
                        ),
                    ]),
                    const SizedBox(height: 10),
                  ],
                  if (offer == null) Text(context.t('product.variantUnavailable'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
                ]),
              ),
            ),
          if (delivery != null)
            SliverToBoxAdapter(
              child: SectionCard(
                margin: const EdgeInsets.fromLTRB(0, 8, 0, 0),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Icon(Icons.local_shipping_outlined),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(context.t('product.getItBy', {'date': shortDate(context, delivery.s('by'))}), style: NawyText.bodyMedium),
                      if (DateTime.tryParse(delivery.s('order_before') ?? '') case final DateTime cutoff when cutoff.isAfter(DateTime.now()))
                        Row(children: [
                          Text('${context.t('product.orderIn')} ', style: NawyText.caption.copyWith(color: NawyColors.textSuccess)),
                          Countdown(endsAt: cutoff, compact: true, style: NawyText.caption.copyWith(color: NawyColors.textSuccess)),
                        ]),
                      Text(inStock ? context.t('product.inStock', {'n': offer?.i('in_stock') ?? 0}) : context.t('product.outOfStock'),
                          style: NawyText.caption.copyWith(color: inStock ? NawyColors.textSecondary : NawyColors.textDanger)),
                    ]),
                  ),
                ]),
              ),
            ),
          if (product.l('warranty_plans').isNotEmpty)
            SliverToBoxAdapter(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SectionHeader(title: context.t('product.protection')),
                SizedBox(
                  height: 110,
                  child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
                    for (final plan in product.l('warranty_plans'))
                      Padding(
                        padding: const EdgeInsetsDirectional.only(end: 10),
                        child: _PlanCard(
                          plan: plan,
                          selected: _warranty == plan.i('plan_id'),
                          onTap: () => setState(() => _warranty = _warranty == plan.i('plan_id') ? null : plan.i('plan_id')),
                        ),
                      ),
                  ]),
                ),
              ]),
            ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: Wrap(spacing: 8, runSpacing: 8, children: [
                Pill(context.t('product.trustDelivery'), icon: Icons.local_shipping_outlined, background: NawyColors.bgSurface, foreground: NawyColors.textPrimary),
                if ((double.tryParse(offer?.m('seller')?.s('rating') ?? '') ?? 0) >= 4)
                  Pill(context.t('product.trustSeller'), icon: Icons.verified_outlined, background: NawyColors.bgSurface, foreground: NawyColors.textPrimary),
                Pill(context.t('product.trustReturns'), icon: Icons.replay_rounded, background: NawyColors.bgSurface, foreground: NawyColors.textPrimary),
                Pill(context.t('product.trustCod'), icon: Icons.payments_outlined, background: NawyColors.bgSurface, foreground: NawyColors.textPrimary),
              ]),
            ),
          ),
          if (product.b('has_size_guide'))
            SliverToBoxAdapter(
              child: ListTile(
                leading: const Icon(Icons.straighten_rounded),
                title: Text(context.t('product.sizeGuide')),
                trailing: const Icon(Icons.chevron_right_rounded),
                onTap: () => showNawySheet<void>(context, title: context.t('product.sizeGuide'), builder: (_) => SizeGuideView(slug: product.str('slug'))),
              ),
            ),
          SliverToBoxAdapter(child: _overview(context, product)),
          if (offer != null) SliverToBoxAdapter(child: _sellerCard(context, offer, otherOffers.length, product)),
          SliverToBoxAdapter(child: FrequentlyBoughtTogether(slug: product.str('slug'))),
          SliverToBoxAdapter(child: ReviewsPreview(slug: product.str('slug'), title: product.str('title'))),
          SliverToBoxAdapter(
            child: ListTile(
              leading: const Icon(Icons.forum_outlined),
              title: Text(context.t('qa.title')),
              trailing: const Icon(Icons.chevron_right_rounded),
              onTap: () => Navigator.of(context).pushNamed(Routes.questions, arguments: {'slug': product.str('slug')}),
            ),
          ),
          if (product.m('category') != null)
            SliverToBoxAdapter(
              child: OnceFuture<List<Json>>(
                load: () => AppScope.read(context).api.getList('products', query: {'category': product.m('category')!.str('slug'), 'per_page': 10}).then((r) => r.data),
                builder: (context, snap) {
                  final cards = (snap.data ?? const <Json>[]).where((c) => c.s('slug') != product.s('slug')).toList();
                  if (cards.isEmpty) return const SizedBox.shrink();
                  return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [SectionHeader(title: context.t('product.alsoViewed')), ProductRail(cards: cards)]);
                },
              ),
            ),
          const SliverToBoxAdapter(child: SizedBox(height: 24)),
        ]),
      ),
      // Sticky qty + Add to cart.
      Container(
        decoration: const BoxDecoration(color: NawyColors.bgSurface, border: Border(top: BorderSide(color: NawyColors.borderDefault))),
        padding: EdgeInsets.fromLTRB(16, 10, 16, 10 + MediaQuery.of(context).padding.bottom),
        child: Row(children: [
          DropdownButton<int>(
            key: const ValueKey('pdp-qty'),
            value: _qty,
            underline: const SizedBox.shrink(),
            items: [
              for (var i = 1; i <= ((offer?.i('in_stock') ?? 1).clamp(1, 10)); i++) DropdownMenuItem(value: i, child: Text('${context.t('cart.qty')} $i')),
            ],
            onChanged: (v) => setState(() => _qty = v ?? 1),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: BusyButton(
              key: const ValueKey('pdp-add'),
              label: inStock ? context.t('cart.add') : context.t('product.outOfStock'),
              onPressed: offer != null && inStock ? () => _addToCart(product, offer) : null,
            ),
          ),
        ]),
      ),
    ]);
  }

  Widget _overview(BuildContext context, Json product) {
    final highlights = product.strings('highlights');
    final specs = product['specifications'];
    final specRows = <(String, String)>[
      if (specs is List)
        for (final s in asJsonList(specs)) (s.str('label'), s.str('value'))
      else if (specs is Map)
        for (final e in specs.entries) (e.key.toString(), e.value.toString()),
    ];
    final description = product.s('description');
    return SectionCard(
      margin: const EdgeInsets.fromLTRB(0, 12, 0, 0),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Wrap(children: [
          for (final (i, label) in [context.t('product.overview'), context.t('product.specifications')].indexed)
            Padding(
              padding: const EdgeInsetsDirectional.only(end: 16),
              child: InkWell(
                onTap: () => setState(() => _tab = i),
                child: Container(
                  padding: const EdgeInsets.only(bottom: 6),
                  decoration: BoxDecoration(border: Border(bottom: BorderSide(color: _tab == i ? NawyColors.bgBrand : Colors.transparent, width: 2))),
                  child: Text(label, style: NawyText.bodyMedium.copyWith(color: _tab == i ? NawyColors.textPrimary : NawyColors.textSecondary)),
                ),
              ),
            ),
        ]),
        const SizedBox(height: 10),
        if (_tab == 0) ...[
          for (final h in highlights) Padding(padding: const EdgeInsets.only(bottom: 4), child: Text('•  $h', style: NawyText.body)),
          if (description != null) Text(stripHtml(description), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
          if (highlights.isEmpty && description == null) Text(context.t('product.noOverview'), style: NawyText.bodySm.copyWith(color: NawyColors.textMuted)),
        ] else ...[
          for (final (label, value) in specRows) InfoRow(context.l10n.status(label), value),
          if (specRows.isEmpty) Text(context.t('product.noSpecs'), style: NawyText.bodySm.copyWith(color: NawyColors.textMuted)),
        ],
      ]),
    );
  }

  Widget _sellerCard(BuildContext context, Json offer, int otherCount, Json product) {
    final seller = offer.m('seller') ?? const {};
    return SectionCard(
      margin: const EdgeInsets.fromLTRB(0, 12, 0, 0),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Icon(Icons.storefront_outlined),
          const SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              InkWell(
                key: const ValueKey('pdp-sold-by'),
                onTap: seller.s('slug') == null ? null : () => Navigator.of(context).pushNamed(Routes.seller, arguments: {'slug': seller.s('slug')}),
                child: Text(context.t('product.soldBy', {'seller': seller.str('name')}), style: NawyText.bodyMedium.copyWith(color: seller.s('slug') == null ? null : NawyColors.textLink)),
              ),
              if (seller.s('rating') != null) Text('★ ${seller.s('rating')} · ${context.t('product.ratingsCount', {'count': seller.i('rating_count') ?? 0})}', style: NawyText.caption),
            ]),
          ),
          if (seller.s('slug') != null)
            TextButton(
              key: const ValueKey('pdp-visit-store'),
              onPressed: () => Navigator.of(context).pushNamed(Routes.seller, arguments: {'slug': seller.s('slug')}),
              child: Text(context.t('product.visitStore')),
            ),
        ]),
        if (otherCount > 0)
          TextButton(
            key: const ValueKey('other-offers'),
            onPressed: () => _showOffers(context, product),
            child: Text(context.t('product.otherOffers', {'count': otherCount, 'price': formatMoney(product['from_price'], locale: context.l10n.locale)})),
          ),
      ]),
    );
  }

  Future<void> _showOffers(BuildContext context, Json product) => showNawySheet<void>(
        context,
        title: context.t('product.allOffers'),
        builder: (ctx) => ListView(shrinkWrap: true, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
          for (final o in [if (product.m('buy_box') != null) product.m('buy_box')!, ...product.l('other_offers')])
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                title: Text(formatMoney(o['price'], locale: ctx.l10n.locale), style: NawyText.h4),
                subtitle: Text([
                  o.m('seller')?.s('name'),
                  if (o.m('options') != null) o.m('options')!.values.join(' / '),
                  ctx.l10n.status(o.s('condition')),
                  if (o.m('delivery') != null) ctx.t('product.getItBy', {'date': shortDate(ctx, o.m('delivery')!.s('by'))}),
                ].whereType<String>().where((s) => s.isNotEmpty).join(' · ')),
                trailing: FilledButton(
                  onPressed: (o.i('in_stock') ?? 0) > 0
                      ? () async {
                          Navigator.of(ctx).pop();
                          await _addToCart(product, o);
                        }
                      : null,
                  child: Text(ctx.t('cart.add')),
                ),
              ),
            ),
        ]),
      );
}

class _Gallery extends StatefulWidget {
  const _Gallery({required this.images, required this.selected});

  final List<Json> images;
  final Map<String, String> selected;

  @override
  State<_Gallery> createState() => _GalleryState();
}

class _GalleryState extends State<_Gallery> {
  int _page = 0;

  @override
  Widget build(BuildContext context) {
    final images = widget.images;
    if (images.isEmpty) return const NetImage(null, icon: Icons.image_outlined, background: NawyColors.bgSurface);
    return Stack(children: [
      PageView.builder(
        itemCount: images.length,
        onPageChanged: (i) => setState(() => _page = i),
        itemBuilder: (_, i) => Semantics(image: true, label: images[i].s('alt') ?? '', child: NetImage(images[i].s('url'), fit: BoxFit.contain, background: NawyColors.bgSurface)),
      ),
      if (images.length > 1)
        Positioned(
          bottom: 10,
          left: 0,
          right: 0,
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            for (var i = 0; i < images.length; i++)
              Container(
                margin: const EdgeInsets.symmetric(horizontal: 2),
                width: 6,
                height: 6,
                decoration: BoxDecoration(shape: BoxShape.circle, color: i == _page ? NawyColors.textPrimary : NawyColors.borderDefault),
              ),
          ]),
        ),
    ]);
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.plan, required this.selected, required this.onTap});

  final Json plan;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        child: Container(
          width: 200,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: selected ? NawyColors.bgBrandSubtle : NawyColors.bgSurface,
            borderRadius: BorderRadius.circular(NawyRadius.card),
            border: Border.all(color: selected ? NawyColors.bgBrand : NawyColors.borderDefault, width: selected ? 1.5 : 1),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(plan.str('name'), style: NawyText.bodyMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
            Text(context.t('product.months', {'n': plan.i('months') ?? 0}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
            const Spacer(),
            Row(children: [
              Expanded(child: MoneyText(plan['price'], style: NawyText.label)),
              Text(selected ? context.t('common.selected') : context.t('common.select'), style: NawyText.label.copyWith(color: NawyColors.textLink)),
            ]),
          ]),
        ),
      );
}

/// Size guide table (`GET products/{slug}/size-guide`, `column_defs`).
class SizeGuideView extends StatelessWidget {
  const SizeGuideView({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context) => AsyncBody<Json>(
        load: () => AppScope.read(context).api.getObject('products/${enc(slug)}/size-guide'),
        builder: (context, guide, _) {
          final defs = guide.l('column_defs');
          final columns = defs.isNotEmpty ? defs.map((d) => d.str('key')).toList() : guide.strings('columns');
          final labels = {for (final d in defs) d.str('key'): '${d.s('label') ?? d.str('key')}${d.s('unit') != null ? ' (${d.s('unit')})' : ''}'};
          return SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: DataTable(
                  columns: [for (final c in columns) DataColumn(label: Text(labels[c] ?? c))],
                  rows: [
                    for (final row in guide.l('rows')) DataRow(cells: [for (final c in columns) DataCell(Text(row.str(c)))]),
                  ],
                ),
              ),
              if (guide.s('notes') != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(guide.str('notes'), style: NawyText.bodySm)),
            ]),
          );
        },
      );
}

/// Instalment split shown by the buy-now-pay-later providers (4 payments).
/// Rounded up to the currency's minor unit so 4 payments cover the price.
Money bnplInstalment(Money price, {int parts = 4}) => Money.minorUnits((price.minor + BigInt.from(parts - 1)) ~/ BigInt.from(parts), price.currency);

/// Figma PDP "Split in 4" row (QC-064): only when `checkout/payment-methods`
/// offers Tabby or Tamara for this amount in the market.
class BnplLine extends StatelessWidget {
  const BnplLine({super.key, required this.price});

  final Money price;

  @override
  Widget build(BuildContext context) => OnceFuture<List<Json>>(
        load: () => AppScope.read(context).api.getList('checkout/payment-methods', query: {'amount': price.amount}).then((r) => r.data),
        builder: (context, snap) {
          final names = [
            for (final m in snap.data ?? const <Json>[])
              if (const {'tabby', 'tamara'}.contains(m.s('code'))) m.s('name') ?? context.l10n.status(m.s('code')),
          ];
          if (names.isEmpty) return const SizedBox.shrink();
          return Padding(
            key: const ValueKey('pdp-bnpl'),
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              context.t('product.bnpl', {'amount': bnplInstalment(price).format(locale: context.l10n.locale), 'providers': names.join(' · ')}),
              style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
            ),
          );
        },
      );
}
