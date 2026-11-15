import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/supervisor_api.dart';
import 'dashboard.dart';
import 'dispatch.dart';
import 'drivers.dart';
import 'failed.dart';

/// Figma ops "Supervisor": Dashboard · Dispatch · Failed · Drivers · More.
class SupervisorShell extends StatefulWidget {
  const SupervisorShell({super.key});

  @override
  State<SupervisorShell> createState() => _SupervisorShellState();
}

class _SupervisorShellState extends State<SupervisorShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final pages = [
      DashboardScreen(onOpen: (i) => setState(() => _index = i)),
      const DispatchScreen(),
      const FailedScreen(),
      const DriversScreen(),
      const SettingsScreen(logoutPath: SupervisorApi.logoutPath),
    ];
    return Scaffold(
      body: IndexedStack(index: _index, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.dashboard_outlined), label: context.t('nav.dashboard')),
          NavigationDestination(icon: const Icon(Icons.local_shipping_outlined), label: context.t('nav.dispatch')),
          NavigationDestination(icon: const Icon(Icons.report_gmailerrorred_outlined), label: context.t('nav.failed')),
          NavigationDestination(icon: const Icon(Icons.badge_outlined), label: context.t('nav.drivers')),
          NavigationDestination(icon: const Icon(Icons.settings_outlined), label: context.t('nav.more')),
        ],
      ),
    );
  }
}
