import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import 'data/supervisor_api.dart';
import 'l10n/strings.dart';
import 'screens/shell.dart';

class SupervisorApp extends StatelessWidget {
  const SupervisorApp({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) => NawyOpsApp(
        session: session,
        title: 'Nawy Fleet',
        en: supervisorEn,
        ar: supervisorAr,
        signIn: (context) => SignInScreen(
          title: context.t('app.title'),
          subtitle: context.t('app.subtitle'),
          onSubmit: (context, email, password) async {
            final s = context.session;
            final result = await SupervisorApi(s.api).login(email, password);
            await s.signedInWith(result.str('token'), {...?result.m('user'), 'workspace': result.m('workspace')});
          },
        ),
        home: (context) => const SupervisorShell(),
      );
}
