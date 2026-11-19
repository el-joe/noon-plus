import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/platform/platform_bridge.dart';
import 'l10n/l10n.dart';
import 'routing/guards.dart';
import 'routing/router.dart';
import 'state/app_state.dart';
import 'ui/theme.dart';

class NawyApp extends StatefulWidget {
  const NawyApp({super.key, required this.state, this.initialRoute = '/'});

  final AppState state;
  final String initialRoute;

  @override
  State<NawyApp> createState() => _NawyAppState();
}

class _NawyAppState extends State<NawyApp> {
  final _messenger = GlobalKey<ScaffoldMessengerState>();
  final _navigator = GlobalKey<NavigatorState>();
  StreamSubscription<Uri>? _links;

  @override
  void initState() {
    super.initState();
    widget.state.notice.addListener(_showNotice);
    // `nawy://` links while the app runs (Google / Apple sign-in returning).
    if (PlatformBridge.supportsDeepLinks) _links = PlatformBridge.links.listen(widget.state.handleLink);
  }

  @override
  void dispose() {
    widget.state.notice.removeListener(_showNotice);
    _links?.cancel();
    super.dispose();
  }

  /// App-wide messages ([AppNotice]): signed out by the API (401 / 403),
  /// guest-cart lines changed on sign-in.
  void _showNotice() {
    final notice = widget.state.notice.value;
    final messenger = _messenger.currentState;
    if (notice == null || messenger == null) return;
    final l10n = L10n(widget.state.locale);
    final action = switch (notice.target) {
      'signIn' => SnackBarAction(
          label: l10n.t('auth.signIn'),
          onPressed: () => _navigator.currentState?.pushNamed(Routes.signIn, arguments: {'pop': true}),
        ),
      'cart' => SnackBarAction(label: l10n.t('cart.view'), onPressed: () => widget.state.tabRequest.value = 'cart'),
      _ => null,
    };
    messenger
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        key: const ValueKey('app-notice'),
        content: Text(notice.message ?? l10n.t(notice.key, notice.params)),
        action: action,
        duration: const Duration(seconds: 8),
      ));
    widget.state.notice.value = null;
  }

  @override
  Widget build(BuildContext context) => AppScope(
        state: widget.state,
        child: Builder(builder: (context) {
          final app = AppScope.of(context);
          final l10n = L10n(app.locale);
          return L10nScope(
            l10n: l10n,
            child: MaterialApp(
              title: 'Nawy',
              debugShowCheckedModeBanner: false,
              scaffoldMessengerKey: _messenger,
              navigatorKey: _navigator,
              theme: buildNawyTheme(arabic: app.locale == 'ar'),
              locale: Locale(app.locale),
              supportedLocales: const [Locale('en'), Locale('ar')],
              localizationsDelegates: GlobalMaterialLocalizations.delegates,
              initialRoute: widget.initialRoute,
              onGenerateRoute: (settings) => AppRouter.generate(settings, app),
              // One initial route (no implicit "/" underneath deep routes).
              onGenerateInitialRoutes: (initial) => [AppRouter.generate(RouteSettings(name: initial), app)],
            ),
          );
        }),
      );
}
