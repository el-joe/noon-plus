import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/travel_api.dart';

/// Figma "Travel inquiries": booking requests (D8) to confirm or reject.
/// Confirming tells the traveller the seats are theirs; payment happens with
/// the agency, never on Nawy.
class BookingsScreen extends StatefulWidget {
  const BookingsScreen({super.key});

  @override
  State<BookingsScreen> createState() => _BookingsScreenState();
}

class _BookingsScreenState extends State<BookingsScreen> {
  String? _status = 'requested';

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: Text(context.t('bookings.title')),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(48),
            child: SizedBox(
              height: 48,
              child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6), children: [
                for (final s in [null, ...bookingFilters])
                  Padding(
                    padding: const EdgeInsetsDirectional.only(end: 6),
                    child: ChoiceChip(
                      key: Key('filter-${s ?? 'all'}'),
                      label: Text(s == null ? context.t('common.all') : context.l10n.status(s), style: TextStyle(color: _status == s ? NawyColors.textInverse : null)),
                      selected: _status == s,
                      showCheckmark: false,
                      onSelected: (_) => setState(() => _status = s),
                    ),
                  ),
              ]),
            ),
          ),
        ),
        body: AsyncBody<List<Json>>(
          key: ValueKey(_status),
          load: () async => (await TravelApi(context.api).bookings(status: _status)).data,
          builder: (context, rows, reload) => rows.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.inbox_outlined, title: context.t('bookings.none'))])
              : ListView.separated(
                  padding: const EdgeInsets.all(NawySpace.gutter),
                  itemCount: rows.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) => BookingCard(row: rows[i], onChanged: reload),
                ),
        ),
      );
}

class BookingCard extends StatelessWidget {
  const BookingCard({super.key, required this.row, required this.onChanged});

  final Json row;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final id = row.i('id')!;
    final api = TravelApi(context.api);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(row.str('package'), style: NawyText.h4)),
            StatusChip(row.str('status')),
          ]),
          const SizedBox(height: 4),
          Text(
            [
              row.str('number'),
              if (row.s('departure_date') != null) context.t('bookings.departs', {'date': row.str('departure_date')}),
              if (partyOf(row) case final party? when party.children > 0)
                context.t('bookings.party', {'adults': party.adults, 'children': party.children})
              else
                context.t('bookings.travellers', {'count': row.i('travellers') ?? partyOf(row)?.adults ?? 0}),
            ].join(' · '),
            style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
          ),
          if (row.s('contact_name') != null) Text(row.str('contact_name'), style: NawyText.body),
          if (row.s('contact_phone') != null) PhoneLink(row.str('contact_phone'), key: Key('call-contact-$id')),
          for (final (i, room) in roomsOf(row).indexed)
            Row(key: Key('room-$id-$i'), children: [
              Expanded(
                child: Text(
                  context.t('bookings.room', {'room': context.l10n.status(room.s('room_type')), 'count': room.i('travellers') ?? 1, 'price': money(context, room['unit_price'])}),
                  style: NawyText.bodySm,
                ),
              ),
              Text(money(context, room['line_total']), style: NawyText.bodySm),
            ]),
          if (row['total'] != null) Text(context.t('bookings.total', {'amount': money(context, row['total'])}), style: NawyText.bodyMedium),
          if (row.b('can_confirm') || row.b('can_reject')) ...[
            const SizedBox(height: 8),
            Row(children: [
              if (row.b('can_reject'))
                Expanded(
                  child: OutlinedButton(
                    key: Key('reject-$id'),
                    style: OutlinedButton.styleFrom(foregroundColor: NawyColors.textDanger),
                    onPressed: () async {
                      final reason = await askReason(context, title: context.t('bookings.rejectTitle'));
                      if (reason == null || !context.mounted) return;
                      final ok = await runAction(context, () => api.reject(id, reason), success: context.t('bookings.rejected'));
                      if (ok != null) onChanged();
                    },
                    child: Text(context.t('bookings.reject')),
                  ),
                ),
              if (row.b('can_confirm') && row.b('can_reject')) const SizedBox(width: 8),
              if (row.b('can_confirm'))
                Expanded(
                  child: FilledButton(
                    key: Key('confirm-$id'),
                    onPressed: () async {
                      final ok = await runAction(context, () => api.confirm(id), success: context.t('bookings.confirmed'));
                      if (ok != null) onChanged();
                    },
                    child: Text(context.t('bookings.confirm')),
                  ),
                ),
            ]),
          ],
        ]),
      ),
    );
  }
}
