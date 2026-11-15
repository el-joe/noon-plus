import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'l10n/l10n.dart';
import 'routing/router.dart';
import 'state/app_state.dart';
import 'ui/theme.dart';

class NawyApp extends StatelessWidget {
  const NawyApp({super.key, required this.state, this.initialRoute = '/'});

  final AppState state;
  final String initialRoute;

  @override
  Widget build(BuildContext context) => AppScope(
        state: state,
        child: Builder(builder: (context) {
          final app = AppScope.of(context);
          final l10n = L10n(app.locale);
          return L10nScope(
            l10n: l10n,
            child: MaterialApp(
              title: 'Nawy',
              debugShowCheckedModeBanner: false,
              theme: buildNawyTheme(),
              locale: Locale(app.locale),
              supportedLocales: const [Locale('en'), Locale('ar')],
              localizationsDelegates: GlobalMaterialLocalizations.delegates,
              initialRoute: initialRoute,
              onGenerateRoute: (settings) => AppRouter.generate(settings, app),
              // One initial route (no implicit "/" underneath deep routes).
              onGenerateInitialRoutes: (initial) => [AppRouter.generate(RouteSettings(name: initial), app)],
            ),
          );
        }),
      );
}
