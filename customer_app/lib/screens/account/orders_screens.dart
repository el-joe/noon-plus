import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../core/platform/platform_bridge.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/paged_list.dart';
import '../../ui/widgets/states.dart';
import '../auth/sign_in_screen.dart';
import '../checkout/payment_flow.dart';

/// Figma "30 Orders" (`33:658`): tabs with counts, search, paged list
/// (`GET orders?tab=&q=&page=`, `meta.counts`, `meta.last_page`) and the
/// per-shipment buttons from `actions` (QC-026).
class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key, this.asTab = false});

  final bool asTab;

  static const tabs = ['all', 'in_progress', 'delivered', 'cancelled', 'returns'];

  @override
  State<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends State<OrdersScreen> {
  final _search = TextEditingController();
  String _tab = 'all';
  String _q = '';
  Json _counts = const {};
  int _gen = 0;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  void _submit(String value) => setState(() {
        _q = value.trim();
        _gen++;
      });

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('account.orders')), automaticallyImplyLeading: !widget.asTab),
      body: !app.signedIn
          ? const SignInPrompt()
          : Column(children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
                child: TextField(
                  key: const ValueKey('orders-search'),
                  controller: _search,
                  textInputAction: TextInputAction.search,
                  onSubmitted: _submit,
                  decoration: InputDecoration(
                    isDense: true,
                    prefixIcon: const Icon(Icons.search_rounded),
                    hintText: context.t('orders.searchHint'),
                    suffixIcon: _q.isEmpty
                        ? null
                        : IconButton(
                            tooltip: context.t('common.clear'),
                            icon: const Icon(Icons.close_rounded),
                            onPressed: () {
                              _search.clear();
                              _submit('');
                            },
                          ),
                  ),
                ),
              ),
              SizedBox(
                height: 48,
                child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6), children: [
                  for (final tab in OrdersScreen.tabs)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: ChoiceChip(
                        key: ValueKey('orders-tab-$tab'),
                        selected: _tab == tab,
                        label: Text(_counts.i(tab) == null ? context.t('orders.tab.$tab') : '${context.t('orders.tab.$tab')} (${_counts.i(tab)})'),
                        onSelected: (_) => setState(() {
                          _tab = tab;
                          _gen++;
                        }),
                      ),
                    ),
                ]),
              ),
              Expanded(
                child: PagedList(
                  key: ValueKey('orders-$_tab-$_q-$_gen'),
                  load: (page) async {
                    final res = await app.api.getList('orders', query: {'tab': _tab, 'q': _q, 'page': page});
                    final counts = res.meta.m('counts');
                    if (counts != null && mounted) setState(() => _counts = counts);
                    return res;
                  },
                  empty: EmptyView(
                    icon: Icons.inventory_2_outlined,
                    title: context.t(_q.isEmpty ? 'orders.empty' : 'orders.noMatch'),
                    subtitle: _q.isEmpty ? context.t('orders.emptyHint') : null,
                  ),
                  itemBuilder: (context, order) => _OrderTile(order: order, onChanged: () => setState(() => _gen++)),
                ),
              ),
            ]),
    );
  }
}

class _OrderTile extends StatelessWidget {
  const _OrderTile({required this.order, required this.onChanged});

  final Json order;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 10),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        InkWell(
          onTap: () => Navigator.of(context).pushNamed(Routes.order, arguments: {'number': order.str('number')}),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(context.t('orders.number', {'number': order.str('number')}), style: NawyText.bodyMedium)),
              Pill.status(context, order.s('status')),
            ]),
            Row(children: [
              Expanded(child: Text(shortDate(context, order.s('placed_at')), style: NawyText.caption.copyWith(color: NawyColors.textSecondary))),
              MoneyText(order.m('totals')?['total'], style: NawyText.h4),
            ]),
            if (order.s('payment_status') == 'pending' && order.s('payment_method') != 'cod')
              Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('orders.paymentPending'), style: NawyText.caption.copyWith(color: NawyColors.textDanger))),
            if (isUnderReview(order.s('payment_status')))
              Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('payment.underReview'), key: const ValueKey('order-under-review'), style: NawyText.caption.copyWith(color: NawyColors.textBrand))),
            if (order.s('status') == 'disputed')
              Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('orders.disputeOpen'), key: const ValueKey('order-disputed'), style: NawyText.caption.copyWith(color: NawyColors.textBrand))),
          ]),
        ),
        for (final shipment in order.l('shipments')) ...[
          const Divider(height: 18),
          Row(children: [
            Expanded(
              child: Text(
                [context.t('orders.shipment', {'number': shipment.str('number')}), if (shipment.s('seller') != null) shipment.s('seller')!].join(' · '),
                style: NawyText.caption.copyWith(color: NawyColors.textSecondary),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            Pill.status(context, shipment.s('status')),
          ]),
          const SizedBox(height: 6),
          Row(children: [
            for (final item in shipment.l('items').take(4))
              Padding(padding: const EdgeInsetsDirectional.only(end: 6), child: SizedBox(width: 44, height: 44, child: NetImage(item.m('image')?.s('url'), radius: NawyRadius.sm))),
            if (shipment.l('items').length > 4) Text('+${shipment.l('items').length - 4}', style: NawyText.label),
          ]),
          if (shipment.s('delivered_at') != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(context.t('orders.deliveredOn', {'date': shortDate(context, shipment.s('delivered_at'))}), style: NawyText.caption.copyWith(color: NawyColors.textSuccess)),
            ),
          ShipmentActions(order: order, shipment: shipment, onChanged: onChanged),
        ],
      ]),
    );
  }
}

/// The buttons `actions` asks for on one shipment (QC-026):
/// Track · Invoice · Cancel · Return · Review · Buy again · Dispute.
class ShipmentActions extends StatelessWidget {
  const ShipmentActions({super.key, required this.order, required this.shipment, required this.onChanged, this.showTrack = true});

  final Json order;
  final Json shipment;
  final VoidCallback onChanged;
  final bool showTrack;

  @override
  Widget build(BuildContext context) {
    final actions = shipment.m('actions') ?? const {};
    final number = shipment.str('number');
    final orderNumber = order.str('number');
    Widget button(String key, String label, VoidCallback onTap, {bool primary = false}) => Padding(
          padding: const EdgeInsetsDirectional.only(end: 6, top: 6),
          child: primary
              ? FilledButton(key: ValueKey('ship-$key-$number'), style: _compact, onPressed: onTap, child: Text(label))
              : OutlinedButton(key: ValueKey('ship-$key-$number'), style: _compact, onPressed: onTap, child: Text(label)),
        );
    final buttons = <Widget>[
      if (showTrack && actions.b('track')) button('track', context.t('orders.action.track'), () => Navigator.of(context).pushNamed(Routes.order, arguments: {'number': orderNumber})),
      if (actions.b('invoice')) button('invoice', context.t('orders.action.invoice'), () => openInvoice(context, orderNumber)),
      if (actions.b('cancel'))
        button('cancel', context.t('orders.cancelShipment'), () async {
          if (await cancelShipment(context, orderNumber, number)) onChanged();
        }),
      if (actions.b('return'))
        button('return', context.t('returns.start'), () => Navigator.of(context).pushNamed(Routes.returnNew, arguments: {'order': order, 'shipment': number})),
      if (actions.b('review'))
        button('review', context.t('orders.action.review'), () {
          final items = shipment.l('items');
          if (items.length == 1) {
            Navigator.of(context).pushNamed(Routes.writeReview, arguments: {'order_item_id': items.first.i('id')});
          } else {
            Navigator.of(context).pushNamed(Routes.order, arguments: {'number': orderNumber});
          }
        }),
      if (actions.b('buy_again')) button('buy-again', context.t('orders.action.buyAgain'), () => buyAgain(context, shipment), primary: true),
      if (actions.b('dispute'))
        button('dispute', context.t('orders.action.dispute'), () async {
          if (await openDispute(context, number)) onChanged();
        }),
    ];
    if (buttons.isEmpty) return const SizedBox.shrink();
    return Wrap(children: buttons);
  }

  static final _compact = ButtonStyle(
    visualDensity: VisualDensity.compact,
    padding: const WidgetStatePropertyAll(EdgeInsets.symmetric(horizontal: 12)),
    minimumSize: const WidgetStatePropertyAll(Size(0, 34)),
  );
}

/// G-A6: the printable HTML invoice in the system browser. The browser has
/// no bearer token, so the app asks for a signed 30-minute link first
/// (`POST orders/{n}/invoice-link`).
Future<void> openInvoice(BuildContext context, String orderNumber) async {
  final res = await guarded(context, () => AppScope.read(context).api.post('orders/${enc(orderNumber)}/invoice-link'));
  final url = res == null ? null : asJson(res.data).s('url');
  if (url == null || !context.mounted) return;
  final opened = await PlatformBridge.openUrl(url);
  if (!opened && context.mounted) showToast(context, context.t('orders.invoiceNotOpened'), error: true);
}

/// "Buy again" (G-A4): every item of the shipment back into the cart
/// (`PUT cart/items` with its `listing_id`), then the cart.
Future<int> buyAgain(BuildContext context, Json shipment) async {
  final app = AppScope.read(context);
  var added = 0;
  ApiException? failure;
  for (final item in shipment.l('items')) {
    final listing = item.i('listing_id');
    if (listing == null) continue;
    try {
      await app.cart.add(listing, quantity: item.i('quantity') ?? 1);
      added++;
    } on ApiException catch (e) {
      failure ??= e;
    }
  }
  if (!context.mounted) return added;
  if (added > 0) {
    showToast(context, context.t('orders.addedAgain', {'count': added}));
    app.tabRequest.value = 'cart';
    Navigator.of(context).popUntil((route) => route.settings.name == Routes.shell || route.isFirst);
  } else {
    showToast(context, failure == null ? context.t('orders.nothingToAdd') : errorMessage(context, failure), error: true);
  }
  return added;
}

/// QA-041: cancel with a reason code from `orders/cancel-reasons`; `other`
/// needs a note. True when the shipment was cancelled.
Future<bool> cancelShipment(BuildContext context, String orderNumber, String shipmentNumber) async {
  final api = AppScope.read(context).api;
  final picked = await showNawySheet<(String, String?)>(
    context,
    title: context.t('orders.cancelTitle'),
    builder: (ctx) => _CancelReasonForm(load: () => api.getList('orders/cancel-reasons').then((r) => r.data)),
  );
  if (picked == null || !context.mounted) return false;
  final res = await guarded(
    context,
    () => api.post('orders/${enc(orderNumber)}/shipments/${enc(shipmentNumber)}/cancel', body: {'reason': picked.$1, 'note': ?picked.$2}),
    success: context.t('orders.cancelled'),
  );
  return res != null;
}

class _CancelReasonForm extends StatefulWidget {
  const _CancelReasonForm({required this.load});

  final Future<List<Json>> Function() load;

  @override
  State<_CancelReasonForm> createState() => _CancelReasonFormState();
}

class _CancelReasonFormState extends State<_CancelReasonForm> {
  final _note = TextEditingController();
  String? _reason;
  bool _noteMissing = false;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AsyncBody<List<Json>>(
        load: widget.load,
        builder: (context, reasons, _) {
          final needsNote = reasons.any((r) => r.str('code') == _reason && r.b('needs_note'));
          return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(8, 0, 8, 16), children: [
            RadioGroup<String>(
              groupValue: _reason,
              onChanged: (v) => setState(() => _reason = v),
              child: Column(children: [
                for (final r in reasons)
                  RadioListTile<String>(key: ValueKey('cancel-reason-${r.str('code')}'), value: r.str('code'), title: Text(r.str('label', r.str('code')))),
              ]),
            ),
            if (needsNote)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                child: TextField(
                  key: const ValueKey('cancel-note'),
                  controller: _note,
                  maxLength: 255,
                  decoration: InputDecoration(labelText: context.t('orders.cancelNote'), errorText: _noteMissing ? context.t('orders.cancelNoteRequired') : null),
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 8, 8, 0),
              child: FilledButton(
                key: const ValueKey('cancel-confirm'),
                onPressed: _reason == null
                    ? null
                    : () {
                        final note = _note.text.trim();
                        if (needsNote && note.isEmpty) {
                          setState(() => _noteMissing = true);
                          return;
                        }
                        Navigator.of(context).pop((_reason!, needsNote ? note : null));
                      },
                child: Text(context.t('orders.cancelShipment')),
              ),
            ),
          ]);
        },
      );
}

/// Open a dispute on a shipment (`POST disputes {shipment, reason, message}`).
Future<bool> openDispute(BuildContext context, String shipmentNumber) async {
  final api = AppScope.read(context).api;
  final sent = await showNawySheet<bool>(
    context,
    title: context.t('orders.disputeTitle'),
    builder: (ctx) => _DisputeForm(onSubmit: (reason, message) => api.post('disputes', body: {'shipment': shipmentNumber, 'reason': reason, 'message': message})),
  );
  if (sent == true && context.mounted) showToast(context, context.t('orders.disputeSent'));
  return sent == true;
}

class _DisputeForm extends StatefulWidget {
  const _DisputeForm({required this.onSubmit});

  final Future<Object?> Function(String reason, String message) onSubmit;

  static const reasons = ['not_received', 'item_damaged', 'not_as_described', 'missing_parts', 'other'];

  @override
  State<_DisputeForm> createState() => _DisputeFormState();
}

class _DisputeFormState extends State<_DisputeForm> {
  final _message = TextEditingController();
  String _reason = _DisputeForm.reasons.first;
  String? _error;

  @override
  void dispose() {
    _message.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), children: [
        DropdownButtonFormField<String>(
          isExpanded: true,
          initialValue: _reason,
          decoration: InputDecoration(labelText: context.t('orders.disputeReason')),
          items: [for (final r in _DisputeForm.reasons) DropdownMenuItem(value: r, child: Text(context.t('orders.disputeReason.$r')))],
          onChanged: (v) => setState(() => _reason = v ?? _reason),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('dispute-message'),
          controller: _message,
          minLines: 3,
          maxLines: 6,
          maxLength: 2000,
          decoration: InputDecoration(labelText: context.t('orders.disputeMessage'), errorText: _error),
        ),
        BusyButton(
          label: context.t('orders.disputeSend'),
          onPressed: () async {
            final text = _message.text.trim();
            if (text.length < 10) {
              setState(() => _error = context.t('orders.disputeTooShort'));
              return;
            }
            try {
              await widget.onSubmit(_reason, text);
              if (context.mounted) Navigator.of(context).pop(true);
            } catch (e) {
              if (context.mounted) setState(() => _error = errorMessage(context, e));
            }
          },
        ),
      ]);
}

/// Shipment progress steps (SubOrderStatus).
const shipmentSteps = ['placed', 'confirmed', 'packed', 'shipped', 'out_for_delivery', 'delivered'];

int shipmentStepIndex(String? status) {
  final s = status == 'processing' ? 'confirmed' : (status == 'completed' ? 'delivered' : status);
  return shipmentSteps.indexOf(s ?? '');
}

/// Figma "31 Order detail" + "32 Track": shipments with progress, the large
/// delivery code the customer reads aloud to the agent (ADR 15), items,
/// totals, address; pay now, cancel shipment, return, review.
class OrderDetailScreen extends StatefulWidget {
  const OrderDetailScreen({super.key, required this.number});

  final String number;

  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  int _gen = 0;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('orders.number', {'number': widget.number}))),
        body: AsyncBody<Json>(
          key: ValueKey(_gen),
          load: () => AppScope.read(context).api.getObject('orders/${enc(widget.number)}'),
          builder: (context, order, reload) {
            final locale = context.l10n.locale;
            final totals = order.m('totals') ?? const {};
            final address = order.m('shipping_address');
            final payment = order.m('payment');
            final step = paymentStep(payment);
            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
                SectionCard(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [Expanded(child: Text(context.t('orders.placedOn', {'date': shortDate(context, order.s('placed_at'), time: true)}), style: NawyText.bodySm)), Pill.status(context, order.s('status'))]),
                    const SizedBox(height: 4),
                    Text('${context.t('checkout.payment')}: ${context.l10n.status(order.s('payment_method'))} · ${context.l10n.status(order.s('payment_status'))}', style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                    if (isUnderReview(order.s('payment_status')))
                      _note(context, 'payment.underReview', 'payment.underReviewHint', key: const ValueKey('order-detail-under-review')),
                    // `disputed` is an overlay while a dispute is open; the
                    // order returns to its own status once it is settled.
                    if (order.s('status') == 'disputed')
                      _note(context, 'orders.disputeOpen', 'orders.disputeOpenHint', key: const ValueKey('order-detail-disputed')),
                    if (!isUnderReview(order.s('payment_status')) && (step == PaymentStep.redirect || step == PaymentStep.retry || step == PaymentStep.wait))
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: FilledButton(
                          key: const ValueKey('order-pay-now'),
                          onPressed: () => continuePayment(context, payment: payment, orderNumber: order.str('number')),
                          child: Text(context.t(step == PaymentStep.retry ? 'payment.tryAnother' : 'orders.payNow')),
                        ),
                      ),
                  ]),
                ),
                for (final shipment in order.l('shipments')) _shipment(context, order, shipment),
                if (address != null)
                  SectionCard(
                    title: context.t('checkout.deliverTo'),
                    child: Text([address.s('name'), address.s('phone'), [address.s('building'), address.s('street'), address.s('area'), address.s('city')].whereType<String>().join(', ')].whereType<String>().join('\n'),
                        style: NawyText.body),
                  ),
                SectionCard(
                  title: context.t('cart.summary'),
                  child: Column(children: [
                    InfoRow(context.t('cart.subtotal'), formatMoney(totals['subtotal'], locale: locale)),
                    if (!(Money.fromJson(totals['discount'])?.isZero ?? true)) InfoRow(context.t('cart.discount'), '-${formatMoney(totals['discount'], locale: locale)}'),
                    InfoRow(context.t('cart.shipping'), formatMoney(totals['shipping'], locale: locale)),
                    if (!(Money.fromJson(totals['cod_fee'])?.isZero ?? true)) InfoRow(context.t('cart.codFee'), formatMoney(totals['cod_fee'], locale: locale)),
                    if (!(Money.fromJson(totals['warranty'])?.isZero ?? true)) InfoRow(context.t('cart.protection'), formatMoney(totals['warranty'], locale: locale)),
                    const Divider(height: 16),
                    InfoRow(context.t('cart.total'), formatMoney(totals['total'], locale: locale), bold: true),
                    InfoRow(context.t('cart.vatIncluded', {'rate': ''}), formatMoney(totals['tax_included'], locale: locale)),
                  ]),
                ),
                if (order.b('invoice_available'))
                  ListTile(
                    key: const ValueKey('order-invoice'),
                    leading: const Icon(Icons.receipt_long_outlined),
                    title: Text(context.t('orders.action.invoice')),
                    trailing: const Icon(Icons.open_in_new_rounded),
                    onTap: () => openInvoice(context, order.str('number')),
                  ),
                ListTile(
                  key: const ValueKey('order-get-help'),
                  leading: const Icon(Icons.support_agent_rounded),
                  title: Text(context.t('orders.getHelp')),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () => Navigator.of(context).pushNamed(Routes.supportNew, arguments: {'order': order.str('number')}),
                ),
              ]),
            );
          },
        ),
      );

  Widget _shipment(BuildContext context, Json order, Json shipment) {
    final status = shipment.s('status');
    final stepIndex = shipmentStepIndex(status);
    final code = shipment.s('delivery_code');
    final delivered = status == 'delivered' || status == 'completed';
    final cancellable = status == 'placed' || status == 'confirmed';
    return SectionCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(context.t('orders.shipment', {'number': shipment.str('number')}), style: NawyText.h4)),
          Pill.status(context, status),
        ]),
        if (shipment.s('seller') != null) Text(context.t('product.soldBy', {'seller': shipment.s('seller')}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
        if (shipment.m('delivery')?.s('by') != null && !delivered)
          Text(context.t('product.getItBy', {'date': shortDate(context, shipment.m('delivery')!.s('by'))}), style: NawyText.caption.copyWith(color: NawyColors.textSuccess)),
        if (code != null && code.isNotEmpty) DeliveryCodeCard(code: code),
        if (stepIndex >= 0) ...[
          const SizedBox(height: 12),
          for (final (i, s) in shipmentSteps.indexed)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(children: [
                Icon(i <= stepIndex ? Icons.circle : Icons.circle_outlined, size: 12, color: i <= stepIndex ? NawyColors.bgSuccess : NawyColors.borderDefault),
                const SizedBox(width: 10),
                Text(context.l10n.status(s), style: NawyText.body.copyWith(color: i <= stepIndex ? NawyColors.textPrimary : NawyColors.textMuted)),
              ]),
            ),
        ],
        const Divider(height: 20),
        for (final item in shipment.l('items'))
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(children: [
              SizedBox(width: 56, height: 56, child: NetImage(item.m('image')?.s('url'), radius: NawyRadius.sm)),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(item.str('title'), maxLines: 2, style: NawyText.bodySm),
                  Text('${item.i('quantity')} × ${formatMoney(item['unit_price'], locale: context.l10n.locale)}', style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                ]),
              ),
              if (delivered)
                TextButton(
                  onPressed: () => Navigator.of(context).pushNamed(Routes.writeReview, arguments: {'order_item_id': item.i('id')}),
                  child: Text(context.t('reviews.rate')),
                ),
            ]),
          ),
        // Wave 5a `actions`; older payloads without it keep the status rules.
        if (shipment.m('actions') != null)
          ShipmentActions(order: order, shipment: shipment, showTrack: false, onChanged: () => setState(() => _gen++))
        else
          Wrap(spacing: 8, children: [
            if (cancellable)
              OutlinedButton(
                onPressed: () async {
                  if (await cancelShipment(context, order.str('number'), shipment.str('number'))) setState(() => _gen++);
                },
                child: Text(context.t('orders.cancelShipment')),
              ),
            if (delivered)
              OutlinedButton(
                onPressed: () => Navigator.of(context).pushNamed(Routes.returnNew, arguments: {'order': order, 'shipment': shipment.str('number')}),
                child: Text(context.t('returns.start')),
              ),
          ]),
      ]),
    );
  }
}

/// The 4-digit delivery code, large, for the customer to read aloud to the
/// agent, who checks it against their screen and taps "Codes match" (ADR 15).
/// The customer never types it and the agent never asks for it to be typed.
class DeliveryCodeCard extends StatelessWidget {
  const DeliveryCodeCard({super.key, required this.code});

  final String code;

  @override
  Widget build(BuildContext context) => Semantics(
        label: '${context.t('orders.deliveryCode')}: ${code.split('').join(' ')}',
        child: Container(
          key: const ValueKey('delivery-code'),
          width: double.infinity,
          margin: const EdgeInsets.only(top: 12),
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(color: NawyColors.bgBrandSubtle, borderRadius: BorderRadius.circular(NawyRadius.card), border: Border.all(color: NawyColors.bgBrand)),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(context.t('orders.deliveryCode'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            const SizedBox(height: 6),
            ExcludeSemantics(
              child: Text(
                code.split('').join(' '),
                textDirection: TextDirection.ltr,
                style: NawyText.display.copyWith(fontSize: 48, letterSpacing: 4, fontFeatures: const [FontFeature.tabularFigures()]),
              ),
            ),
            const SizedBox(height: 6),
            Text(context.t('orders.deliveryCodeHint'), style: NawyText.bodySm),
          ]),
        ),
      );
}

Widget _note(BuildContext context, String title, String hint, {Key? key}) => Container(
      key: key,
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: NawyColors.bgWarningSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Icon(Icons.info_outline_rounded, size: 18, color: NawyColors.textBrand),
        const SizedBox(width: 8),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(context.t(title), style: NawyText.bodyMedium),
            Text(context.t(hint), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
          ]),
        ),
      ]),
    );
