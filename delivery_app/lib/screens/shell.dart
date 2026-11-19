import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/location.dart';
import 'cod.dart';
import 'earnings.dart';
import 'my_day.dart';
import 'profile.dart';
import 'route_list.dart';

/// Bottom navigation (Figma delivery app: Today · Map · COD · Earnings · Profile).
/// Owns the [DutyTracker] (GPS pings while online, background tracking while
/// a stop is in progress) for the signed-in agent; signing out disposes the
/// shell and stops the pings, the position stream and its service.
class DeliveryShell extends StatefulWidget {
  const DeliveryShell({super.key, this.location = const NoLocationSource(), this.pingScheduler});

  final LocationSource location;
  final PingScheduler? pingScheduler;

  @override
  State<DeliveryShell> createState() => _DeliveryShellState();
}

class _DeliveryShellState extends State<DeliveryShell> with WidgetsBindingObserver {
  int _index = 0;
  DutyTracker? _tracker;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _tracker ??= DutyTracker(context.api, widget.location, scheduler: widget.pingScheduler);
    _tracker!.notice = (title: context.t('gps.noticeTitle'), text: context.t('gps.noticeText'));
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Back from the settings app: the permission may have changed.
    if (state == AppLifecycleState.resumed) _tracker?.recheck();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _tracker?.dispose();
    super.dispose();
  }

  void go(int index) => setState(() => _index = index);

  @override
  Widget build(BuildContext context) {
    final pages = [
      MyDayScreen(onOpenStops: () => go(1)),
      const RouteScreen(),
      const CodScreen(),
      const EarningsScreen(),
      const ProfileScreen(),
    ];
    return DutyScope(
      tracker: _tracker!,
      child: Scaffold(
      body: IndexedStack(index: _index, children: pages),
      bottomNavigationBar: NavigationBarTheme(
        data: NavigationBarThemeData(
          labelTextStyle: WidgetStateProperty.resolveWith((states) => NawyText.caption.copyWith(color: states.contains(WidgetState.selected) ? NawyColors.bgBrand : NawyColors.textInverse)),
        ),
        child: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: go,
        backgroundColor: NawyColors.bgInverse,
        indicatorColor: NawyColors.bgBrand.withValues(alpha: 0.25),
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        destinations: [
          for (final (icon, key) in const [
            (Icons.today_outlined, 'nav.today'),
            (Icons.place_outlined, 'nav.stops'),
            (Icons.account_balance_wallet_outlined, 'nav.cod'),
            (Icons.bar_chart_rounded, 'nav.earnings'),
            (Icons.person_outline, 'nav.profile'),
          ])
            NavigationDestination(
              icon: Icon(icon, color: NawyColors.textInverse),
              selectedIcon: Icon(icon, color: NawyColors.bgBrand),
              label: context.t(key),
            ),
        ],
        ),
      ),
    ),
    );
  }
}
