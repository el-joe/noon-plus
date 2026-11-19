import 'dart:async';

import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/travel_api.dart';
import 'bookings.dart';
import 'dashboard.dart';
import 'departures.dart';
import 'promotion_checkout.dart';
import 'promotions.dart';

/// Figma ops "Travel agency": Dashboard · Requests · Departures ·
/// Promotions (D14) · More.
class TravelShell extends StatefulWidget {
  const TravelShell({super.key});

  @override
  State<TravelShell> createState() => _TravelShellState();
}

class _TravelShellState extends State<TravelShell> {
  int _index = 0;
  StreamSubscription<String>? _links;

  @override
  void initState() {
    super.initState();
    // `nawytravel://promotions/return?payment=…` while the app runs ([PromotionReturnLinks]).
    _links = PromotionReturnLinks.links.stream.listen(_onReturn);
    // Cold start from that link (`flutter_deeplinking_enabled`): the initial route.
    final initial = Uri.tryParse(WidgetsBinding.instance.platformDispatcher.defaultRouteName);
    final reference = initial == null ? null : promotionReturnReference(initial);
    if (reference != null) WidgetsBinding.instance.addPostFrameCallback((_) => _onReturn(reference));
  }

  @override
  void dispose() {
    _links?.cancel();
    super.dispose();
  }

  /// Handed to the open payment screen, else a result screen is opened for it.
  void _onReturn(String reference) {
    if (!mounted) return;
    if (PromotionCheckout.hasPaymentScreen) {
      PromotionCheckout.returns.add(reference);
      return;
    }
    setState(() => _index = 3);
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => PromotionPaymentScreen(reference: reference)));
  }

  @override
  Widget build(BuildContext context) {
    final pages = [
      DashboardScreen(onOpen: (i) => setState(() => _index = i)),
      const BookingsScreen(),
      const DeparturesScreen(),
      const PromotionsScreen(),
      const SettingsScreen(logoutPath: TravelApi.logoutPath, extra: [DocumentsMenuTile(base: 'ops/travel/documents')]),
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
          NavigationDestination(icon: const Icon(Icons.campaign_outlined), label: context.t('nav.promotions')),
          NavigationDestination(icon: const Icon(Icons.settings_outlined), label: context.t('nav.more')),
        ],
      ),
    );
  }
}
