import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/supervisor_api.dart';

/// Figma "Supervisor summary": company KPIs and shortcuts to the boards.
class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key, this.onOpen});

  /// Opens a tab (1 dispatch, 2 failed, 3 drivers).
  final void Function(int tab)? onOpen;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('dashboard.title'))),
        body: AsyncBody<Json>(
          load: () => SupervisorApi(context.api).dashboard(),
          builder: (context, data, _) {
            final k = data.m('kpis') ?? const {};
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Text(data.m('company')?.str('name') ?? '', key: const Key('company'), style: NawyText.h2),
              const SizedBox(height: 12),
              KpiGrid(tiles: [
                KpiTile(key: const Key('kpi-open'), value: '${k.i('open_assignments') ?? 0}', label: context.t('kpi.open'), onTap: () => onOpen?.call(1)),
                KpiTile(key: const Key('kpi-unassigned'), value: '${k.i('unassigned') ?? 0}', label: context.t('kpi.unassigned'), highlight: (k.i('unassigned') ?? 0) > 0, onTap: () => onOpen?.call(1)),
                KpiTile(key: const Key('kpi-delivered'), value: '${k.i('delivered_today') ?? 0}', label: context.t('kpi.deliveredToday')),
                KpiTile(key: const Key('kpi-failed'), value: '${k.i('failed_today') ?? 0}', label: context.t('kpi.failedToday'), onTap: () => onOpen?.call(2)),
                KpiTile(key: const Key('kpi-drivers'), value: '${k.i('drivers_active') ?? 0}', label: context.t('kpi.driversActive'), onTap: () => onOpen?.call(3)),
                KpiTile(key: const Key('kpi-cod'), value: money(context, k['cod_in_hand']), label: context.t('kpi.codInHand')),
              ]),
              SectionTitle(context.t('dashboard.actions')),
              Card(
                child: Column(children: [
                  ListTile(leading: const Icon(Icons.assignment_ind_outlined), title: Text(context.t('dashboard.assign')), trailing: const Icon(Icons.chevron_right), onTap: () => onOpen?.call(1)),
                  ListTile(leading: const Icon(Icons.event_repeat_outlined), title: Text(context.t('dashboard.resolveFailed')), trailing: const Icon(Icons.chevron_right), onTap: () => onOpen?.call(2)),
                  ListTile(leading: const Icon(Icons.badge_outlined), title: Text(context.t('dashboard.manageDrivers')), trailing: const Icon(Icons.chevron_right), onTap: () => onOpen?.call(3)),
                ]),
              ),
            ]);
          },
        ),
      );
}
