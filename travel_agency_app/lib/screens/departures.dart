import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/travel_api.dart';

/// Figma "Travel packages": departures with seats booked / left and a
/// capacity change (0–1000, never below the seats held or booked).
class DeparturesScreen extends StatelessWidget {
  const DeparturesScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('departures.title'))),
        body: AsyncBody<List<Json>>(
          load: () async => (await TravelApi(context.api).departures()).data,
          builder: (context, rows, reload) => rows.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.flight_takeoff, title: context.t('departures.none'))])
              : ListView.separated(
                  padding: const EdgeInsets.all(NawySpace.gutter),
                  itemCount: rows.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final d = rows[i];
                    final id = d.i('id')!;
                    final capacity = d.i('capacity') ?? 0;
                    final booked = d.i('booked') ?? 0;
                    return Card(
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(children: [
                            Expanded(child: Text(d.str('package'), style: NawyText.h4)),
                            if (d.s('status') != null) StatusChip(d.str('status')),
                          ]),
                          Text('${d.str('starts_on')} → ${d.str('ends_on', '—')}', style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary), textDirection: TextDirection.ltr),
                          const SizedBox(height: 8),
                          ClipRRect(
                            borderRadius: BorderRadius.circular(NawyRadius.pill),
                            child: LinearProgressIndicator(value: bookedShare(capacity, booked), minHeight: 8, backgroundColor: NawyColors.borderDefault, color: NawyColors.bgBrand),
                          ),
                          const SizedBox(height: 6),
                          Row(children: [
                            Expanded(
                              child: Text(
                                context.t('departures.seats', {'booked': booked, 'capacity': capacity, 'left': d.i('seats_left') ?? seatsLeft(capacity, booked)}),
                                key: Key('seats-$id'),
                                style: NawyText.body,
                              ),
                            ),
                            TextButton(
                              key: Key('capacity-$id'),
                              onPressed: () async {
                                final value = await askCapacity(context, capacity, booked);
                                if (value == null || !context.mounted) return;
                                final ok = await runAction(context, () => TravelApi(context.api).setCapacity(id, value), success: context.t('common.saved'));
                                if (ok != null) reload();
                              },
                              child: Text(context.t('departures.change')),
                            ),
                          ]),
                        ]),
                      ),
                    );
                  },
                ),
        ),
      );
}

Future<int?> askCapacity(BuildContext context, int current, int booked) {
  final controller = TextEditingController(text: '$current');
  return showDialog<int>(
    context: context,
    builder: (context) => StatefulBuilder(builder: (context, setState) {
      final error = capacityError(controller.text, booked);
      return AlertDialog(
        title: Text(context.t('departures.changeTitle')),
        content: TextField(
          key: const Key('capacity-field'),
          controller: controller,
          autofocus: true,
          keyboardType: TextInputType.number,
          onChanged: (_) => setState(() {}),
          decoration: InputDecoration(
            labelText: context.t('departures.capacity'),
            helperText: context.t('departures.bookedHint', {'booked': booked}),
            errorText: error == null ? null : context.t(error, {'booked': booked}),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(context.t('common.cancel'))),
          FilledButton(
            key: const Key('capacity-save'),
            onPressed: error == null ? () => Navigator.pop(context, int.parse(controller.text.trim())) : null,
            child: Text(context.t('common.save')),
          ),
        ],
      );
    }),
  );
}
