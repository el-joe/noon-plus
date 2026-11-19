import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../state/compare_list.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';

/// PDP badges (`badges[{key,label}]`) and the model number (QC-057).
class PdpBadges extends StatelessWidget {
  const PdpBadges({super.key, required this.product});

  final Json product;

  @override
  Widget build(BuildContext context) {
    final badges = cardBadges(product);
    final model = product.s('model_number');
    if (badges.isEmpty && (model == null || model.isEmpty)) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Wrap(
        spacing: 6,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          for (final b in badges)
            Pill(
              b.str('label'),
              key: ValueKey('pdp-badge-${b.str('key')}'),
              background: b.s('key') == 'deal' ? NawyColors.bgDanger : NawyColors.bgBrandSubtle,
              foreground: b.s('key') == 'deal' ? NawyColors.textInverse : NawyColors.textBrand,
            ),
          if (model != null && model.isNotEmpty)
            Text(
              context.t('product.modelNumber', {'model': model}),
              key: const ValueKey('pdp-model'),
              style: NawyText.caption.copyWith(color: NawyColors.textSecondary),
            ),
        ],
      ),
    );
  }
}

/// The short text of a public coupon: "10% off", "AED 20 off", "Buy 2 get 1".
String couponHeadline(BuildContext context, Json coupon) {
  final label = coupon.s('label');
  if (label != null && label.isNotEmpty) return label;
  final percent = coupon.s('percent');
  final amount = Money.fromJson(coupon['amount']);
  return switch (coupon.s('type')) {
    'bogo' || 'buy_x_get_y' => context.t('offers.bogo', {'buy': coupon.i('buy_quantity') ?? 1, 'get': coupon.i('get_quantity') ?? 1}),
    _ when percent != null && (double.tryParse(percent) ?? 0) > 0 => context.t('offers.percentOff', {'percent': _trimZeros(percent)}),
    _ when amount != null && !amount.isZero => context.t('offers.amountOff', {'amount': amount.format(locale: context.l10n.locale)}),
    _ => coupon.str('code'),
  };
}

String _trimZeros(String number) => number.contains('.') ? number.replaceAll(RegExp(r'\.?0+$'), '') : number;

/// Copy a coupon code (the customer applies it in the cart).
Future<void> copyCoupon(BuildContext context, String code) async {
  await Clipboard.setData(ClipboardData(text: code));
  if (context.mounted) showToast(context, context.t('offers.copied', {'code': code}));
}

/// A tappable coupon chip: headline + code; tap copies the code.
class CouponChip extends StatelessWidget {
  const CouponChip({super.key, required this.coupon});

  final Json coupon;

  @override
  Widget build(BuildContext context) {
    final code = coupon.str('code');
    final min = Money.fromJson(coupon['min_order']);
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 280),
      child: Material(
        color: NawyColors.bgSuccessSubtle,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(NawyRadius.md),
          side: const BorderSide(color: NawyColors.bgSuccess),
        ),
        child: InkWell(
          key: ValueKey('coupon-$code'),
          borderRadius: BorderRadius.circular(NawyRadius.md),
          onTap: () => copyCoupon(context, code),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.local_offer_outlined, size: 16, color: NawyColors.textSuccess),
                const SizedBox(width: 6),
                Flexible(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        couponHeadline(context, coupon),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: NawyText.label.copyWith(color: NawyColors.textSuccess),
                      ),
                      Text(
                        [
                          context.t('blocks.useCode', {'code': code}),
                          if (min != null && !min.isZero) context.t('offers.minOrder', {'amount': min.format(locale: context.l10n.locale)}),
                        ].join(' · '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: NawyText.caption.copyWith(color: NawyColors.textSecondary),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 6),
                const Icon(Icons.copy_rounded, size: 14, color: NawyColors.textSecondary),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// PDP coupon chips (`GET products/{slug}/coupons`, QC-064).
class ProductCoupons extends StatelessWidget {
  const ProductCoupons({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context) => OnceFuture<List<Json>>(
    load: () => AppScope.read(context).api.getList('products/${enc(slug)}/coupons').then((r) => r.data),
    builder: (context, snap) {
      final coupons = snap.data ?? const <Json>[];
      if (coupons.isEmpty) return const SizedBox.shrink();
      return Padding(
        key: const ValueKey('pdp-coupons'),
        padding: const EdgeInsets.only(top: 10),
        child: Wrap(spacing: 8, runSpacing: 8, children: [for (final c in coupons) CouponChip(coupon: c)]),
      );
    },
  );
}

/// "Frequently bought together" with "Add all" (QC-057,
/// `GET products/{slug}/frequently-bought-together`).
class FrequentlyBoughtTogether extends StatelessWidget {
  const FrequentlyBoughtTogether({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context) => OnceFuture<Json>(
    load: () => AppScope.read(context).api.getObject('products/${enc(slug)}/frequently-bought-together'),
    builder: (context, snap) {
      final data = snap.data ?? const <String, dynamic>{};
      final items = data.l('items').where((i) => i.i('listing_id') != null).toList();
      if (items.isEmpty) return const SizedBox.shrink();
      final own = data.m('product')?.i('listing_id');
      final listings = [?own, for (final i in items) i.i('listing_id')!];
      final total = data['bundle_total'];
      return Column(
        key: const ValueKey('pdp-fbt'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SectionHeader(title: context.t('product.fbtTitle')),
          ProductRail(cards: items),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    total == null
                        ? context.t('product.fbtCount', {'count': listings.length})
                        : context.t('product.fbtTotal', {'count': listings.length, 'total': formatMoney(total, locale: context.l10n.locale)}),
                    style: NawyText.bodyMedium,
                  ),
                ),
                BusyButton(key: const ValueKey('fbt-add-all'), expand: false, label: context.t('product.fbtAddAll'), onPressed: () => addAllToCart(context, listings)),
              ],
            ),
          ),
        ],
      );
    },
  );
}

/// Add each listing to the cart (quantity 1). Stops at the first refusal and
/// shows it; returns the number added.
Future<int> addAllToCart(BuildContext context, List<int> listings) async {
  final app = AppScope.read(context);
  var added = 0;
  for (final listing in listings) {
    try {
      await app.cart.add(listing);
      added++;
    } catch (e) {
      if (context.mounted) showToast(context, errorMessage(context, e), error: true);
      return added;
    }
  }
  if (context.mounted) showToast(context, context.t('product.fbtAdded', {'count': added}));
  return added;
}

/// Add / remove [slug] from the compare list, with a snack offering to open
/// the comparison (QC-043).
void toggleCompare(BuildContext context, String slug) {
  final list = CompareList.instance;
  final was = list.contains(slug);
  if (!list.toggle(slug)) {
    showToast(context, context.t('compare.full', {'max': CompareList.max}), error: true);
    return;
  }
  final messenger = ScaffoldMessenger.maybeOf(context);
  messenger?.hideCurrentSnackBar();
  messenger?.showSnackBar(
    SnackBar(
      content: Text(context.t(was ? 'compare.removed' : 'compare.added')),
      action: list.value.length >= 2 ? SnackBarAction(label: context.t('compare.open', {'count': list.value.length}), onPressed: () => Navigator.of(context).pushNamed(Routes.compare)) : null,
    ),
  );
}

/// The PDP "Compare" toggle.
class CompareToggle extends StatelessWidget {
  const CompareToggle({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context) => ValueListenableBuilder<List<String>>(
    valueListenable: CompareList.instance,
    builder: (context, slugs, _) {
      final on = slugs.contains(slug);
      return IconButton(
        key: const ValueKey('pdp-compare'),
        tooltip: context.t(on ? 'compare.remove' : 'compare.add'),
        onPressed: () => toggleCompare(context, slug),
        icon: Icon(on ? Icons.library_add_check_rounded : Icons.library_add_outlined, color: on ? NawyColors.textLink : null),
      );
    },
  );
}
