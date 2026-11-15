import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';
import '../checkout/checkout_screen.dart';
import '../checkout/payment_flow.dart';

/// Pick a gateway payment method for a non-order payment (no COD).
Future<String?> pickPaymentMethod(BuildContext context, String amount) async {
  final methods = await guarded(context, () => AppScope.read(context).api.getList('checkout/payment-methods', query: {'amount': amount}).then((r) => r.data));
  if (methods == null || !context.mounted) return null;
  return showNawySheet<String>(
    context,
    title: context.t('checkout.payWith'),
    builder: (ctx) => ListView(shrinkWrap: true, children: [
      for (final m in methods.where((m) => m.s('type') != 'cod' && m.s('type') != 'wallet'))
        ListTile(title: Text(m.str('name')), subtitle: m.s('description') == null ? null : Text(m.str('description')), onTap: () => Navigator.of(ctx).pop(m.str('code'))),
    ]),
  );
}

/// Figma "53 Gift cards": my gift cards, buy (design, amount, recipient) and
/// redeem (`GET/POST gift-cards`, `gift-cards/designs`, `gift-cards/redeem`).
class GiftCardsScreen extends StatefulWidget {
  const GiftCardsScreen({super.key});

  @override
  State<GiftCardsScreen> createState() => _GiftCardsScreenState();
}

class _GiftCardsScreenState extends State<GiftCardsScreen> {
  int _gen = 0;

  Future<void> _redeem() async {
    final code = TextEditingController();
    await showNawySheet<void>(
      context,
      title: context.t('giftCards.redeem'),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          TextField(controller: code, decoration: InputDecoration(labelText: ctx.t('giftCards.code'))),
          const SizedBox(height: 12),
          BusyButton(
            label: ctx.t('giftCards.redeem'),
            onPressed: () async {
              final res = await guarded(ctx, () => AppScope.read(ctx).api.post('gift-cards/redeem', body: {'code': code.text.trim()}), success: ctx.t('giftCards.redeemed'));
              if (res != null && ctx.mounted) Navigator.of(ctx).pop();
            },
          ),
        ]),
      ),
    );
    code.dispose();
  }

  Future<void> _buy() async {
    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const BuyGiftCardScreen()));
    if (mounted) setState(() => _gen++);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('giftCards.title')), actions: [TextButton(onPressed: _redeem, child: Text(context.t('giftCards.redeem')))]),
        floatingActionButton: FloatingActionButton.extended(onPressed: _buy, icon: const Icon(Icons.card_giftcard_rounded), label: Text(context.t('giftCards.buy'))),
        body: AsyncBody<List<Json>>(
          key: ValueKey(_gen),
          load: () => AppScope.read(context).api.getList('gift-cards').then((r) => r.data),
          isEmpty: (l) => l.isEmpty,
          empty: EmptyView(icon: Icons.card_giftcard_rounded, title: context.t('giftCards.empty'), actionLabel: context.t('giftCards.buy'), onAction: _buy),
          builder: (context, cards, _) => ListView(padding: const EdgeInsets.only(top: 12, bottom: 96), children: [
            for (final c in cards)
              SectionCard(
                child: Row(children: [
                  Container(
                    width: 64,
                    height: 44,
                    decoration: BoxDecoration(color: NawyColors.bgBrand, borderRadius: BorderRadius.circular(NawyRadius.md)),
                    child: const Icon(Icons.card_giftcard_rounded),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(formatMoney(c['amount'], locale: context.l10n.locale), style: NawyText.h4),
                      Text('${c.str('masked_code')} · ${c.s('recipient_email') ?? ''}', style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                      Text(context.t('giftCards.expires', {'date': shortDate(context, c.s('expires_at'))}), style: NawyText.caption.copyWith(color: NawyColors.textMuted)),
                    ]),
                  ),
                  Pill.status(context, c.s('status')),
                ]),
              ),
          ]),
        ),
      );
}

class BuyGiftCardScreen extends StatefulWidget {
  const BuyGiftCardScreen({super.key});

  @override
  State<BuyGiftCardScreen> createState() => _BuyGiftCardScreenState();
}

class _BuyGiftCardScreenState extends State<BuyGiftCardScreen> {
  final _amount = TextEditingController(text: '100');
  final _email = TextEditingController();
  final _name = TextEditingController();
  final _message = TextEditingController();
  String _design = 'classic';
  Map<String, String> _errors = {};

  @override
  void dispose() {
    for (final c in [_amount, _email, _name, _message]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    final method = await pickPaymentMethod(context, _amount.text.trim());
    if (method == null || !mounted) return;
    try {
      final res = await AppScope.read(context).api.post('gift-cards', body: {
        'amount': _amount.text.trim(),
        'payment_method': method,
        'recipient_email': _email.text.trim(),
        'recipient_name': _name.text.trim().isEmpty ? null : _name.text.trim(),
        'message': _message.text.trim().isEmpty ? null : _message.text.trim(),
        'design': _design,
      });
      if (!mounted) return;
      await continuePayment(context, payment: asJson(res.data).m('payment'), kind: 'gift_card');
    } on ApiException catch (e) {
      setState(() => _errors = {...e.fieldErrors, if (e.fieldErrors.isEmpty) 'form': e.message});
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('giftCards.buy'))),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          OnceFuture<List<Json>>(
            load: () => AppScope.read(context).api.getList('gift-cards/designs').then((r) => r.data),
            builder: (context, snap) => Wrap(spacing: 8, children: [
              for (final d in snap.data ?? const <Json>[]) ChoiceChip(label: Text(d.str('name')), selected: _design == d.s('key'), onSelected: (_) => setState(() => _design = d.str('key'))),
            ]),
          ),
          const SizedBox(height: 12),
          TextField(controller: _amount, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: '${context.t('giftCards.amount')} (${AppScope.of(context).currency})', errorText: _errors['amount'])),
          const SizedBox(height: 12),
          TextField(controller: _email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: context.t('giftCards.recipientEmail'), errorText: _errors['recipient_email'])),
          const SizedBox(height: 12),
          TextField(controller: _name, decoration: InputDecoration(labelText: context.t('giftCards.recipientName'))),
          const SizedBox(height: 12),
          TextField(controller: _message, maxLines: 3, decoration: InputDecoration(labelText: context.t('giftCards.message'))),
          if (_errors['form'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_errors['form']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
          const SizedBox(height: 16),
          BusyButton(label: context.t('giftCards.pay'), onPressed: _submit),
        ]),
      );
}

/// Figma "36 Nawy One": plans (public), my membership, subscribe, cancel.
class MembershipScreen extends StatefulWidget {
  const MembershipScreen({super.key});

  @override
  State<MembershipScreen> createState() => _MembershipScreenState();
}

class _MembershipScreenState extends State<MembershipScreen> {
  int _gen = 0;
  String? _plan;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('membership.title'))),
      body: AsyncBody<(List<Json>, Json?)>(
        key: ValueKey(_gen),
        load: () async {
          final plans = (await app.api.getList('membership/plans')).data;
          Json? mine;
          if (app.signedIn) {
            try {
              mine = await app.api.getObject('membership');
            } catch (_) {}
          }
          return (plans, mine);
        },
        builder: (context, data, _) {
          final (plans, mine) = data;
          final member = mine?.b('member') ?? false;
          _plan ??= plans.isNotEmpty ? plans.first.s('code') : null;
          return ListView(padding: const EdgeInsets.all(16), children: [
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: NawyColors.bgInverse, borderRadius: BorderRadius.circular(NawyRadius.card)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t('membership.title'), style: NawyText.h2.copyWith(color: NawyColors.bgBrand)),
                const SizedBox(height: 6),
                for (final b in const ['freeDelivery', 'earlyDeals', 'fasterDelivery'])
                  Text('✓ ${context.t('membership.benefit.$b')}', style: NawyText.body.copyWith(color: NawyColors.textInverse)),
              ]),
            ),
            const SizedBox(height: 16),
            if (member) ...[
              SectionCard(
                margin: EdgeInsets.zero,
                child: Column(children: [
                  InfoRow(context.t('membership.status'), context.l10n.status(mine!.s('status'))),
                  InfoRow(context.t('membership.plan'), mine.str('plan')),
                  InfoRow(context.t('membership.renews'), shortDate(context, mine.s('current_period_end'))),
                ]),
              ),
              const SizedBox(height: 12),
              if (mine.b('auto_renew'))
                BusyButton(
                  outlined: true,
                  label: context.t('membership.cancel'),
                  onPressed: () async {
                    final ok = await guarded(context, () => app.api.post('membership/cancel'), success: context.t('membership.cancelled'));
                    if (ok != null && mounted) setState(() => _gen++);
                  },
                ),
            ] else ...[
              for (final p in plans)
                RadioListTileLite(
                  selected: _plan == p.s('code'),
                  title: '${p.str('name')} · ${formatMoney(p['price'], locale: context.l10n.locale)} / ${context.l10n.status(p.s('interval'))}',
                  subtitle: p.i('trial_days') != null && p.i('trial_days')! > 0 ? context.t('membership.trial', {'n': p.i('trial_days')}) : null,
                  onTap: () => setState(() => _plan = p.s('code')),
                ),
              const SizedBox(height: 12),
              BusyButton(
                label: context.t('membership.join'),
                onPressed: _plan == null
                    ? null
                    : () async {
                        if (!await requireSignIn(context) || !context.mounted) return;
                        final plan = plans.firstWhere((p) => p.s('code') == _plan);
                        final method = await pickPaymentMethod(context, plan.m('price')?.s('amount') ?? '0');
                        if (method == null || !context.mounted) return;
                        final res = await guarded(context, () => app.api.post('membership', body: {'plan': _plan, 'payment_method': method}));
                        if (res == null || !context.mounted) return;
                        final payment = asJson(res.data).m('payment');
                        if (payment != null) {
                          await continuePayment(context, payment: payment, kind: 'membership_invoice');
                        }
                        if (mounted) setState(() => _gen++);
                      },
              ),
            ],
          ]);
        },
      ),
    );
  }
}
