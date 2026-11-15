import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/supervisor_api.dart';

/// Drivers of the company: status, vehicle, open stops, cash in hand and
/// last GPS ping; suspend / reactivate (new agents are activated by Nawy, P3).
class DriversScreen extends StatelessWidget {
  const DriversScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('drivers.title'))),
        body: AsyncBody<List<Json>>(
          load: () => SupervisorApi(context.api).drivers(),
          builder: (context, rows, reload) => rows.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.badge_outlined, title: context.t('drivers.none'))])
              : ListView.separated(
                  padding: const EdgeInsets.all(NawySpace.gutter),
                  itemCount: rows.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final d = rows[i];
                    final id = d.i('id')!;
                    final status = d.str('status');
                    final vehicle = d.m('vehicle') ?? const {};
                    final ping = DateTime.tryParse(d.str('last_ping_at'))?.toLocal();
                    final ml = MaterialLocalizations.of(context);
                    return Card(
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(children: [
                            Expanded(child: Text(d.str('name'), style: NawyText.h4)),
                            StatusChip(status),
                          ]),
                          if (d.s('phone') != null) Text(d.str('phone'), style: NawyText.bodySm, textDirection: TextDirection.ltr),
                          Text(
                            [
                              if (vehicle.s('type') != null) context.t('vehicle.${vehicle.str('type')}'),
                              vehicle.s('plate'),
                              context.t('drivers.open', {'count': d.i('open_assignments') ?? 0}),
                              context.t('drivers.cod', {'amount': money(context, d['cod_in_hand'])}),
                            ].whereType<String>().join(' · '),
                            style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
                          ),
                          Text(
                            ping == null ? context.t('drivers.noPing') : context.t('drivers.ping', {'time': '${ml.formatShortDate(ping)} ${ml.formatTimeOfDay(TimeOfDay.fromDateTime(ping))}'}),
                            style: NawyText.bodySm.copyWith(color: NawyColors.textMuted),
                          ),
                          const SizedBox(height: 8),
                          if (status == 'active' || status == 'unavailable')
                            OutlinedButton(
                              key: Key('suspend-$id'),
                              style: OutlinedButton.styleFrom(foregroundColor: NawyColors.textDanger),
                              onPressed: () async {
                                final reason = await askReason(context, title: context.t('drivers.suspendTitle'), minLength: 0, hint: context.t('drivers.reasonOptional'));
                                if (reason == null || !context.mounted) return;
                                final ok = await runAction(context, () => SupervisorApi(context.api).setDriverStatus(id, 'suspended', reason: reason.isEmpty ? null : reason), success: context.t('drivers.suspended'));
                                if (ok != null) reload();
                              },
                              child: Text(context.t('drivers.suspend')),
                            )
                          else if (status == 'suspended' || status == 'pending_documents')
                            FilledButton(
                              key: Key('activate-$id'),
                              onPressed: () async {
                                final ok = await runAction(context, () => SupervisorApi(context.api).setDriverStatus(id, 'active'), success: context.t('drivers.activated'));
                                if (ok != null) reload();
                              },
                              child: Text(context.t('drivers.activate')),
                            ),
                        ]),
                      ),
                    );
                  },
                ),
        ),
      );
}
