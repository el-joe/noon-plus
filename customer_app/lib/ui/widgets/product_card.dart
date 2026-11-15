import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../theme.dart';
import 'common.dart';
import 'states.dart';

enum ProductCardVariant { grid, carousel, list }

/// Figma component "Product card" (94:12069): Grid, Carousel, Sponsored, List.
/// Input is the API card (`ProductCardResource`): slug, title, brand, price,
/// image, rating, listing_id, sponsored, label, ad_token. Deal cards from
/// flash sales pass [was] and [tag].
class ProductCard extends StatelessWidget {
  const ProductCard({super.key, required this.card, this.variant = ProductCardVariant.grid, this.was, this.tag, this.merchLine, this.width, this.onWishlist, this.wished = false});

  final Json card;
  final ProductCardVariant variant;
  final Json? was;
  final String? tag;
  final String? merchLine;
  final double? width;
  final VoidCallback? onWishlist;
  final bool wished;

  bool get sponsored => card.b('sponsored');

  void _open(BuildContext context) {
    final slug = card.s('slug') ?? card.s('product_slug');
    if (slug == null) return;
    final token = card.s('ad_token');
    if (sponsored && token != null) {
      AppScope.read(context).api.post('ads/click', body: {'token': token}).then((_) {}, onError: (_) {});
    }
    Navigator.of(context).pushNamed(Routes.product, arguments: {'slug': slug});
  }

  Future<void> _quickAdd(BuildContext context) async {
    final listing = card.i('listing_id');
    if (listing == null) return _open(context);
    final app = AppScope.read(context);
    await guarded(context, () => app.cart.add(listing), success: context.t('cart.added'));
  }

  @override
  Widget build(BuildContext context) {
    final price = Money.fromJson(card['price']);
    final wasPrice = Money.fromJson(was);
    final rating = card.m('rating');
    final image = card.m('image')?.s('url') ?? card.s('image_url');
    final list = variant == ProductCardVariant.list;
    final size = list ? 110.0 : (variant == ProductCardVariant.carousel ? 150.0 : null);
    final effectiveTag = tag ?? card.s('label');

    final imageBox = SizedBox(
      width: size,
      height: size,
      child: AspectRatio(
        aspectRatio: 1,
        child: Stack(children: [
          Positioned.fill(child: NetImage(image, radius: NawyRadius.card, fit: BoxFit.cover)),
          if (effectiveTag != null && effectiveTag.isNotEmpty && !sponsored)
            PositionedDirectional(
              top: 6,
              start: 6,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(color: NawyColors.textPrimary, borderRadius: BorderRadius.circular(NawyRadius.sm)),
                child: Text(effectiveTag, style: NawyText.caption.copyWith(color: NawyColors.textInverse)),
              ),
            ),
          if (onWishlist != null)
            PositionedDirectional(
              top: 2,
              end: 2,
              child: IconButton(
                tooltip: context.t('wishlist.title'),
                visualDensity: VisualDensity.compact,
                onPressed: onWishlist,
                icon: Icon(wished ? Icons.favorite : Icons.favorite_border, size: 18, color: wished ? NawyColors.bgDanger : NawyColors.textPrimary),
              ),
            ),
          PositionedDirectional(
            bottom: 6,
            end: 6,
            child: Material(
              color: NawyColors.bgSurface,
              shape: const CircleBorder(side: BorderSide(color: NawyColors.borderDefault)),
              child: InkWell(
                customBorder: const CircleBorder(),
                onTap: () => _quickAdd(context),
                child: Tooltip(message: context.t('cart.add'), child: const SizedBox(width: 28, height: 28, child: Icon(Icons.add, size: 16))),
              ),
            ),
          ),
        ]),
      ),
    );

    final info = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (sponsored) AdLabel(text: card.s('label')),
        Text(card.str('title'), maxLines: 2, overflow: TextOverflow.ellipsis, style: NawyText.caption.copyWith(height: 1.3)),
        const SizedBox(height: 3),
        RatingPill(average: rating?.s('average'), count: rating?.i('count')),
        const SizedBox(height: 2),
        PriceLine(price: price, was: wasPrice),
        if (merchLine != null) Text(merchLine!, style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
        if (card.s('fulfilment_mode') == 'fbn' || card.s('fulfilment_mode') == 'express')
          Padding(
            padding: const EdgeInsets.only(top: 3),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
              decoration: BoxDecoration(color: NawyColors.bgBrand, borderRadius: BorderRadius.circular(NawyRadius.xs)),
              child: Text(context.t('product.express'), style: NawyText.caption),
            ),
          ),
      ],
    );

    return Semantics(
      button: true,
      label: card.str('title'),
      child: InkWell(
        onTap: () => _open(context),
        borderRadius: BorderRadius.circular(NawyRadius.card),
        child: SizedBox(
          width: width ?? (list ? null : size),
          child: list
              ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [imageBox, const SizedBox(width: 12), Expanded(child: info)])
              : Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [imageBox, const SizedBox(height: 4), info]),
        ),
      ),
    );
  }
}

/// Horizontal product carousel (~2.3 cards visible).
class ProductRail extends StatelessWidget {
  const ProductRail({super.key, required this.cards, this.wasPrices, this.tags, this.height = 262});

  final List<Json> cards;
  final List<Json?>? wasPrices;
  final List<String?>? tags;
  final double height;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: height,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          itemCount: cards.length,
          separatorBuilder: (_, _) => const SizedBox(width: 10),
          itemBuilder: (context, i) => ProductCard(
            card: cards[i],
            variant: ProductCardVariant.carousel,
            was: wasPrices != null && i < wasPrices!.length ? wasPrices![i] : null,
            tag: tags != null && i < tags!.length ? tags![i] : null,
          ),
        ),
      );
}

/// Two-column product grid inside a scroll view (shrink-wrapped).
class ProductGrid extends StatelessWidget {
  const ProductGrid({super.key, required this.cards, this.padding = const EdgeInsets.symmetric(horizontal: 16)});

  final List<Json> cards;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => Padding(
        padding: padding,
        child: LayoutBuilder(builder: (context, constraints) {
          final columns = constraints.maxWidth > 700 ? 4 : (constraints.maxWidth > 500 ? 3 : 2);
          final itemWidth = (constraints.maxWidth - (columns - 1) * 12) / columns;
          return Wrap(
            spacing: 12,
            runSpacing: 16,
            children: [for (final card in cards) ProductCard(card: card, width: itemWidth)],
          );
        }),
      );
}
