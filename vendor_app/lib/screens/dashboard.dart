import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/vendor_api.dart';

/// Figma "Vendor sales": store KPIs and the top actions.
class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key, required this.onOrders, required this.onLowStock});

  final void Function(String? status) onOrders;
  final VoidCallback onLowStock;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('dashboard.title'))),
        body: AsyncBody<Json>(
          load: () => VendorApi(context.api).dashboard(),
          builder: (context, data, _) {
            final k = data.m('kpis') ?? const {};
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Text(data.m('store')?.str('name') ?? '', style: NawyText.h2),
              const SizedBox(height: 12),
              KpiGrid(columns: 2, tiles: [
                KpiTile(key: const Key('kpi-sales-today'), value: money(context, k['sales_today']), label: context.t('dashboard.salesToday'), highlight: true),
                KpiTile(key: const Key('kpi-sales-7d'), value: money(context, k['sales_7d']), label: context.t('dashboard.sales7d')),
              ]),
              const SizedBox(height: 8),
              KpiGrid(tiles: [
                KpiTile(key: const Key('kpi-to-confirm'), value: '${k.i('to_confirm') ?? 0}', label: context.t('dashboard.toConfirm'), onTap: () => onOrders('placed')),
                KpiTile(value: '${k.i('to_pack') ?? 0}', label: context.t('dashboard.toPack'), onTap: () => onOrders('confirmed')),
                KpiTile(value: '${k.i('ready_to_ship') ?? 0}', label: context.t('dashboard.readyToShip'), onTap: () => onOrders('packed')),
                KpiTile(value: '${k.i('orders_today') ?? 0}', label: context.t('dashboard.ordersToday'), onTap: () => onOrders(null)),
                KpiTile(key: const Key('kpi-low-stock'), value: '${k.i('low_stock') ?? 0}', label: context.t('dashboard.lowStock'), onTap: onLowStock),
              ]),
              SectionTitle(context.t('dashboard.actions')),
              Card(
                child: Column(children: [
                  ListTile(
                    key: const Key('action-confirm'),
                    leading: const Icon(Icons.task_alt),
                    title: Text(context.t('dashboard.actionConfirm', {'count': k.i('to_confirm') ?? 0})),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => onOrders('placed'),
                  ),
                  ListTile(
                    leading: const Icon(Icons.inventory_outlined),
                    title: Text(context.t('dashboard.actionPack', {'count': k.i('to_pack') ?? 0})),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => onOrders('confirmed'),
                  ),
                  ListTile(
                    key: const Key('action-low-stock'),
                    leading: const Icon(Icons.warning_amber_rounded),
                    title: Text(context.t('dashboard.actionStock', {'count': k.i('low_stock') ?? 0})),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: onLowStock,
                  ),
                ]),
              ),
            ]);
          },
        ),
      );
}
