import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import 'payslips.dart' show localStamp;

/// The agent's wallet (`GET delivery/me/wallet`): available / reserved /
/// paid out, the withdrawal request and its history (cancel while pending).
/// Carrier agents' withdrawals are approved and paid by their carrier.
class WalletScreen extends StatefulWidget {
  const WalletScreen({super.key});

  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  final _key = GlobalKey<AsyncBodyState<Json>>();

  Future<void> _withdraw(Json wallet) async {
    final done = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => WithdrawScreen(wallet: wallet)));
    if (done == true) await _key.currentState?.reload();
  }

  Future<void> _cancel(Json w) async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(context.t('wallet.cancelTitle')),
        content: Text(context.t('wallet.cancelBody', {'amount': money(context, w['amount'])})),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(context.t('wallet.keep'))),
          FilledButton(key: const Key('withdrawal-cancel-confirm'), onPressed: () => Navigator.pop(context, true), child: Text(context.t('wallet.cancelRequest'))),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    final ok = await runAction(context, () => DeliveryApi(context.api).cancelWithdrawal(w.str('number')), success: context.t('wallet.cancelled'));
    if (ok != null) await _key.currentState?.reload();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('wallet.title'))),
        body: AsyncBody<Json>(
          key: _key,
          load: () => DeliveryApi(context.api).wallet(),
          builder: (context, w, _) {
            final carrier = w.str('paid_by') == 'carrier';
            final withdrawals = w.l('withdrawals');
            final available = Money.fromJson(w['available']);
            final minimum = Money.fromJson(w['minimum']);
            final belowMinimum = available != null && minimum != null && available.currency == minimum.currency && available.compareTo(minimum) < 0;
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              KpiGrid(tiles: [
                KpiTile(key: const Key('wallet-available'), value: money(context, w['available']), label: context.t('wallet.available'), highlight: true),
                KpiTile(key: const Key('wallet-reserved'), value: money(context, w['reserved']), label: context.t('wallet.reserved')),
                KpiTile(key: const Key('wallet-paid-out'), value: money(context, w['paid_out']), label: context.t('wallet.paidOut')),
              ]),
              const SizedBox(height: 8),
              Text(context.t('wallet.reservedHint'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
              if (carrier) ...[
                const SizedBox(height: 8),
                Card(
                  key: const Key('wallet-carrier'),
                  color: NawyColors.bgInfoSubtle,
                  child: ListTile(
                    leading: const Icon(Icons.business_outlined),
                    title: Text(context.t('wallet.byCarrier', {'carrier': w.s('carrier') ?? context.t('wallet.yourCarrier')})),
                  ),
                ),
              ],
              const SizedBox(height: 12),
              FilledButton.icon(
                key: const Key('wallet-withdraw'),
                onPressed: w.b('can_withdraw') ? () => _withdraw(w) : null,
                icon: const Icon(Icons.account_balance_outlined),
                label: Text(context.t('wallet.withdraw')),
              ),
              if (!w.b('can_withdraw'))
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    key: const Key('wallet-cannot'),
                    belowMinimum ? context.t('wallet.belowMinimum', {'minimum': money(context, w['minimum'])}) : context.t('wallet.cannotNow'),
                    style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
                  ),
                )
              else if (w['minimum'] != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(context.t('wallet.minimum', {'minimum': money(context, w['minimum'])}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                ),
              SectionTitle(context.t('wallet.history')),
              if (withdrawals.isEmpty) Text(context.t('wallet.noWithdrawals'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
              for (final x in withdrawals) _WithdrawalCard(withdrawal: x, onCancel: () => _cancel(x)),
            ]);
          },
        ),
      );
}

class _WithdrawalCard extends StatelessWidget {
  const _WithdrawalCard({required this.withdrawal, required this.onCancel});

  final Json withdrawal;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    final w = withdrawal;
    final status = w.str('status');
    final payout = w.m('payout') ?? const {};
    final steps = [
      if (w.s('requested_at') != null) context.t('wallet.requestedAt', {'date': localStamp(w.s('requested_at'))}),
      if (w.s('approved_at') != null) context.t('wallet.approvedAt', {'date': localStamp(w.s('approved_at'))}),
      if (w.s('paid_at') != null) context.t('wallet.paidAt', {'date': localStamp(w.s('paid_at'))}),
      if (w.s('reference') != null) context.t('wallet.reference', {'reference': w.str('reference')}),
    ];
    return Card(
      key: Key('withdrawal-${w.str('number')}'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(money(context, w['amount']), style: NawyText.h4)),
            StatusChip(status),
          ]),
          const SizedBox(height: 2),
          Text('${w.str('number')} · ${[payout.s('bank_name'), if (payout.s('iban_last4') != null) '•••• ${payout.str('iban_last4')}'].whereType<String>().join(' ')}', style: NawyText.bodySm),
          for (final s in steps) Text(s, style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          if ((status == 'rejected' || status == 'cancelled') && (w.s('reason') ?? '').trim().isNotEmpty)
            Text(context.t('wallet.reason', {'reason': w.str('reason')}), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
          if (status == 'pending')
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton(key: Key('withdrawal-cancel-${w.str('number')}'), onPressed: onCancel, child: Text(context.t('wallet.cancelRequest'))),
            ),
        ]),
      ),
    );
  }
}

/// IBAN without spaces, upper-cased.
String normaliseIban(String raw) => raw.replaceAll(RegExp(r'\s+'), '').toUpperCase();

/// Basic IBAN shape: 15–34 letters and digits starting with the two-letter
/// country code. The bank checks the rest.
bool ibanLooksValid(String raw) => RegExp(r'^[A-Z]{2}[A-Z0-9]{13,32}$').hasMatch(normaliseIban(raw));

/// Why [raw] is not a valid withdrawal amount (null when it is): a decimal
/// with at most the currency's decimals, at least [minimum] and at most
/// [available]. Returns the l10n key and its parameters.
(String, Map<String, Object?>)? withdrawalAmountError(String raw, {required Money available, Money? minimum}) {
  final text = raw.trim();
  final decimals = available.decimals;
  if (text.isEmpty) return ('wallet.amountRequired', const {});
  if (!RegExp('^\\d+(\\.\\d{1,$decimals})?\$').hasMatch(text)) return ('wallet.amountInvalid', const {});
  final amount = Money.parse(text, available.currency);
  if (amount.isZero) return ('wallet.amountInvalid', const {});
  if (minimum != null && minimum.currency == amount.currency && amount.compareTo(minimum) < 0) return ('wallet.amountMin', {'minimum': minimum});
  if (amount.compareTo(available) > 0) return ('wallet.amountMax', {'available': available});
  return null;
}

/// The withdrawal request: amount and the bank account (prefilled from the
/// last payout; the IBAN is typed again, the last four are shown as a hint).
class WithdrawScreen extends StatefulWidget {
  const WithdrawScreen({super.key, required this.wallet});

  final Json wallet;

  @override
  State<WithdrawScreen> createState() => _WithdrawScreenState();
}

class _WithdrawScreenState extends State<WithdrawScreen> {
  final _form = GlobalKey<FormState>();
  late final Json? _last = widget.wallet.m('last_payout');
  late final _amount = TextEditingController();
  late final _holder = TextEditingController(text: _last?.s('account_holder') ?? '');
  late final _bank = TextEditingController(text: _last?.s('bank_name') ?? '');
  final _iban = TextEditingController();
  bool _busy = false;
  Map<String, String> _serverErrors = const {};

  late final Money _available = Money.fromJson(widget.wallet['available']) ?? Money.zero(context.session.currency);
  late final Money? _minimum = Money.fromJson(widget.wallet['minimum']);

  @override
  void dispose() {
    _amount.dispose();
    _holder.dispose();
    _bank.dispose();
    _iban.dispose();
    super.dispose();
  }

  String _fmt(Object? v) => v is Money ? v.format(locale: context.l10n.locale) : '$v';

  Future<void> _submit() async {
    setState(() => _serverErrors = const {});
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await DeliveryApi(context.api).withdraw(
        amount: Money.parse(_amount.text.trim(), _available.currency).amount,
        accountHolder: _holder.text.trim(),
        bankName: _bank.text.trim(),
        iban: normaliseIban(_iban.text),
      );
      if (!mounted) return;
      showSnack(context, context.t('wallet.requested'));
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _serverErrors = e.fieldErrors;
      });
      _form.currentState!.validate();
      const known = {'amount', 'account_holder', 'bank_name', 'iban'};
      if (!e.fieldErrors.keys.any(known.contains)) showSnack(context, apiErrorMessage(context, e));
    }
  }

  @override
  Widget build(BuildContext context) {
    final last4 = _last?.s('iban_last4');
    final carrier = widget.wallet.str('paid_by') == 'carrier';
    return Scaffold(
      appBar: AppBar(title: Text(context.t('wallet.withdraw'))),
      body: Form(
        key: _form,
        child: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
          Text(context.t('wallet.availableNow', {'amount': _available.format(locale: context.l10n.locale)}), style: NawyText.h4),
          if (carrier) Text(context.t('wallet.byCarrier', {'carrier': widget.wallet.s('carrier') ?? context.t('wallet.yourCarrier')}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          const SizedBox(height: 12),
          TextFormField(
            key: const Key('withdraw-amount'),
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textDirection: TextDirection.ltr,
            decoration: InputDecoration(
              labelText: context.t('wallet.amount'),
              prefixText: '${_available.currency} ',
              helperText: _minimum == null ? null : context.t('wallet.minimum', {'minimum': _fmt(_minimum)}),
            ),
            validator: (v) {
              if (_serverErrors['amount'] != null) return _serverErrors['amount'];
              final error = withdrawalAmountError(v ?? '', available: _available, minimum: _minimum);
              return error == null ? null : context.t(error.$1, error.$2.map((k, v) => MapEntry(k, _fmt(v))));
            },
          ),
          const SizedBox(height: 8),
          TextFormField(
            key: const Key('withdraw-holder'),
            controller: _holder,
            textCapitalization: TextCapitalization.words,
            decoration: InputDecoration(labelText: context.t('wallet.holder')),
            validator: (v) => _serverErrors['account_holder'] ?? ((v ?? '').trim().length < 2 ? context.t('wallet.holderRequired') : null),
          ),
          const SizedBox(height: 8),
          TextFormField(
            key: const Key('withdraw-bank'),
            controller: _bank,
            textCapitalization: TextCapitalization.words,
            decoration: InputDecoration(labelText: context.t('wallet.bank')),
            validator: (v) => _serverErrors['bank_name'] ?? ((v ?? '').trim().length < 2 ? context.t('wallet.bankRequired') : null),
          ),
          const SizedBox(height: 8),
          TextFormField(
            key: const Key('withdraw-iban'),
            controller: _iban,
            textDirection: TextDirection.ltr,
            textCapitalization: TextCapitalization.characters,
            autocorrect: false,
            decoration: InputDecoration(
              labelText: context.t('wallet.iban'),
              hintText: 'AE07 0331 2345 6789 0123 456',
              helperText: last4 == null ? null : context.t('wallet.ibanLast', {'last4': last4}),
            ),
            validator: (v) => _serverErrors['iban'] ?? ((v ?? '').trim().isEmpty ? context.t('wallet.ibanRequired') : (ibanLooksValid(v!) ? null : context.t('wallet.ibanInvalid'))),
          ),
          const SizedBox(height: 20),
          FilledButton(key: const Key('withdraw-submit'), onPressed: _busy ? null : _submit, child: Text(context.t('wallet.request'))),
          const SizedBox(height: 8),
          Text(context.t(carrier ? 'wallet.reviewCarrier' : 'wallet.reviewNawy'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
        ]),
      ),
    );
  }
}
