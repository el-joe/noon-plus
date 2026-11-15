import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/config.dart';
import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

enum _Step { email, code, name }

/// Figma "43 Welcome", "44 Email code", "45 Create account": email sign-in by
/// code (DV1, ADR 5) with an optional guest mode.
class SignInScreen extends StatefulWidget {
  const SignInScreen({super.key, this.next, this.nextArgs, this.pop = false});

  /// Route to open after signing in (from a routing guard).
  final String? next;
  final Object? nextArgs;

  /// Pop back to the caller instead (inline gates: wishlist, review…).
  final bool pop;

  @override
  State<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends State<SignInScreen> {
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _name = TextEditingController();
  _Step _step = _Step.email;
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _email.dispose();
    _code.dispose();
    _name.dispose();
    super.dispose();
  }

  bool get _emailValid => RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(_email.text.trim());

  Future<void> _run(Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
    } on ApiException catch (e) {
      setState(() => _error = e.fieldErrors.values.firstOrNull ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sendCode() => _run(() async {
        await AppScope.read(context).api.post('auth/email-code/send', body: {'email': _email.text.trim()});
        setState(() => _step = _Step.code);
      });

  Future<void> _verify() => _run(() async {
        final app = AppScope.read(context);
        final res = await app.api.post('auth/email-code/verify', body: {'email': _email.text.trim(), 'code': _code.text.trim(), 'device_name': AppConfig.deviceName});
        final data = asJson(res.data);
        final token = data.s('token');
        if (token == null) throw ApiException(500, 'invalid_response', 'Unexpected sign-in response.');
        final user = data.m('user') ?? {};
        await app.signIn(token, user);
        final name = user.s('name') ?? '';
        if (name.isEmpty || name == _email.text.trim().split('@').first) {
          _name.text = '';
          setState(() => _step = _Step.name);
        } else {
          _finish();
        }
      });

  Future<void> _saveName() => _run(() async {
        final app = AppScope.read(context);
        if (_name.text.trim().length >= 2) {
          final res = await app.api.patch('me', body: {'name': _name.text.trim(), 'locale': app.locale});
          app.updateUser(asJson(res.data));
        }
        _finish();
      });

  void _finish() {
    if (!mounted) return;
    final nav = Navigator.of(context);
    if (widget.pop && nav.canPop()) {
      nav.pop(true);
    } else if (widget.next != null) {
      nav.pushReplacementNamed(widget.next!, arguments: widget.nextArgs);
    } else if (nav.canPop()) {
      nav.pop(true);
    } else {
      nav.pushReplacementNamed(Routes.shell);
    }
  }

  void _guest() {
    final nav = Navigator.of(context);
    if (nav.canPop()) {
      nav.pop(false);
    } else {
      nav.pushReplacementNamed(Routes.shell);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(backgroundColor: NawyColors.bgPage),
        body: SafeArea(
          child: ListView(padding: const EdgeInsets.all(20), children: [
            switch (_step) {
              _Step.email => _emailStep(),
              _Step.code => _codeStep(),
              _Step.name => _nameStep(),
            },
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(_error!, key: const ValueKey('sign-in-error'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
              ),
          ]),
        ),
      );

  Widget _emailStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.welcome'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.welcomeHint'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        TextField(
          key: const ValueKey('sign-in-email'),
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          autofillHints: const [AutofillHints.email],
          textInputAction: TextInputAction.go,
          onChanged: (_) => setState(() {}),
          onSubmitted: (_) => _emailValid ? _sendCode() : null,
          decoration: InputDecoration(labelText: context.t('auth.email'), hintText: 'name@example.com'),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('sign-in-send'),
          onPressed: _busy || !_emailValid ? null : _sendCode,
          child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(context.t('auth.sendCode')),
        ),
        const SizedBox(height: 12),
        OutlinedButton(key: const ValueKey('sign-in-guest'), onPressed: _guest, child: Text(context.t('auth.guest'))),
        const SizedBox(height: 16),
        Text(context.t('auth.terms'), textAlign: TextAlign.center, style: NawyText.caption.copyWith(color: NawyColors.textMuted)),
      ]);

  Widget _codeStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.codeTitle'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.codeHint', {'email': _email.text.trim()}), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        TextField(
          key: const ValueKey('sign-in-code'),
          controller: _code,
          keyboardType: TextInputType.number,
          autofillHints: const [AutofillHints.oneTimeCode],
          maxLength: 8,
          textAlign: TextAlign.center,
          style: NawyText.h2.copyWith(letterSpacing: 8),
          onChanged: (_) => setState(() {}),
          decoration: InputDecoration(counterText: '', hintText: '••••••'),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('sign-in-verify'),
          onPressed: _busy || _code.text.trim().length < 4 ? null : _verify,
          child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(context.t('auth.verify')),
        ),
        TextButton(onPressed: _busy ? null : _sendCode, child: Text(context.t('auth.resend'))),
        TextButton(onPressed: () => setState(() => _step = _Step.email), child: Text(context.t('auth.changeEmail'))),
      ]);

  Widget _nameStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.createTitle'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.createHint'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        TextField(controller: _name, textCapitalization: TextCapitalization.words, decoration: InputDecoration(labelText: context.t('profile.name'))),
        const SizedBox(height: 16),
        BusyButton(label: context.t('common.continue'), onPressed: _saveName),
        TextButton(onPressed: _finish, child: Text(context.t('common.skip'))),
      ]);
}

/// Inline sign-in gate shown inside a tab (Account/Wishlist when signed out).
class SignInPrompt extends StatelessWidget {
  const SignInPrompt({super.key, this.title, this.subtitle});

  final String? title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) => EmptyView(
        icon: Icons.person_outline_rounded,
        title: title ?? context.t('auth.signInTitle'),
        subtitle: subtitle ?? context.t('auth.signInHint'),
        actionLabel: context.t('auth.signIn'),
        onAction: () => requireSignIn(context),
      );
}
