import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/travel_api.dart';
import 'bookings.dart';
import 'dashboard.dart';
import 'departures.dart';

/// Figma ops "Travel agency": Dashboard · Requests · Departures · More.
class TravelShell extends StatefulWidget {
  const TravelShell({super.key});

  @override
  State<TravelShell> createState() => _TravelShellState();
}

class _TravelShellState extends State<TravelShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final pages = [
      DashboardScreen(onOpen: (i) => setState(() => _index = i)),
      const BookingsScreen(),
      const DeparturesScreen(),
      const SettingsScreen(logoutPath: TravelApi.logoutPath),
    ];
    return Scaffold(
      body: IndexedStack(index: _index, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.dashboard_outlined), label: context.t('nav.dashboard')),
          NavigationDestination(icon: const Icon(Icons.inbox_outlined), label: context.t('nav.requests')),
          NavigationDestination(icon: const Icon(Icons.flight_takeoff), label: context.t('nav.departures')),
          NavigationDestination(icon: const Icon(Icons.settings_outlined), label: context.t('nav.more')),
        ],
      ),
    );
  }
}
