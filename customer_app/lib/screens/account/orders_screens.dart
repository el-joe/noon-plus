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
import '../../ui/widgets/states.dart';
import '../auth/sign_in_screen.dart';
import '../checkout/payment_flow.dart';

/// Figma "30 Orders" (`GET orders`, paged).
class OrdersScreen extends StatelessWidget {
  const OrdersScreen({super.key, this.asTab = false});

  final bool asTab;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('account.orders')), automaticallyImplyLeading: !asTab),
      body: !app.signedIn
          ? const SignInPrompt()
          : PagedList(
              load: (page) => app.api.getList('orders', query: {'page': page}),
              empty: EmptyView(icon: Icons.inventory_2_outlined, title: context.t('orders.empty'), subtitle: context.t('orders.emptyHint')),
              itemBuilder: (context, order) => _OrderTile(order: order),
            ),
    );
  }
}

class _OrderTile extends StatelessWidget {
  const _OrderTile({required this.order});

  final Json order;

  @override
  Widget build(BuildContext context) {
    final items = [for (final s in order.l('shipments')) ...s.l('items')];
    return InkWell(
      onTap: () => Navigator.of(context).pushNamed(Routes.order, arguments: {'number': order.str('number')}),
      child: SectionCard(
        margin: const EdgeInsets.fromLTRB(16, 0, 16, 10),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(context.t('orders.number', {'number': order.str('number')}), style: NawyText.bodyMedium)),
            Pill.status(context, order.s('status')),
          ]),
          Text(shortDate(context, order.s('placed_at')), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
          const SizedBox(height: 8),
          Row(children: [
            for (final item in items.take(4))
              Padding(padding: const EdgeInsetsDirectional.only(end: 6), child: SizedBox(width: 48, height: 48, child: NetImage(item.m('image')?.s('url'), radius: NawyRadius.sm))),
            if (items.length > 4) Text('+${items.length - 4}', style: NawyText.label),
            const Spacer(),
            MoneyText(order.m('totals')?['total'], style: NawyText.h4),
          ]),
          if (order.s('payment_status') == 'pending' && order.s('payment_method') != 'cod')
            Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('orders.paymentPending'), style: NawyText.caption.copyWith(color: NawyColors.textDanger))),
        ]),
      ),
    );
  }
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

  Future<void> _cancel(Json shipment) async {
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.t('orders.cancelTitle')),
        content: TextField(controller: reason, decoration: InputDecoration(labelText: ctx.t('orders.cancelReason'))),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(ctx.t('common.back'))),
          TextButton(onPressed: () => Navigator.of(ctx).pop(true), child: Text(ctx.t('orders.cancelShipment'))),
        ],
      ),
    );
    final text = reason.text.trim();
    reason.dispose();
    if (ok != true || !mounted) return;
    final res = await guarded(
      context,
      () => AppScope.read(context).api.post('orders/${enc(widget.number)}/shipments/${enc(shipment.str('number'))}/cancel', body: {'reason': text.isEmpty ? null : text}),
      success: context.t('orders.cancelled'),
    );
    if (res != null && mounted) setState(() => _gen++);
  }

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
                    if (step == PaymentStep.redirect || step == PaymentStep.retry || step == PaymentStep.wait)
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
                ListTile(
                  leading: const Icon(Icons.support_agent_rounded),
                  title: Text(context.t('orders.getHelp')),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () => Navigator.of(context).pushNamed(Routes.help),
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
        Wrap(spacing: 8, children: [
          if (cancellable) OutlinedButton(onPressed: () => _cancel(shipment), child: Text(context.t('orders.cancelShipment'))),
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
