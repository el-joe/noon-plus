import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/supervisor_api.dart';

/// Figma panel "Supervisor failed deliveries": reschedule to a business day,
/// return to origin, or confirm the parcel is back at origin (W4). Only the
/// actions the API offers per row are shown.
class FailedScreen extends StatelessWidget {
  const FailedScreen({super.key, this.pickDate});

  /// Test seam for the date picker.
  final Future<DateTime?> Function(BuildContext context)? pickDate;

  Future<DateTime?> _pick(BuildContext context) {
    if (pickDate != null) return pickDate!(context);
    final now = DateTime.now();
    return showDatePicker(context: context, firstDate: DateTime(now.year, now.month, now.day), lastDate: now.add(const Duration(days: 14)), initialDate: now.add(const Duration(days: 1)));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('failed.title'))),
        body: AsyncBody<List<Json>>(
          load: () => SupervisorApi(context.api).failed(),
          builder: (context, rows, reload) => rows.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.check_circle_outline, title: context.t('failed.none'))])
              : ListView.separated(
                  padding: const EdgeInsets.all(NawySpace.gutter),
                  itemCount: rows.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final row = rows[i];
                    final id = row.i('id')!;
                    final actions = row.strings('actions');
                    Future<void> act(String action, [Json body = const {}]) async {
                      final ok = await runAction(context, () => SupervisorApi(context.api).failedAction(id, action, body), success: context.t('common.saved'));
                      if (ok != null) reload();
                    }

                    return Card(
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(children: [
                            Expanded(child: Text(row.s('sub_order_number') ?? row.str('number'), style: NawyText.h4)),
                            StatusChip(row.str('status')),
                          ]),
                          const SizedBox(height: 4),
                          Text(
                            [
                              if (row.s('failure_reason') != null) context.t('reason.${row.str('failure_reason')}'),
                              context.t('failed.attempts', {'value': attemptsLabel(row)}),
                              row.s('city'),
                            ].whereType<String>().join(' · '),
                            style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
                          ),
                          if (row.s('rescheduled_for') != null) Text(context.t('failed.rescheduledFor', {'date': row.str('rescheduled_for')}), style: NawyText.bodySm),
                          if (actions.isNotEmpty) const SizedBox(height: 8),
                          Wrap(spacing: 8, runSpacing: 8, children: [
                            if (actions.contains('reschedule'))
                              OutlinedButton(
                                key: Key('reschedule-$id'),
                                onPressed: () async {
                                  final date = await _pick(context);
                                  if (date != null && context.mounted) await act('reschedule', {'date': ymd(date)});
                                },
                                child: Text(context.t('failed.reschedule')),
                              ),
                            if (actions.contains('return'))
                              OutlinedButton(
                                key: Key('return-$id'),
                                style: OutlinedButton.styleFrom(foregroundColor: NawyColors.textDanger),
                                onPressed: () async {
                                  final reason = await askReason(context, title: context.t('failed.returnTitle'), minLength: 1);
                                  if (reason != null && context.mounted) await act('return', {'reason': reason});
                                },
                                child: Text(context.t('failed.return')),
                              ),
                            if (actions.contains('returned'))
                              FilledButton(
                                key: Key('returned-$id'),
                                onPressed: () => act('returned'),
                                child: Text(context.t('failed.returned')),
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
