import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/marketer_api.dart';
import 'campaigns.dart';
import 'conversions.dart';
import 'dashboard.dart';
import 'links.dart';

/// Figma "Creator" (ops minimal): Dashboard · Campaigns · Links · Conversions · Settings.
class MarketerShell extends StatefulWidget {
  const MarketerShell({super.key});

  @override
  State<MarketerShell> createState() => _MarketerShellState();
}

class _MarketerShellState extends State<MarketerShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final pages = [
      DashboardScreen(onOpen: (i) => setState(() => _index = i)),
      const CampaignsScreen(),
      const LinksScreen(),
      const ConversionsScreen(),
      const SettingsScreen(logoutPath: MarketerApi.logoutPath),
    ];
    return Scaffold(
      body: IndexedStack(index: _index, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.dashboard_outlined), label: context.t('nav.dashboard')),
          NavigationDestination(icon: const Icon(Icons.campaign_outlined), label: context.t('nav.campaigns')),
          NavigationDestination(icon: const Icon(Icons.link), label: context.t('nav.links')),
          NavigationDestination(icon: const Icon(Icons.trending_up), label: context.t('nav.conversions')),
          NavigationDestination(icon: const Icon(Icons.settings_outlined), label: context.t('settings.title')),
        ],
      ),
    );
  }
}
