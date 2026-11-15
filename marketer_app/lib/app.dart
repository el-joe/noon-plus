import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import 'data/marketer_api.dart';
import 'l10n/strings.dart';
import 'screens/shell.dart';

class MarketerApp extends StatelessWidget {
  const MarketerApp({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) => NawyOpsApp(
        session: session,
        title: 'Nawy Creator Studio',
        en: marketerEn,
        ar: marketerAr,
        signIn: (context) => SignInScreen(
          title: context.t('app.title'),
          subtitle: context.t('app.subtitle'),
          onSubmit: (context, email, password) async {
            final s = context.session;
            final result = await MarketerApi(s.api).login(email, password);
            await s.signedInWith(result.str('token'), {...?result.m('user'), 'workspace': result.m('workspace')});
          },
        ),
        home: (context) => const MarketerShell(),
      );
}
