import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/travel_api.dart';
import 'promotion_checkout.dart';

/// D14 "Travel: free listing, paid placement": listing trips is free; a paid
/// promotion shows a trip first in travel lists and search with a
/// "Sponsored" label. Bought in the app: choose a trip and an option, pay by
/// card in an in-app browser tab, come back to the result
/// ([PromoteTripScreen] → [PromotionPaymentScreen]).
class PromotionsScreen extends StatefulWidget {
  const PromotionsScreen({super.key});

  @override
  State<PromotionsScreen> createState() => _PromotionsScreenState();
}

class _PromotionsScreenState extends State<PromotionsScreen> {
  final _key = GlobalKey<AsyncBodyState<ApiResponse<List<Json>>>>();

  Future<void> _open(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    await _key.currentState?.reload();
  }

  Future<void> _payNow(int id) async {
    try {
      final started = await TravelApi(context.api).payPromotion(id);
      if (mounted) await _open(PromotionPaymentScreen(start: started));
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.code == 'transition_refused' ? e.message : apiErrorMessage(context, e));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('promotions.title'))),
        body: AsyncBody<ApiResponse<List<Json>>>(
          key: _key,
          load: () => TravelApi(context.api).promotions(),
          builder: (context, res, _) {
            final rows = res.data;
            final button = Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: FilledButton.icon(
                key: const Key('promote'),
                icon: const Icon(Icons.campaign_outlined),
                label: Text(context.t('promotions.buy')),
                onPressed: () => _open(const PromoteTripScreen()),
              ),
            );
            final intro = Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(context.t('promotions.intro'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            );
            if (rows.isEmpty) {
              return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
                intro,
                button,
                EmptyView(icon: Icons.campaign_outlined, title: context.t('promotions.none'), subtitle: context.t('promotions.noneHint')),
              ]);
            }
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              intro,
              button,
              for (final p in rows) ...[PromotionCard(row: p, onPay: () => _payNow(p.i('id') ?? 0)), const SizedBox(height: 8)],
            ]);
          },
        ),
      );
}

class PromotionCard extends StatelessWidget {
  const PromotionCard({super.key, required this.row, this.onPay});

  final Json row;

  /// "Pay now" for a promotion still waiting for payment.
  final VoidCallback? onPay;

  @override
  Widget build(BuildContext context) {
    final id = row.i('id');
    final status = row.str('status');
    final product = row.m('product') ?? const {};
    final ml = MaterialLocalizations.of(context);
    String? date(String key) {
      final d = DateTime.tryParse(row.str(key));
      return d == null ? null : ml.formatShortDate(d.toLocal());
    }

    final starts = date('starts_at');
    final ends = date('ends_at');
    final days = product.i('duration_days');
    return Card(
      key: Key('promotion-$id'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(row.m('package')?.str('title', '—') ?? '—', style: NawyText.h4)),
            if (status.isNotEmpty) StatusChip(promotionChipStatus(status), key: Key('promotion-status-$id'), label: row.s('status_label') ?? context.l10n.status(status)),
          ]),
          const SizedBox(height: 4),
          Text(
            [
              row.str('number'),
              if (product.s('name') != null) product.str('name'),
              if (days != null) context.t('promotions.days', {'count': days}),
            ].where((s) => s.isNotEmpty).join(' · '),
            style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
          ),
          const SizedBox(height: 4),
          Row(children: [
            Expanded(
              child: Text(
                starts == null ? context.t('promotions.notStarted') : context.t('promotions.period', {'from': starts, 'to': ends ?? '—'}),
                key: Key('promotion-dates-$id'),
                style: NawyText.bodySm,
              ),
            ),
            Text(money(context, row['amount']), key: Key('promotion-amount-$id'), style: NawyText.bodyMedium),
          ]),
          if (onPay != null && (row.b('can_pay') || status == 'pending_payment'))
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton.icon(
                key: Key('promotion-pay-$id'),
                onPressed: onPay,
                icon: const Icon(Icons.credit_card, size: 18),
                label: Text(context.t('promotions.payNow')),
              ),
            ),
        ]),
      ),
    );
  }
}
