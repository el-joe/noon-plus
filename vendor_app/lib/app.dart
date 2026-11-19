import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import 'data/vendor_api.dart';
import 'l10n/strings.dart';
import 'screens/agreement.dart';

class VendorApp extends StatelessWidget {
  const VendorApp({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) => NawyOpsApp(
        session: session,
        title: 'Nawy Seller',
        en: vendorEn,
        ar: vendorAr,
        signIn: (context) => SignInScreen(
          title: context.t('app.title'),
          subtitle: context.t('app.subtitle'),
          onSubmit: (context, email, password) async {
            final s = context.session;
            final result = await VendorApi(s.api).login(email, password);
            await s.signedInWith(result.str('token'), {...?result.m('user'), 'workspace': result.m('workspace')});
          },
        ),
        home: (context) => const VendorGate(),
      );
}
