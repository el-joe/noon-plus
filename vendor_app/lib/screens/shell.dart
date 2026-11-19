import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/vendor_api.dart';
import 'dashboard.dart';
import 'orders.dart';
import 'stock.dart';
import 'support.dart';

/// Figma "Vendor" (ops minimal): Dashboard · Orders · Stock · Settings
/// (with Documents and Help & support).
class VendorShell extends StatefulWidget {
  const VendorShell({super.key});

  @override
  State<VendorShell> createState() => _VendorShellState();
}

class _VendorShellState extends State<VendorShell> {
  int _index = 0;
  String? _orderStatus;
  bool _lowOnly = false;
  int _generation = 0;

  void _openOrders(String? status) => setState(() {
        _orderStatus = status;
        _index = 1;
        _generation++;
      });

  void _openStock({bool low = false}) => setState(() {
        _lowOnly = low;
        _index = 2;
        _generation++;
      });

  @override
  Widget build(BuildContext context) {
    final pages = [
      DashboardScreen(onOrders: _openOrders, onLowStock: () => _openStock(low: true)),
      OrdersScreen(key: ValueKey('orders-$_generation'), initialStatus: _orderStatus),
      StockScreen(key: ValueKey('stock-$_generation'), initialLowOnly: _lowOnly),
      SettingsScreen(logoutPath: VendorApi.logoutPath, extra: [
        const DocumentsMenuTile(base: 'ops/vendor/documents'),
        ListTile(
          key: const Key('open-support'),
          leading: const Icon(Icons.support_agent_outlined),
          title: Text(context.t('support.menu')),
          subtitle: Text(context.t('support.menuHint')),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SupportListScreen())),
        ),
      ]),
    ];
    return Scaffold(
      body: IndexedStack(index: _index, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.dashboard_outlined), label: context.t('nav.dashboard')),
          NavigationDestination(icon: const Icon(Icons.receipt_long_outlined), label: context.t('nav.orders')),
          NavigationDestination(icon: const Icon(Icons.inventory_2_outlined), label: context.t('nav.stock')),
          NavigationDestination(icon: const Icon(Icons.settings_outlined), label: context.t('settings.title')),
        ],
      ),
    );
  }
}
