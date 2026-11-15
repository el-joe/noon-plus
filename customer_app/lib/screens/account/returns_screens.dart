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
import '../checkout/checkout_screen.dart';

/// Returns list (`GET returns`).
class ReturnsScreen extends StatelessWidget {
  const ReturnsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('account.returns'))),
        body: PagedList(
          load: (page) => AppScope.read(context).api.getList('returns', query: {'page': page}),
          empty: EmptyView(icon: Icons.replay_rounded, title: context.t('returns.empty'), subtitle: context.t('returns.emptyHint')),
          itemBuilder: (context, r) => InkWell(
            onTap: () => Navigator.of(context).pushNamed(Routes.returnDetail, arguments: {'number': r.str('number')}),
            child: SectionCard(
              margin: const EdgeInsets.fromLTRB(16, 0, 16, 10),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [Expanded(child: Text(r.str('number'), style: NawyText.bodyMedium)), Pill.status(context, r.s('status'))]),
                Text('${context.t('returns.reason.${r.str('reason')}')} · ${shortDate(context, r.s('created_at'))}', style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                for (final item in r.l('items')) Text('${item.i('quantity')} × ${item.str('title')}', style: NawyText.bodySm),
              ]),
            ),
          ),
        ),
      );
}

const returnReasons = ['defective', 'damaged_on_arrival', 'wrong_item', 'not_as_described', 'changed_mind', 'size_or_fit', 'other'];

/// Figma "33 Return items" + "34 Return pickup": items and quantities from a
/// delivered shipment, reason, pickup / drop-off, refund to card or credits.
class ReturnNewScreen extends StatefulWidget {
  const ReturnNewScreen({super.key, required this.order, required this.shipment});

  final Json order;
  final String shipment;

  @override
  State<ReturnNewScreen> createState() => _ReturnNewScreenState();
}

class _ReturnNewScreenState extends State<ReturnNewScreen> {
  final Map<int, int> _qty = {};
  String? _reason;
  String _method = 'pickup';
  String _refund = 'original';
  final _comment = TextEditingController();
  Map<String, String> _errors = {};

  Json get _shipment => widget.order.l('shipments').firstWhere((s) => s.s('number') == widget.shipment, orElse: () => {});

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final items = [for (final e in _qty.entries) if (e.value > 0) {'order_item_id': e.key, 'quantity': e.value}];
    if (items.isEmpty || _reason == null) {
      setState(() => _errors = {'form': context.t('returns.pickItems')});
      return;
    }
    try {
      final res = await AppScope.read(context).api.post('returns', body: {
        'shipment': widget.shipment,
        'items': items,
        'reason': _reason,
        'comment': _comment.text.trim().isEmpty ? null : _comment.text.trim(),
        'method': _method,
        'refund_destination': _refund,
      });
      if (!mounted) return;
      showToast(context, context.t('returns.created'));
      Navigator.of(context).pushReplacementNamed(Routes.returnDetail, arguments: {'number': asJson(res.data).str('number')});
    } on ApiException catch (e) {
      setState(() => _errors = {...e.fieldErrors, 'form': e.message});
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = _shipment.l('items');
    return Scaffold(
      appBar: AppBar(title: Text(context.t('returns.start'))),
      body: ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
        SectionCard(
          title: context.t('returns.whichItems'),
          child: Column(children: [
            for (final item in items)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(children: [
                  SizedBox(width: 48, height: 48, child: NetImage(item.m('image')?.s('url'), radius: NawyRadius.sm)),
                  const SizedBox(width: 10),
                  Expanded(child: Text(item.str('title'), maxLines: 2, style: NawyText.bodySm)),
                  QuantityStepper(
                    value: _qty[item.i('id')] ?? 0,
                    max: item.i('quantity') ?? 1,
                    onChanged: (v) => setState(() => _qty[item.i('id')!] = v),
                  ),
                ]),
              ),
          ]),
        ),
        SectionCard(
          title: context.t('returns.why'),
          child: Column(children: [
            for (final r in returnReasons) RadioListTileLite(selected: _reason == r, title: context.t('returns.reason.$r'), onTap: () => setState(() => _reason = r)),
            TextField(controller: _comment, maxLines: 3, decoration: InputDecoration(labelText: context.t('returns.comment'), errorText: _errors['comment'])),
          ]),
        ),
        SectionCard(
          title: context.t('returns.how'),
          child: Column(children: [
            RadioListTileLite(selected: _method == 'pickup', title: context.t('returns.pickup'), subtitle: context.t('returns.pickupHint'), onTap: () => setState(() => _method = 'pickup')),
            RadioListTileLite(selected: _method == 'drop_off', title: context.t('returns.dropOff'), onTap: () => setState(() => _method = 'drop_off')),
          ]),
        ),
        SectionCard(
          title: context.t('returns.refundTo'),
          child: Column(children: [
            RadioListTileLite(selected: _refund == 'original', title: context.t('returns.original'), onTap: () => setState(() => _refund = 'original')),
            RadioListTileLite(selected: _refund == 'wallet', title: context.t('returns.wallet'), subtitle: context.t('returns.walletHint'), onTap: () => setState(() => _refund = 'wallet')),
          ]),
        ),
        if (_errors['form'] != null) Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: Text(_errors['form']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
        Padding(padding: const EdgeInsets.all(16), child: BusyButton(label: context.t('returns.submit'), onPressed: _submit)),
      ]),
    );
  }
}

/// Return detail with timeline, refund and cancel (`GET returns/{n}`).
class ReturnDetailScreen extends StatefulWidget {
  const ReturnDetailScreen({super.key, required this.number});

  final String number;

  @override
  State<ReturnDetailScreen> createState() => _ReturnDetailScreenState();
}

class _ReturnDetailScreenState extends State<ReturnDetailScreen> {
  int _gen = 0;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.number)),
        body: AsyncBody<Json>(
          key: ValueKey(_gen),
          load: () => AppScope.read(context).api.getObject('returns/${enc(widget.number)}'),
          builder: (context, r, _) {
            final locale = context.l10n.locale;
            return ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
              SectionCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [Expanded(child: Text(context.t('returns.reason.${r.str('reason')}'), style: NawyText.h4)), Pill.status(context, r.s('status'))]),
                  Text('${context.t(r.s('method') == 'pickup' ? 'returns.pickup' : 'returns.dropOff')} · ${context.t(r.s('refund_destination') == 'wallet' ? 'returns.wallet' : 'returns.original')}',
                      style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                  if (r.s('rejection_reason') != null) Text(r.str('rejection_reason'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
                ]),
              ),
              SectionCard(
                title: context.t('returns.items'),
                child: Column(children: [
                  for (final item in r.l('items')) InfoRow('${item.i('quantity')} × ${item.str('title')}', formatMoney(item['refund'] ?? item['paid'], locale: locale)),
                ]),
              ),
              if (r.m('refund') != null)
                SectionCard(
                  title: context.t('returns.refund'),
                  child: Column(children: [
                    InfoRow(context.t('returns.refundStatus'), context.l10n.status(r.m('refund')!.s('status'))),
                    InfoRow(context.t('cart.total'), formatMoney(r.m('refund')!['amount'], locale: locale), bold: true),
                  ]),
                ),
              SectionCard(
                title: context.t('returns.timeline'),
                child: Column(children: [for (final t in r.l('timeline')) InfoRow(context.l10n.status(t.s('status')), shortDate(context, t.s('at'), time: true))]),
              ),
              if (const {'requested', 'approved'}.contains(r.s('status')))
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: BusyButton(
                    outlined: true,
                    label: context.t('returns.cancel'),
                    onPressed: () async {
                      final ok = await guarded(context, () => AppScope.read(context).api.post('returns/${enc(widget.number)}/cancel'), success: context.t('returns.cancelled'));
                      if (ok != null && mounted) setState(() => _gen++);
                    },
                  ),
                ),
            ]);
          },
        ),
      );
}
