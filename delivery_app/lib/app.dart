import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import 'data/delivery_api.dart';
import 'data/location.dart';
import 'l10n/strings.dart';
import 'screens/shell.dart';

class DeliveryApp extends StatelessWidget {
  const DeliveryApp({super.key, required this.session, this.location = const NoLocationSource(), this.pingScheduler});

  final Session session;

  /// GPS for the on-duty pings (`GeolocatorLocationSource` in the app).
  final LocationSource location;

  /// Ping timer (tests tick by hand).
  final PingScheduler? pingScheduler;

  @override
  Widget build(BuildContext context) => NawyOpsApp(
        session: session,
        title: 'Nawy Delivery',
        en: deliveryEn,
        ar: deliveryAr,
        signIn: (context) => SignInScreen(
          title: context.t('app.title'),
          subtitle: context.t('app.subtitle'),
          onSubmit: (context, email, password) async {
            final s = context.session;
            final result = await DeliveryApi(s.api).login(email, password);
            await s.signedInWith(result.str('token'), result.m('agent') ?? {});
          },
        ),
        home: (context) => DeliveryShell(location: location, pingScheduler: pingScheduler),
      );
}
