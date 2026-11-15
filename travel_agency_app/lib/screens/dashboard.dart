import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/travel_api.dart';

/// Agency KPIs and the top actions (Figma ops "Travel agency").
class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key, this.onOpen});

  /// Opens a tab (1 requests, 2 departures).
  final void Function(int tab)? onOpen;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('dashboard.title'))),
        body: AsyncBody<Json>(
          load: () => TravelApi(context.api).dashboard(),
          builder: (context, data, _) {
            final k = data.m('kpis') ?? const {};
            final pending = k.i('pending_requests') ?? 0;
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Text(data.m('agency')?.str('name') ?? '', key: const Key('agency'), style: NawyText.h2),
              const SizedBox(height: 12),
              KpiGrid(columns: 2, tiles: [
                KpiTile(key: const Key('kpi-pending'), value: '$pending', label: context.t('kpi.pending'), highlight: pending > 0, onTap: () => onOpen?.call(1)),
                KpiTile(key: const Key('kpi-confirmed'), value: '${k.i('confirmed_this_month') ?? 0}', label: context.t('kpi.confirmedMonth')),
                KpiTile(key: const Key('kpi-departures'), value: '${k.i('upcoming_departures') ?? 0}', label: context.t('kpi.upcoming'), onTap: () => onOpen?.call(2)),
                KpiTile(key: const Key('kpi-seats'), value: '${k.i('seats_left') ?? 0}', label: context.t('kpi.seatsLeft'), onTap: () => onOpen?.call(2)),
              ]),
              SectionTitle(context.t('dashboard.actions')),
              Card(
                child: Column(children: [
                  ListTile(leading: const Icon(Icons.inbox_outlined), title: Text(context.t('dashboard.answer', {'count': pending})), trailing: const Icon(Icons.chevron_right), onTap: () => onOpen?.call(1)),
                  ListTile(leading: const Icon(Icons.event_seat_outlined), title: Text(context.t('dashboard.seats')), trailing: const Icon(Icons.chevron_right), onTap: () => onOpen?.call(2)),
                ]),
              ),
              const SizedBox(height: 12),
              Text(context.t('dashboard.d8'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            ]);
          },
        ),
      );
}
