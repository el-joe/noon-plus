import 'dart:math';

import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';
import '../account/addresses_screens.dart';
import '../cart/cart_tab.dart';
import 'payment_flow.dart';

/// Figma "24 Checkout": address book, delivery per shipment, payment methods
/// for the country and amount (`GET checkout/payment-methods`), coupon, Nawy
/// Credits, contract gate, place order → gateway (system browser) → verify.
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  String? _attemptKey;
  List<Json>? _addresses;
  Json? _address;
  List<Json> _methods = const [];
  String? _method;
  Json? _wallet;
  Object? _error;
  bool _loading = true;

  AppState get _app => AppScope.read(context);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final results = await Future.wait([
        _app.api.getList('addresses').then((r) => r.data),
        _app.api.getObject('wallet').catchError((_) => <String, dynamic>{}),
        _app.cart.refresh(),
      ]);
      _addresses = results[0] as List<Json>;
      _wallet = results[1] as Json;
      _address ??= _addresses!.firstWhere((a) => a.b('is_default'), orElse: () => _addresses!.isNotEmpty ? _addresses!.first : <String, dynamic>{});
      if (_address!.isEmpty) _address = null;
      if (_address != null) await _quote(_address!);
      await _loadMethods();
    } catch (e) {
      _error = e;
    }
    if (mounted) setState(() => _loading = false);
  }

  Future<void> _quote(Json address) async {
    final destination = _app.cart.cart?.m('destination');
    if (destination?.s('city') == address.s('city') && destination?.s('area') == address.s('area')) return;
    await _app.cart.setShipping(city: address.s('city'), area: address.s('area'));
  }

  Future<void> _loadMethods() async {
    final total = Money.fromJson(_app.cart.cart?.m('totals')?['total']);
    final res = await _app.api.getList('checkout/payment-methods', query: {'amount': total?.amount});
    _methods = res.data;
    if (_method == null || !_methods.any((m) => m.s('code') == _method)) {
      _method = (_methods.firstWhere((m) => m.b('recommended'), orElse: () => _methods.isNotEmpty ? _methods.first : <String, dynamic>{})).s('code');
    }
  }

  Future<void> _pickAddress() async {
    final picked = await Navigator.of(context).pushNamed(Routes.addresses, arguments: {'pick': true});
    if (picked is Map && mounted) {
      final address = asJson(picked);
      setState(() => _address = address);
      await guarded(context, () async {
        await _quote(address);
        await _loadMethods();
      });
      if (mounted) setState(() {});
    }
  }

  Future<void> _selectMethod(String code) async {
    setState(() => _method = code);
    await guarded(context, () => _app.cart.refresh(paymentMethod: code));
  }

  Future<void> _place() async {
    if (_address == null) {
      showToast(context, context.t('checkout.needAddress'), error: true);
      return;
    }
    if (_method == null) {
      showToast(context, context.t('checkout.needPayment'), error: true);
      return;
    }
    // QC-044: one Idempotency-Key per order attempt, kept only while the outcome is unknown (network error / 5xx).
    _attemptKey ??= 'order-${DateTime.now().microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 32).toRadixString(36)}';
    try {
      final res = await _app.api.post('orders', body: {'payment_method': _method, 'address_id': _address!.i('id')}, headers: {'Idempotency-Key': _attemptKey!});
      _attemptKey = null;
      final order = asJson(res.data);
      _app.cart.clear();
      await _app.cart.refresh().catchError((_) => null);
      if (!mounted) return;
      await continuePayment(context, payment: order.m('payment'), orderNumber: order.str('number'));
    } on ApiException catch (e) {
      if (e.status < 500) _attemptKey = null;
      if (!mounted) return;
      if (e.code == 'customer_contract_required') {
        final contracts = asJsonList(e.details['contracts']);
        if (contracts.isNotEmpty && await _acceptContract(contracts.first) && mounted) await _place();
        return;
      }
      showToast(context, e.code == 'measurements_required' ? context.t('cart.measurementsMissing') : e.message, error: true);
    }
  }

  Future<bool> _acceptContract(Json contract) async {
    final name = TextEditingController(text: _app.user?.s('name') ?? '');
    final accepted = await showNawySheet<bool>(
      context,
      title: contract.str('title'),
      builder: (ctx) => OnceFuture<Json>(
        load: () => _app.api.getObject('contracts/${contract.i('version_id')}'),
        builder: (ctx, snap) => ListView(padding: const EdgeInsets.all(16), shrinkWrap: true, children: [
          if (snap.data?.s('text') != null) Text(snap.data!.str('text'), style: NawyText.bodySm) else const LoadingView(),
          const SizedBox(height: 12),
          TextField(controller: name, decoration: InputDecoration(labelText: ctx.t('contracts.fullName'))),
          const SizedBox(height: 12),
          BusyButton(
            label: ctx.t('contracts.accept'),
            onPressed: () async {
              final ok = await guarded(ctx, () => _app.api.post('contracts/${contract.i('version_id')}/accept', body: {'full_name': name.text.trim(), 'accept': true}));
              if (ok != null && ctx.mounted) Navigator.of(ctx).pop(true);
            },
          ),
        ]),
      ),
    );
    name.dispose();
    return accepted ?? false;
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: _app.cart,
        builder: (context, _) {
          final cart = _app.cart.cart;
          return Scaffold(
            appBar: AppBar(title: Text(context.t('checkout.title'))),
            body: _loading && cart == null
                ? const LoadingView()
                : _error != null && cart == null
                    ? ErrorView(error: _error!, onRetry: _load)
                    : (cart == null || _app.cart.isEmpty)
                        ? EmptyView(icon: Icons.shopping_cart_outlined, title: context.t('cart.emptyTitle'))
                        : _body(context, cart),
            bottomNavigationBar: cart == null || _app.cart.isEmpty
                ? null
                : Container(
                    decoration: const BoxDecoration(color: NawyColors.bgSurface, border: Border(top: BorderSide(color: NawyColors.borderDefault))),
                    padding: EdgeInsets.fromLTRB(16, 10, 16, 10 + MediaQuery.of(context).padding.bottom),
                    child: Row(children: [
                      Expanded(child: MoneyText(cart.m('totals')?['total'], style: NawyText.h3)),
                      BusyButton(key: const ValueKey('place-order'), expand: false, label: context.t('checkout.placeOrder'), onPressed: _place),
                    ]),
                  ),
          );
        },
      );

  Widget _body(BuildContext context, Json cart) {
    final locale = context.l10n.locale;
    final totals = cart.m('totals') ?? const {};
    final available = Money.fromJson(_wallet?['spendable'] ?? _wallet?['available']);
    return ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
      SectionCard(
        title: context.t('checkout.deliverTo'),
        child: _address == null
            ? Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t('checkout.noAddress'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  key: const ValueKey('add-address'),
                  onPressed: () async {
                    final created = await Navigator.of(context).pushNamed(Routes.addressForm);
                    if (created is Map) {
                      _address = asJson(created);
                      await _load();
                    }
                  },
                  icon: const Icon(Icons.add_location_alt_outlined),
                  label: Text(context.t('address.add')),
                ),
              ])
            : InkWell(
                onTap: _pickAddress,
                child: Row(children: [
                  const Icon(Icons.location_on_outlined),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('${addressLabel(context, _address!.s('label'))} · ${_address!.str('name')}', style: NawyText.bodyMedium),
                      Text(addressLine(_address!), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                      if (_address!.s('phone') != null) Text(_address!.str('phone'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary), textDirection: TextDirection.ltr),
                    ]),
                  ),
                  Text(context.t('common.change'), style: NawyText.label.copyWith(color: NawyColors.textLink)),
                ]),
              ),
      ),
      for (final (i, shipment) in cart.l('shipments').indexed)
        SectionCard(
          title: context.t('cart.shipmentOf', {'n': i + 1, 'total': cart.l('shipments').length}),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(
              [
                context.t('cart.itemsCount', {'count': shipment.l('items').fold<int>(0, (s, l) => s + (l.i('quantity') ?? 0))}),
                if (shipment.s('seller') != null) context.t('product.soldBy', {'seller': shipment.s('seller')}),
                if (shipment.m('delivery')?.s('by') != null) context.t('product.getItBy', {'date': shortDate(context, shipment.m('delivery')!.s('by'))}),
              ].join(' · '),
              style: NawyText.caption.copyWith(color: NawyColors.textSecondary),
            ),
            const SizedBox(height: 6),
            for (final option in shipment.l('shipping_options'))
              RadioListTileLite(
                selected: shipment.s('method') == option.s('method'),
                title: '${context.l10n.status(option.s('method'))} · ${(Money.fromJson(option['fee'])?.isZero ?? false) ? context.t('cart.free') : formatMoney(option['fee'], locale: locale)}',
                subtitle: context.t('product.getItBy', {'date': shortDate(context, option.m('delivery')?.s('by'))}),
                onTap: () => guarded(context, () => _app.cart.setShipping(city: _address?.s('city'), area: _address?.s('area'), method: option.s('method'))),
              ),
          ]),
        ),
      SectionCard(
        title: context.t('checkout.payment'),
        child: Column(children: [
          if (_methods.isEmpty) Text(context.t('checkout.noMethods'), style: NawyText.bodySm),
          for (final m in _methods)
            RadioListTileLite(
              key: ValueKey('pay-${m.str('code')}'),
              selected: _method == m.s('code'),
              title: m.str('name'),
              subtitle: [
                m.s('description'),
                if (m.s('type') == 'wallet' && available != null) context.t('checkout.balance', {'amount': available.format(locale: locale)}),
                if (m['fee'] != null) context.t('checkout.fee', {'amount': formatMoney(m['fee'], locale: locale)}),
              ].whereType<String>().join(' · '),
              trailing: m.b('recommended') ? Pill(context.t('checkout.recommended')) : null,
              onTap: () => _selectMethod(m.str('code')),
            ),
          if (available != null && !available.isZero && !_methods.any((m) => m.s('type') == 'wallet'))
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(context.t('checkout.creditsAvailable', {'amount': available.format(locale: locale)}), style: NawyText.caption.copyWith(color: NawyColors.textSuccess)),
            ),
        ]),
      ),
      SectionCard(
        child: InkWell(
          onTap: () => showNawySheet<void>(context, title: context.t('cart.coupons'), builder: (_) => CouponSheet(cart: _app.cart)),
          child: Row(children: [
            const Icon(Icons.confirmation_number_outlined),
            const SizedBox(width: 12),
            Expanded(child: Text(cart.l('coupons').isEmpty ? context.t('cart.applyCoupon') : cart.l('coupons').map((c) => c.str('code')).join(', '), style: NawyText.bodyMedium)),
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
        ]),
      ),
    ]);
  }
}

String addressLine(Json a) => [a.s('building'), a.s('apartment'), a.s('street'), a.s('area'), a.s('city')].whereType<String>().where((s) => s.isNotEmpty).join(', ');

/// Radio row without the deprecated RadioListTile groupValue API.
class RadioListTileLite extends StatelessWidget {
  const RadioListTileLite({super.key, required this.selected, required this.title, this.subtitle, this.trailing, required this.onTap});

  final bool selected;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
        selected: selected,
        inMutuallyExclusiveGroup: true,
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(children: [
              Icon(selected ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded, color: selected ? NawyColors.textPrimary : NawyColors.textMuted),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(title, style: NawyText.bodyMedium),
                  if (subtitle != null && subtitle!.isNotEmpty) Text(subtitle!, style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                ]),
              ),
              ?trailing,
            ]),
          ),
        ),
      );
}

/// Figma "28 Order placed".
class OrderSuccessScreen extends StatelessWidget {
  const OrderSuccessScreen({super.key, required this.number});

  final String number;

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: ListView(padding: const EdgeInsets.all(24), children: [
            const SizedBox(height: 40),
            const Icon(Icons.check_circle_rounded, size: 88, color: NawyColors.bgSuccess),
            const SizedBox(height: 16),
            Text(context.t('checkout.placedTitle'), textAlign: TextAlign.center, style: NawyText.h2),
            const SizedBox(height: 8),
            Text(context.t('checkout.placedHint', {'number': number}), textAlign: TextAlign.center, style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
            const SizedBox(height: 24),
            FilledButton(
              key: const ValueKey('success-view-order'),
              onPressed: () => Navigator.of(context).pushReplacementNamed(Routes.order, arguments: {'number': number}),
              child: Text(context.t('checkout.viewOrder')),
            ),
            const SizedBox(height: 8),
            OutlinedButton(
              onPressed: () {
                AppScope.read(context).tabRequest.value = 'home';
                Navigator.of(context).popUntil((r) => r.settings.name == Routes.shell || r.isFirst);
              },
              child: Text(context.t('cart.continueShopping')),
            ),
          ]),
        ),
      );
}
