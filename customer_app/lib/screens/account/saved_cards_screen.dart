import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

/// Figma "40 Cards" (G-A6): the cards saved at the payment gateway for this
/// market (`GET me/cards`). Nawy keeps gateway tokens only: the app sees the
/// brand, the last 4 digits and the expiry, never a card number.
class SavedCardsScreen extends StatefulWidget {
  const SavedCardsScreen({super.key});

  @override
  State<SavedCardsScreen> createState() => _SavedCardsScreenState();
}

class _SavedCardsScreenState extends State<SavedCardsScreen> {
  int _gen = 0;

  Future<void> _makeDefault(Json card) async {
    final res = await guarded(context, () => AppScope.read(context).api.post('me/cards/${card.str('id')}/default'), success: context.t('cards.defaultSet'));
    if (res != null && mounted) setState(() => _gen++);
  }

  Future<void> _delete(Json card) async {
    final ok = await confirmDialog(context, title: context.t('cards.deleteTitle'), body: context.t('cards.deleteBody', {'last4': card.str('last4')}), confirm: context.t('cards.delete'));
    if (ok != true || !mounted) return;
    final res = await guarded(context, () => AppScope.read(context).api.delete('me/cards/${card.str('id')}'), success: context.t('cards.deleted'));
    if (res != null && mounted) setState(() => _gen++);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('cards.title'))),
        body: AsyncBody<List<Json>>(
          key: ValueKey(_gen),
          load: () => AppScope.read(context).api.getList('me/cards').then((r) => r.data),
          isEmpty: (cards) => cards.isEmpty,
          empty: EmptyView(icon: Icons.credit_card_rounded, title: context.t('cards.empty'), subtitle: context.t('cards.emptyHint')),
          builder: (context, cards, reload) => RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
              for (final card in cards) _CardTile(card: card, onDefault: () => _makeDefault(card), onDelete: () => _delete(card)),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Icon(Icons.lock_outline_rounded, size: 16, color: NawyColors.textSecondary),
                  const SizedBox(width: 6),
                  Expanded(child: Text(context.t('cards.secureNote'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary))),
                ]),
              ),
            ]),
          ),
        ),
      );
}

class _CardTile extends StatelessWidget {
  const _CardTile({required this.card, required this.onDefault, required this.onDelete});

  final Json card;
  final VoidCallback onDefault;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final expired = card.b('expired');
    final month = (card.i('exp_month') ?? 0).toString().padLeft(2, '0');
    final year = (card.i('exp_year') ?? 0) % 100;
    final brand = card.str('brand', 'card');
    return SectionCard(
      key: ValueKey('card-${card.str('id')}'),
      child: Row(children: [
        Container(
          width: 48,
          height: 32,
          alignment: Alignment.center,
          decoration: BoxDecoration(color: NawyColors.bgPage, borderRadius: BorderRadius.circular(NawyRadius.sm), border: Border.all(color: NawyColors.borderDefault)),
          child: Text(brand.toUpperCase(), style: NawyText.caption.copyWith(fontWeight: FontWeight.w700), maxLines: 1, overflow: TextOverflow.clip),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('•••• ${card.str('last4')}', style: NawyText.bodyMedium, textDirection: TextDirection.ltr),
            Text(
              context.t(expired ? 'cards.expired' : 'cards.expires', {'date': '$month/${year.toString().padLeft(2, '0')}'}),
              style: NawyText.caption.copyWith(color: expired ? NawyColors.textDanger : NawyColors.textSecondary),
            ),
            if (card.b('is_default'))
              Padding(padding: const EdgeInsets.only(top: 4), child: Pill(context.t('cards.default'), key: ValueKey('card-default-${card.str('id')}')))
            else if (!expired)
              InkWell(
                key: ValueKey('card-make-default-${card.str('id')}'),
                onTap: onDefault,
                child: Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(context.t('cards.makeDefault'), style: NawyText.label.copyWith(color: NawyColors.textLink)),
                ),
              ),
          ]),
        ),
        IconButton(key: ValueKey('card-delete-${card.str('id')}'), tooltip: context.t('cards.delete'), onPressed: onDelete, icon: const Icon(Icons.delete_outline_rounded)),
      ]),
    );
  }
}
