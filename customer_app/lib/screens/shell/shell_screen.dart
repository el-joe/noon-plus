import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../account/account_tab.dart';
import '../account/wishlist_screen.dart';
import '../account/orders_screens.dart';
import '../cart/cart_tab.dart';
import '../catalog/categories_tab.dart';
import '../deals/deals_tab.dart';
import '../home/home_tab.dart';
import '../live/live_screens.dart';
import 'app_header.dart';

/// The app shell (ADR 16, §14c): super-app context switcher + per-context
/// header theme + per-context bottom tab bar (`GET app/contexts`).
class ShellScreen extends StatefulWidget {
  const ShellScreen({super.key, this.initialTab});

  final String? initialTab;

  @override
  State<ShellScreen> createState() => _ShellScreenState();
}

class _ShellScreenState extends State<ShellScreen> {
  int _index = 0;
  late final AppState _app;

  @override
  void initState() {
    super.initState();
    _app = AppScope.read(context);
    _app.tabRequest.addListener(_onTabRequest);
    if (widget.initialTab != null) WidgetsBinding.instance.addPostFrameCallback((_) => _select(widget.initialTab!));
  }

  @override
  void dispose() {
    _app.tabRequest.removeListener(_onTabRequest);
    super.dispose();
  }

  void _onTabRequest() {
    final target = _app.tabRequest.value;
    if (target == null) return;
    _select(target);
    _app.tabRequest.value = null;
  }

  void _select(String target) {
    final tabs = _app.context.l('tabs');
    final i = tabs.indexWhere((t) => t.str('target') == target);
    if (i >= 0) {
      setState(() => _index = i);
    } else if (target == 'cart') {
      Navigator.of(context).pushNamed(Routes.cart);
    }
  }

  static IconData iconFor(String? icon, String target, {bool active = false}) {
    switch (icon ?? target) {
      case 'home':
        return active ? Icons.home_rounded : Icons.home_outlined;
      case 'grid':
      case 'categories':
        return active ? Icons.grid_view_rounded : Icons.grid_view_outlined;
      case 'tag':
      case 'deals':
        return active ? Icons.local_offer_rounded : Icons.local_offer_outlined;
      case 'user':
      case 'account':
        return active ? Icons.person_rounded : Icons.person_outline_rounded;
      case 'cart':
        return active ? Icons.shopping_cart_rounded : Icons.shopping_cart_outlined;
      case 'search':
        return Icons.search_rounded;
      case 'orders':
      case 'package':
        return active ? Icons.inventory_2_rounded : Icons.inventory_2_outlined;
      case 'live':
      case 'radio':
        return active ? Icons.live_tv_rounded : Icons.live_tv_outlined;
      case 'favourites':
      case 'heart':
        return active ? Icons.favorite_rounded : Icons.favorite_border_rounded;
      default:
        return active ? Icons.circle : Icons.circle_outlined;
    }
  }

  String _label(BuildContext context, Json tab) {
    final label = tab.s('label');
    if (label != null && label.isNotEmpty) return label;
    return context.t('tabs.${tab.str('target', 'home')}');
  }

  Widget _body(Json ctx, Json tab) {
    final target = tab.str('target', 'home');
    final key = ValueKey('${ctx.str('key')}:$target');
    return switch (target) {
      'home' => ContextHome(key: key, appContext: ctx),
      'categories' => CategoriesTab(key: key),
      'deals' => DealsTab(key: key),
      'account' => AccountTab(key: key),
      'cart' => CartTab(key: key, asTab: true),
      'orders' => OrdersScreen(key: key, asTab: true),
      'live' => LiveListScreen(key: key, asTab: true),
      'favourites' => WishlistScreen(key: key, asTab: true),
      'search' => ContextHome(key: key, appContext: ctx),
      'page' => PageTab(key: key, slug: tab.s('page')),
      _ => ContextHome(key: key, appContext: ctx),
    };
  }

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    final ctx = app.context;
    var tabs = ctx.l('tabs');
    if (tabs.length < 2) tabs = AppState.defaultTabs;
    if (_index >= tabs.length) _index = 0;
    final accent = hexColor(ctx.m('theme')?.s('accent'));
    final tab = tabs[_index];
    final quick = const {'minutes', 'food'}.contains(ctx.str('key'));

    return PopScope(
      canPop: _index == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) setState(() => _index = 0);
      },
      child: Scaffold(
        body: Column(children: [
          if (tab.str('target') == 'home' || tab.str('target') == 'search') const AppHeader(),
          Expanded(child: _body(ctx, tab)),
        ]),
        floatingActionButtonLocation: FloatingActionButtonLocation.centerFloat,
        floatingActionButton: quick && app.cart.count > 0 && tab.str('target') != 'cart'
            ? FloatingActionButton.extended(
                key: const ValueKey('cart-pill'),
                backgroundColor: accent ?? NawyColors.bgInverse,
                foregroundColor: NawyColors.textInverse,
                onPressed: () => _select('cart'),
                icon: const Icon(Icons.shopping_basket_rounded),
                label: Text(context.t('cart.viewCart', {'count': app.cart.count})),
              )
            : null,
        bottomNavigationBar: NavigationBarTheme(
          data: NavigationBarThemeData(
            indicatorColor: Colors.transparent,
            backgroundColor: NawyColors.bgSurface,
            labelTextStyle: WidgetStateProperty.resolveWith((states) => NawyText.caption.copyWith(
                  color: states.contains(WidgetState.selected) ? NawyColors.textPrimary : NawyColors.textSecondary,
                  fontWeight: states.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500,
                )),
          ),
          child: NavigationBar(
            height: 64,
            selectedIndex: _index,
            onDestinationSelected: (i) => setState(() => _index = i),
            destinations: [
              for (final t in tabs)
                NavigationDestination(
                  key: ValueKey('tab-${t.str('target')}'),
                  icon: _badged(app, t, iconFor(t.s('icon'), t.str('target'))),
                  selectedIcon: _badged(app, t, iconFor(t.s('icon'), t.str('target'), active: true)),
                  label: _label(context, t),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _badged(AppState app, Json tab, IconData icon) {
    final target = tab.str('target');
    final count = target == 'cart' ? app.cart.count : (target == 'account' ? app.unread : 0);
    return Badge(isLabelVisible: count > 0, label: Text(count > 99 ? '99+' : '$count'), backgroundColor: NawyColors.bgDanger, child: Icon(icon));
  }
}
