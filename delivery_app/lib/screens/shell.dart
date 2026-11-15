import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import 'cod.dart';
import 'earnings.dart';
import 'my_day.dart';
import 'profile.dart';
import 'route_list.dart';

/// Bottom navigation (Figma delivery app: Today · Map · COD · Earnings · Profile).
class DeliveryShell extends StatefulWidget {
  const DeliveryShell({super.key});

  @override
  State<DeliveryShell> createState() => _DeliveryShellState();
}

class _DeliveryShellState extends State<DeliveryShell> {
  int _index = 0;

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
    return Scaffold(
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
    );
  }
}
