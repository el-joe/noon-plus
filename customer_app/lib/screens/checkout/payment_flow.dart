import 'dart:async';

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
import '../../ui/widgets/states.dart';

/// What the app should do with a payment (`PaymentResource.next_action`).
enum PaymentStep { redirect, wait, retry, done }

PaymentStep paymentStep(Json? payment) => switch (payment?.m('next_action')?.s('type')) {
      'redirect' => PaymentStep.redirect,
      'wait' => PaymentStep.wait,
      'retry' => PaymentStep.retry,
      _ => PaymentStep.done,
    };

/// Payment states that count as paid.
bool isPaid(String? status) => const {'authorized', 'captured', 'paid', 'partially_refunded'}.contains(status);

/// Run the gateway step for an order or a non-order payment (gift card,
/// Nawy One): open the hosted page in the system browser, then the waiting
/// screen verifies when the app comes back.
Future<void> continuePayment(BuildContext context, {required Json? payment, String? orderNumber, String? kind}) async {
  final step = paymentStep(payment);
  final nav = Navigator.of(context);
  if (step == PaymentStep.done) {
    if (orderNumber != null) nav.pushNamedAndRemoveUntil(Routes.orderSuccess, (r) => r.settings.name == Routes.shell || r.isFirst, arguments: {'number': orderNumber});
    return;
  }
  if (step == PaymentStep.redirect) {
    final url = payment?.m('next_action')?.s('url');
    if (url != null) await PlatformBridge.openUrl(url);
  }
  if (!context.mounted) return;
  nav.pushNamed(Routes.paymentWait, arguments: {
    'order': orderNumber,
    'reference': payment?.s('reference'),
    'kind': kind ?? 'order',
    'url': payment?.m('next_action')?.s('url'),
  });
}

/// Figma "26 3DS" / "27 Payment failed": waiting for the gateway; verifies on
/// resume (`POST orders/{n}/payments/verify` or `POST payments/{ref}/verify`)
/// and offers another method after a failure.
class PaymentWaitScreen extends StatefulWidget {
  const PaymentWaitScreen({super.key, this.order, this.reference, this.kind = 'order', this.url});

  final String? order;
  final String? reference;
  final String kind;
  final String? url;

  @override
  State<PaymentWaitScreen> createState() => _PaymentWaitScreenState();
}

class _PaymentWaitScreenState extends State<PaymentWaitScreen> with WidgetsBindingObserver {
  Json? _payment;
  Json? _subject;
  bool _checking = false;
  String? _error;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _poll = Timer.periodic(const Duration(seconds: 6), (_) => _verify(silent: true));
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _poll?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _verify();
  }

  Future<void> _verify({bool silent = false}) async {
    if (_checking) return;
    setState(() => _checking = !silent);
    try {
      final api = AppScope.read(context).api;
      if (widget.order != null) {
        final order = asJson((await api.post('orders/${enc(widget.order!)}/payments/verify')).data);
        _payment = order.m('payment');
        if (isPaid(order.s('payment_status')) || isPaid(_payment?.s('status')) || paymentStep(_payment) == PaymentStep.done) {
          _poll?.cancel();
          if (mounted) Navigator.of(context).pushNamedAndRemoveUntil(Routes.orderSuccess, (r) => r.settings.name == Routes.shell || r.isFirst, arguments: {'number': widget.order});
          return;
        }
      } else if (widget.reference != null) {
        final res = asJson((await api.post('payments/${enc(widget.reference!)}/verify')).data);
        _payment = res.m('payment');
        _subject = res.m('subject');
        if (isPaid(_payment?.s('status'))) _poll?.cancel();
      }
      _error = null;
    } on ApiException catch (e) {
      if (!silent) _error = e.message;
    } finally {
      if (mounted) setState(() => _checking = false);
    }
  }

  Future<void> _retry() async {
    final api = AppScope.read(context).api;
    final methods = await guarded(context, () => api.getList('checkout/payment-methods', query: {'amount': _payment?.m('amount')?.s('amount')}).then((r) => r.data));
    if (methods == null || !mounted) return;
    final code = await showNawySheet<String>(
      context,
      title: context.t('checkout.payWith'),
      builder: (ctx) => ListView(shrinkWrap: true, children: [
        for (final m in methods.where((m) => m.s('type') != 'cod' || widget.order != null))
          ListTile(title: Text(m.str('name')), subtitle: m.s('description') == null ? null : Text(m.str('description')), onTap: () => Navigator.of(ctx).pop(m.str('code'))),
      ]),
    );
    if (code == null || widget.order == null || !mounted) return;
    final order = await guarded(context, () => api.post('orders/${enc(widget.order!)}/payments', body: {'payment_method': code}).then((r) => asJson(r.data)));
    if (order == null || !mounted) return;
    setState(() => _payment = order.m('payment'));
    final step = paymentStep(_payment);
    if (step == PaymentStep.redirect) {
      await PlatformBridge.openUrl(_payment!.m('next_action')!.str('url'));
    } else if (step == PaymentStep.done) {
      await _verify();
    }
  }

  @override
  Widget build(BuildContext context) {
    final failed = paymentStep(_payment) == PaymentStep.retry || const {'failed', 'expired'}.contains(_payment?.s('status'));
    final paid = isPaid(_payment?.s('status'));
    final url = _payment?.m('next_action')?.s('url') ?? widget.url;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('payment.title'))),
      body: ListView(padding: const EdgeInsets.all(24), children: [
        const SizedBox(height: 24),
        Icon(
          paid ? Icons.check_circle_rounded : (failed ? Icons.error_rounded : Icons.lock_clock_rounded),
          size: 72,
          color: paid ? NawyColors.bgSuccess : (failed ? NawyColors.bgDanger : NawyColors.textBrand),
        ),
        const SizedBox(height: 16),
        Text(context.t(paid ? 'payment.paid' : (failed ? 'payment.failed' : 'payment.waiting')), textAlign: TextAlign.center, style: NawyText.h3),
        const SizedBox(height: 8),
        Text(
          context.t(paid ? 'payment.paidHint' : (failed ? 'payment.failedHint' : 'payment.waitingHint')),
          textAlign: TextAlign.center,
          style: NawyText.body.copyWith(color: NawyColors.textSecondary),
        ),
        if (_payment?.m('failure')?.s('message') != null)
          Padding(padding: const EdgeInsets.only(top: 8), child: Text(_payment!.m('failure')!.str('message'), textAlign: TextAlign.center, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
        if (_subject != null && paid) ...[
          const SizedBox(height: 12),
          SectionCard(
            margin: EdgeInsets.zero,
            child: Column(children: [
              if (_subject!.s('number') != null) InfoRow(context.t('payment.reference'), _subject!.str('number')),
              if (_subject!.s('masked_code') != null) InfoRow(context.t('giftCards.code'), _subject!.str('masked_code')),
              if (_subject!['amount'] != null) InfoRow(context.t('cart.total'), formatMoney(_subject!['amount'], locale: context.l10n.locale)),
            ]),
          ),
        ],
        if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, textAlign: TextAlign.center, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
        const SizedBox(height: 24),
        if (paid)
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.t('common.done')))
        else if (failed && widget.order != null)
          BusyButton(label: context.t('payment.tryAnother'), onPressed: _retry)
        else ...[
          BusyButton(key: const ValueKey('payment-verify'), label: context.t('payment.check'), onPressed: () => _verify()),
          if (url != null)
            TextButton(onPressed: () => PlatformBridge.openUrl(url), child: Text(context.t('payment.openAgain'))),
        ],
        if (widget.order != null && !paid)
          TextButton(
            onPressed: () => Navigator.of(context).pushNamedAndRemoveUntil(Routes.order, (r) => r.settings.name == Routes.shell || r.isFirst, arguments: {'number': widget.order}),
            child: Text(context.t('payment.viewOrder')),
          ),
      ]),
    );
  }
}
