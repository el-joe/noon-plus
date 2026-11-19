import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../features/page_builder/blocks.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../state/cart_store.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';
import 'free_delivery_bar.dart';

/// Figma "21 Cart", "22 Cart empty", "23 Coupons": shipments per seller with
/// delivery estimates, quantity, protection plan, save for later (wishlist),
/// coupon sheet, totals incl. VAT, sticky checkout.
class CartTab extends StatefulWidget {
  const CartTab({super.key, this.asTab = false});

  final bool asTab;

  @override
  State<CartTab> createState() => _CartTabState();
}

class _CartTabState extends State<CartTab> {
  late final CartStore _cart = AppScope.read(context).cart;
  Object? _error;
  bool _loaded = false;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    try {
      await _cart.refresh();
      _error = null;
    } catch (e) {
      _error = e;
    }
    if (mounted) setState(() => _loaded = true);
  }

  Future<void> _saveForLater(Json line) async {
    if (!await requireSignIn(context) || !mounted) return;
    final slug = line.s('product_slug');
    final api = AppScope.read(context).api;
    await guarded(context, () async {
      if (slug != null) await api.post('wishlist', body: {'product': slug});
      await _cart.remove(line.i('listing_id')!);
    }, success: context.t('cart.savedForLater'));
  }

  Future<void> _coupons() => showNawySheet<void>(context, title: context.t('cart.coupons'), builder: (_) => CouponSheet(cart: _cart));

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: _cart,
        builder: (context, _) {
          final cart = _cart.cart;
          Widget body;
          if (!_loaded && cart == null) {
            body = const LoadingView();
          } else if (cart == null && _error != null) {
            body = ErrorView(error: _error!, onRetry: _refresh);
          } else if (_cart.isEmpty) {
            body = _empty(context);
          } else {
            body = _filled(context, cart!);
          }
          return Scaffold(
            appBar: AppBar(
              automaticallyImplyLeading: !widget.asTab,
              title: Text(_cart.isEmpty ? context.t('tabs.cart') : context.t('cart.titleCount', {'count': _cart.count})),
            ),
            body: Stack(children: [
              body,
              if (_cart.loading) const Positioned(top: 0, left: 0, right: 0, child: LinearProgressIndicator(minHeight: 2)),
            ]),
            bottomNavigationBar: _cart.isEmpty || cart == null ? null : _checkoutBar(context, cart),
          );
        },
      );

  Widget _empty(BuildContext context) => RefreshIndicator(
        onRefresh: _refresh,
        child: ListView(children: [
          Container(
            margin: const EdgeInsets.all(16),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: NawyColors.bgBrandSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
            child: Text(context.t('cart.promo'), style: NawyText.label),
          ),
          EmptyView(
            icon: Icons.shopping_cart_outlined,
            title: context.t('cart.emptyTitle'),
            subtitle: context.t('cart.emptyHint'),
            actionLabel: context.t('cart.startShopping'),
            onAction: () {
              final app = AppScope.read(context);
              if (widget.asTab) {
                app.tabRequest.value = 'home';
              } else {
                Navigator.of(context).popUntil((r) => r.settings.name == Routes.shell || r.isFirst);
                app.tabRequest.value = 'home';
              }
            },
          ),
          buildBlock(context, const {'type': 'item_carousel', 'settings': {}}) ?? const SizedBox.shrink(),
        ]),
      );

  Widget _filled(BuildContext context, Json cart) {
    final locale = context.l10n.locale;
    final totals = cart.m('totals') ?? const {};
    final shipments = cart.l('shipments');
    final warnings = cart['warnings'] is List ? cart['warnings'] as List : const [];
    final savings = CartMath.savings(cart);
    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
        // G-A5: the API's free-delivery progress (null → no threshold in this market).
        if (cart['free_delivery'] is Map)
          FreeDeliveryBar(data: asJson(cart['free_delivery']))
        else if (shipments.any((s) => s.b('free_shipping_threshold_applied')))
          _banner(context.t('cart.freeDeliveryApplied'), Icons.local_shipping_outlined, NawyColors.bgSuccessSubtle, NawyColors.textSuccess),
        // Lines of the guest cart clipped or skipped at sign-in (`cart/merge`).
        if (AppScope.of(context).mergeWarnings.isNotEmpty) _mergeWarnings(context, AppScope.of(context).mergeWarnings),
        for (final w in warnings)
          _banner(w is Map ? (asJson(w).s('message') ?? asJson(w).str('code')) : '$w', Icons.warning_amber_rounded, NawyColors.bgWarningSubtle, NawyColors.textBrand),
        for (final (i, shipment) in shipments.indexed)
          SectionCard(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(context.t('cart.shipmentOf', {'n': i + 1, 'total': shipments.length}), style: NawyText.h4),
              Text(
                [
                  if (shipment.s('seller') != null) context.t('product.soldBy', {'seller': shipment.s('seller')}),
                  if (shipment.m('delivery') != null) context.t('product.getItBy', {'date': shortDate(context, shipment.m('delivery')!.s('by'))}),
                ].join(' · '),
                style: NawyText.caption.copyWith(color: NawyColors.textSecondary),
              ),
              const Divider(height: 20),
              for (final line in shipment.l('items')) _line(context, line),
            ]),
          ),
        // Coupons
        SectionCard(
          child: InkWell(
            key: const ValueKey('cart-coupons'),
            onTap: _coupons,
            child: Row(children: [
              const Icon(Icons.confirmation_number_outlined),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  cart.l('coupons').isEmpty ? context.t('cart.applyCoupon') : cart.l('coupons').map((c) => c.str('code')).join(', '),
                  style: NawyText.bodyMedium,
                ),
              ),
              const Icon(Icons.chevron_right_rounded),
            ]),
          ),
        ),
        SectionCard(
          title: context.t('cart.summary'),
          child: Column(children: [
            InfoRow(context.t('cart.subtotal'), formatMoney(totals['subtotal'], locale: locale)),
            if (!(Money.fromJson(totals['discount'])?.isZero ?? true)) InfoRow(context.t('cart.discount'), '-${formatMoney(totals['discount'], locale: locale)}', valueColor: NawyColors.textSuccess),
            InfoRow(context.t('cart.shipping'), (Money.fromJson(totals['shipping'])?.isZero ?? false) ? context.t('cart.free') : formatMoney(totals['shipping'], locale: locale)),
            if (!(Money.fromJson(totals['warranty'])?.isZero ?? true)) InfoRow(context.t('cart.protection'), formatMoney(totals['warranty'], locale: locale)),
            if (!(Money.fromJson(totals['cod_fee'])?.isZero ?? true)) InfoRow(context.t('cart.codFee'), formatMoney(totals['cod_fee'], locale: locale)),
            const Divider(height: 16),
            InfoRow(context.t('cart.total'), formatMoney(totals['total'], locale: locale), bold: true),
            InfoRow(context.t('cart.vatIncluded', {'rate': totals.s('tax_rate') ?? ''}), formatMoney(totals['tax_included'], locale: locale)),
            if (savings != null && !savings.isZero) InfoRow(context.t('cart.youSave'), savings.format(locale: locale), valueColor: NawyColors.textSuccess),
          ]),
        ),
      ]),
    );
  }

  Widget _banner(String text, IconData icon, Color bg, Color fg) => Container(
        margin: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(NawyRadius.md)),
        child: Row(children: [Icon(icon, color: fg, size: 18), const SizedBox(width: 8), Expanded(child: Text(text, style: NawyText.label.copyWith(color: fg)))]),
      );

  Widget _line(BuildContext context, Json line) {
    final listing = line.i('listing_id')!;
    final locale = context.l10n.locale;
    final unit = Money.fromJson(line['unit_price']);
    final regular = Money.fromJson(line['regular_price']);
    final options = line.l('warranty_options');
    final warranty = line.m('warranty');
    return Padding(
      key: ValueKey('cart-line-$listing'),
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          InkWell(
            onTap: line.s('product_slug') == null ? null : () => Navigator.of(context).pushNamed(Routes.product, arguments: {'slug': line.s('product_slug')}),
            child: SizedBox(width: 80, height: 80, child: NetImage(line.m('image')?.s('url'), radius: NawyRadius.md)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(line.str('title'), maxLines: 2, overflow: TextOverflow.ellipsis, style: NawyText.body),
              const SizedBox(height: 4),
              PriceLine(price: unit, was: regular),
              if (line.m('flash_sale') != null) Text(context.t('deals.deal'), style: NawyText.caption.copyWith(color: NawyColors.textDanger)),
              if (line.b('measurements_missing')) Text(context.t('cart.measurementsMissing'), style: NawyText.caption.copyWith(color: NawyColors.textDanger)),
              Text('${context.t('cart.lineTotal')}: ${formatMoney(line['line_total'], locale: locale)}', style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
            ]),
          ),
        ]),
        const SizedBox(height: 8),
        Row(children: [
          QuantityStepper(
            key: ValueKey('qty-$listing'),
            value: line.i('quantity') ?? 1,
            onChanged: (q) => guarded(context, () => _cart.setQuantity(listing, q)),
          ),
          const Spacer(),
          IconButton(tooltip: context.t('cart.saveForLater'), onPressed: () => _saveForLater(line), icon: const Icon(Icons.favorite_border_rounded)),
          IconButton(tooltip: context.t('common.remove'), onPressed: () => guarded(context, () => _cart.remove(listing)), icon: const Icon(Icons.delete_outline_rounded)),
        ]),
        if (options.isNotEmpty)
          DropdownButtonFormField<int?>(
            initialValue: warranty?.i('plan_id'),
            isExpanded: true,
            decoration: InputDecoration(labelText: context.t('product.protection')),
            items: [
              DropdownMenuItem<int?>(value: null, child: Text(context.t('cart.noProtection'))),
              for (final o in options) DropdownMenuItem<int?>(value: o.i('plan_id'), child: Text('${o.str('name')} · ${formatMoney(o['price'], locale: locale)}')),
            ],
            onChanged: (v) => guarded(context, () => _cart.setWarranty(listing, v)),
          ),
      ]),
    );
  }

  Widget _checkoutBar(BuildContext context, Json cart) => Container(
        decoration: const BoxDecoration(color: NawyColors.bgSurface, border: Border(top: BorderSide(color: NawyColors.borderDefault))),
        padding: EdgeInsets.fromLTRB(16, 10, 16, 10 + (widget.asTab ? 0 : MediaQuery.of(context).padding.bottom)),
        child: Row(children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              Text(context.t('cart.itemsCount', {'count': _cart.count}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
              MoneyText(cart.m('totals')?['total'], style: NawyText.h3),
            ]),
          ),
          FilledButton(
            key: const ValueKey('cart-checkout'),
            onPressed: () => Navigator.of(context).pushNamed(Routes.checkout),
            child: Text(context.t('cart.checkout')),
          ),
        ]),
      );
}

Widget _mergeWarnings(BuildContext context, List<Json> warnings) => Container(
      key: const ValueKey('cart-merge-warnings'),
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
      decoration: BoxDecoration(color: NawyColors.bgWarningSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Padding(padding: EdgeInsets.only(top: 2), child: Icon(Icons.info_outline_rounded, size: 18, color: NawyColors.textBrand)),
        const SizedBox(width: 8),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(context.t('cart.mergeTitle'), style: NawyText.bodyMedium),
            for (final w in warnings)
              Text(
                [
                  w.s('message') ?? context.l10n.status(w.s('code')),
                  if (w.i('requested') != null && w.i('merged') != null) context.t('cart.mergeQty', {'requested': w.i('requested'), 'merged': w.i('merged')}),
                ].join(' '),
                style: NawyText.bodySm,
              ),
          ]),
        ),
        IconButton(
          key: const ValueKey('cart-merge-dismiss'),
          tooltip: context.t('common.close'),
          visualDensity: VisualDensity.compact,
          onPressed: () => AppScope.read(context).clearMergeWarnings(),
          icon: const Icon(Icons.close_rounded, size: 18),
        ),
      ]),
    );

/// Coupon sheet: applied coupons + code input (`POST cart/coupons`).
class CouponSheet extends StatefulWidget {
  const CouponSheet({super.key, required this.cart});

  final CartStore cart;

  @override
  State<CouponSheet> createState() => _CouponSheetState();
}

class _CouponSheetState extends State<CouponSheet> {
  final _code = TextEditingController();
  String? _error;
  String? _errorCode;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: widget.cart,
        builder: (context, _) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), children: [
          Text(context.t('cart.couponRule'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(
              child: TextField(
                key: const ValueKey('coupon-input'),
                controller: _code,
                textCapitalization: TextCapitalization.characters,
                decoration: InputDecoration(hintText: context.t('cart.couponCode'), errorText: _error),
              ),
            ),
            const SizedBox(width: 8),
            BusyButton(
              expand: false,
              label: context.t('cart.apply'),
              onPressed: () async {
                final code = _code.text.trim();
                if (code.isEmpty) return;
                try {
                  await widget.cart.applyCoupon(code);
                  _code.clear();
                  setState(() {
                    _error = null;
                    _errorCode = null;
                  });
                } on ApiException catch (e) {
                  // Coupon refusals (`coupon_no_eligible_items`,
                  // `coupon_sign_in_required`, `coupon_first_order_only`,
                  // `coupon_segment`, …) come translated by the API; 429
                  // after 10 tries a minute shows the wait.
                  if (mounted) {
                    setState(() {
                      _error = e.isRateLimited ? errorMessage(context, e) : (e.fieldErrors['code'] ?? errorMessage(context, e));
                      _errorCode = e.code;
                    });
                  }
                }
              },
            ),
          ]),
          if (_errorCode == 'coupon_sign_in_required')
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton(
                key: const ValueKey('coupon-sign-in'),
                onPressed: () async {
                  if (await requireSignIn(context) && mounted) setState(() => _errorCode = _error = null);
                },
                child: Text(context.t('auth.signIn')),
              ),
            ),
          // `data.warnings`: an applied coupon the customer is no longer
          // eligible for, or a BOGO without enough units, stays on the cart
          // with a 0 discount.
          for (final w in couponWarnings(widget.cart.cart))
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Icon(Icons.warning_amber_rounded, size: 16, color: NawyColors.textBrand),
                const SizedBox(width: 6),
                Expanded(child: Text(w, key: const ValueKey('coupon-warning'), style: NawyText.bodySm)),
              ]),
            ),
          const SizedBox(height: 12),
          for (final c in widget.cart.cart?.l('coupons') ?? const <Json>[])
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(children: [
                Expanded(
                  child: CouponChip(
                    width: double.infinity,
                    title: c.b('free_shipping') ? context.t('cart.freeShippingCoupon') : '-${formatMoney(c['discount'], locale: context.l10n.locale)}',
                    code: c.str('code'),
                  ),
                ),
                IconButton(tooltip: context.t('common.remove'), onPressed: () => guarded(context, () => widget.cart.removeCoupon(c.str('code'))), icon: const Icon(Icons.close_rounded)),
              ]),
            ),
        ]),
      );
}

/// Cart warnings that mention an applied coupon code.
List<String> couponWarnings(Json? cart) {
  if (cart == null) return const [];
  final codes = cart.l('coupons').map((c) => c.str('code')).where((c) => c.isNotEmpty).toList();
  final warnings = cart['warnings'] is List ? (cart['warnings'] as List) : const [];
  return [
    for (final w in warnings)
      if (w is String && codes.any(w.contains)) w,
  ];
}
