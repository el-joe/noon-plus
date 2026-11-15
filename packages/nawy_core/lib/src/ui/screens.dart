import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import '../api/api_client.dart';
import '../config.dart';
import '../l10n.dart';
import '../session.dart';
import 'theme.dart';

/// Root widget for every delivery / ops app: theme, EN/AR with RTL, and
/// the sign-in guard (signed out → [signIn], signed in → [home]).
class NawyOpsApp extends StatelessWidget {
  const NawyOpsApp({
    super.key,
    required this.session,
    required this.title,
    required this.signIn,
    required this.home,
    this.en = const {},
    this.ar = const {},
  });

  final Session session;
  final String title;
  final Map<String, String> en;
  final Map<String, String> ar;
  final WidgetBuilder signIn;
  final WidgetBuilder home;

  @override
  Widget build(BuildContext context) => SessionScope(
        session: session,
        child: ListenableBuilder(
          listenable: session,
          builder: (context, _) {
            final l10n = L10n(session.locale, en: en, ar: ar);
            return L10nScope(
              l10n: l10n,
              child: MaterialApp(
                title: title,
                debugShowCheckedModeBanner: false,
                theme: buildNawyTheme(),
                locale: Locale(session.locale),
                supportedLocales: const [Locale('en'), Locale('ar')],
                localizationsDelegates: GlobalMaterialLocalizations.delegates,
                home: !session.loaded
                    ? const Scaffold(body: Center(child: CircularProgressIndicator()))
                    : KeyedSubtree(
                        key: ValueKey('${session.signedIn}-${session.market}'),
                        child: Builder(builder: session.signedIn ? home : signIn),
                      ),
              ),
            );
          },
        ),
      );
}

/// Email + password sign-in with country and language (Figma "Sign in" /
/// "00 Sign in & role switch"). [onSubmit] calls the app's login endpoint
/// and returns the token and the profile to keep.
class SignInScreen extends StatefulWidget {
  const SignInScreen({super.key, required this.title, required this.subtitle, required this.onSubmit, this.badge});

  final String title;
  final String subtitle;
  final String? badge;
  final Future<void> Function(BuildContext context, String email, String password) onSubmit;

  @override
  State<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends State<SignInScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  Future<void> _submit() async {
    final email = _email.text.trim();
    final password = _password.text;
    if (email.isEmpty || password.isEmpty) {
      setState(() => _error = context.t('login.required'));
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.onSubmit(context, email, password);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.fieldErrors.values.firstOrNull ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    return Scaffold(
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
          const SizedBox(height: 24),
          Row(children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(color: NawyColors.bgBrand, borderRadius: BorderRadius.circular(NawyRadius.lg)),
              alignment: Alignment.center,
              child: Text('N', style: NawyText.h2),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(widget.title, style: NawyText.h2),
                Text(widget.subtitle, style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
              ]),
            ),
          ]),
          const SizedBox(height: 24),
          Text(context.t('login.market'), style: NawyText.label),
          const SizedBox(height: 6),
          Row(children: [
            Expanded(
              child: DropdownButtonFormField<String>(
                key: const Key('country'),
                initialValue: session.country,
                items: [for (final c in AppConfig.countries) DropdownMenuItem(value: c, child: Text(context.t('market.$c')))],
                onChanged: (v) => v == null ? null : session.setCountry(v),
              ),
            ),
            const SizedBox(width: 8),
            SegmentedButton<String>(
              key: const Key('language'),
              showSelectedIcon: false,
              segments: const [ButtonSegment(value: 'en', label: Text('EN')), ButtonSegment(value: 'ar', label: Text('ع'))],
              selected: {session.locale},
              onSelectionChanged: (s) => session.setLocale(s.first),
            ),
          ]),
          const SizedBox(height: 16),
          Text(context.t('login.email'), style: NawyText.label),
          const SizedBox(height: 6),
          TextField(key: const Key('email'), controller: _email, keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.email], textDirection: TextDirection.ltr),
          const SizedBox(height: 16),
          Text(context.t('login.password'), style: NawyText.label),
          const SizedBox(height: 6),
          TextField(key: const Key('password'), controller: _password, obscureText: true, autofillHints: const [AutofillHints.password], onSubmitted: (_) => _submit()),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, key: const Key('login-error'), style: NawyText.body.copyWith(color: NawyColors.textDanger)),
          ],
          const SizedBox(height: 20),
          FilledButton(
            key: const Key('sign-in'),
            onPressed: _busy ? null : _submit,
            child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(context.t('login.signIn')),
          ),
          if (widget.badge != null) ...[
            const SizedBox(height: 16),
            Text(widget.badge!, style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          ],
        ]),
      ),
    );
  }
}

/// Settings: language (EN/AR, RTL), country shown, sign out.
class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key, required this.logoutPath, this.extra = const []});

  final String logoutPath;
  final List<Widget> extra;

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final name = session.profile?['name'] ?? (session.profile?['user'] is Map ? (session.profile!['user'] as Map)['name'] : null);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('settings.title'))),
      body: ListView(children: [
        if (name != null) ListTile(leading: const Icon(Icons.person_outline), title: Text(context.t('settings.signedInAs', {'name': name}))),
        ListTile(leading: const Icon(Icons.public), title: Text(context.t('settings.country')), subtitle: Text(context.t('market.${session.country}'))),
        ListTile(
          leading: const Icon(Icons.translate),
          title: Text(context.t('settings.language')),
          trailing: SegmentedButton<String>(
            key: const Key('settings-language'),
            showSelectedIcon: false,
            segments: const [ButtonSegment(value: 'en', label: Text('English')), ButtonSegment(value: 'ar', label: Text('العربية'))],
            selected: {session.locale},
            onSelectionChanged: (s) => session.setLocale(s.first),
          ),
        ),
        ...extra,
        const Divider(),
        ListTile(
          key: const Key('sign-out'),
          leading: const Icon(Icons.logout, color: NawyColors.textDanger),
          title: Text(context.t('settings.signOut'), style: NawyText.body.copyWith(color: NawyColors.textDanger)),
          onTap: () async {
            Navigator.of(context).popUntil((r) => r.isFirst);
            await session.signOut(logoutPath: logoutPath);
          },
        ),
      ]),
    );
  }
}
