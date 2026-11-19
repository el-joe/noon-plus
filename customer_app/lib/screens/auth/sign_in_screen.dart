import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/config.dart';
import '../../core/json.dart';
import '../../features/auth/social_sign_in.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

enum _Step { email, code, name, password, forgot, reset, social, phone, phoneCode, addEmail, addEmailCode }

/// Figma "43 Welcome", "44 Email code", "45 Create account" plus D9: the
/// email code stays the default; password sign-in, forgot / reset by code,
/// and Google / Apple (web flow, [SocialSignIn]) are the other options.
/// An old Nawy account with only a phone number (data-migration plan §5.2)
/// signs in once with an SMS code and must then add and confirm an email
/// (`me/email` → `me/email/verify`) before going on.
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
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  final _phone = TextEditingController();
  final _newEmail = TextEditingController();
  _Step _step = _Step.email;
  String? _error;
  String? _errorCode;
  String? _socialProvider;
  bool _busy = false;
  late final AppState _app;

  @override
  void initState() {
    super.initState();
    _app = AppScope.read(context);
    _email.text = _app.lastEmail ?? '';
    _app.addListener(_onAppChanged);
  }

  @override
  void dispose() {
    _app.removeListener(_onAppChanged);
    _email.dispose();
    _code.dispose();
    _name.dispose();
    _password.dispose();
    _confirm.dispose();
    _phone.dispose();
    _newEmail.dispose();
    super.dispose();
  }

  /// The social sign-in finishes outside this screen (app link → AppState).
  void _onAppChanged() {
    if (_step == _Step.social && _app.signedIn && mounted) {
      setState(() => _step = _Step.email);
      _finish();
    }
  }

  bool get _emailValid => RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(_email.text.trim());

  Future<void> _run(Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
      _errorCode = null;
    });
    try {
      await action();
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = errorMessage(context, e);
          _errorCode = e.code;
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _go(_Step step) => setState(() {
        _step = step;
        _error = null;
        _errorCode = null;
      });

  Future<void> _sendCode() => _run(() async {
        await _app.api.post('auth/email-code/send', body: {'email': _email.text.trim()});
        _code.clear();
        setState(() => _step = _Step.code);
      });

  /// Every sign-in answer is `{token, user: {id, name, email, has_password}}`.
  Future<Json> _signedIn(ApiResponse<dynamic> res) async {
    final data = asJson(res.data);
    final token = data.s('token');
    if (token == null) throw ApiException(500, 'invalid_response', 'Unexpected sign-in response.');
    final user = data.m('user') ?? {};
    await _app.signIn(token, user);
    return user;
  }

  Future<void> _verify() => _run(() async {
        final user = await _signedIn(await _app.api.post('auth/email-code/verify', body: {'email': _email.text.trim(), 'code': _code.text.trim(), 'device_name': AppConfig.deviceName}));
        final name = user.s('name') ?? '';
        if (name.isEmpty || name == _email.text.trim().split('@').first) {
          _name.text = '';
          setState(() => _step = _Step.name);
        } else {
          _finish();
        }
      });

  Future<void> _sendPhoneCode() => _run(() async {
        await _app.api.post('auth/phone-code/send', body: {'phone': _phone.text.trim()});
        _code.clear();
        setState(() => _step = _Step.phoneCode);
      });

  Future<void> _verifyPhone() => _run(() async {
        final user = await _signedIn(await _app.api.post('auth/phone-code/verify', body: {'phone': _phone.text.trim(), 'code': _code.text.trim(), 'device_name': AppConfig.deviceName}));
        if (user['requires_email'] == true) {
          _newEmail.clear();
          setState(() => _step = _Step.addEmail);
        } else {
          _finish();
        }
      });

  bool get _newEmailValid => RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(_newEmail.text.trim());

  Future<void> _sendEmailConfirmation() => _run(() async {
        await _app.api.post('me/email', body: {'email': _newEmail.text.trim()});
        _code.clear();
        setState(() => _step = _Step.addEmailCode);
      });

  Future<void> _confirmEmail() => _run(() async {
        final res = await _app.api.post('me/email/verify', body: {'email': _newEmail.text.trim(), 'code': _code.text.trim()});
        await _app.completeEmail(asJson(res.data));
        _finish();
      });

  Future<void> _passwordLogin() => _run(() async {
        await _signedIn(await _app.api.post('auth/password/login', body: {'email': _email.text.trim(), 'password': _password.text, 'device_name': AppConfig.deviceName}));
        _password.clear();
        _finish();
      });

  Future<void> _forgot() => _run(() async {
        await _app.api.post('auth/password/forgot', body: {'email': _email.text.trim()});
        _code.clear();
        _password.clear();
        _confirm.clear();
        setState(() => _step = _Step.reset);
      });

  Future<void> _reset() async {
    final local = passwordProblem(_password.text, _confirm.text);
    if (local != null) {
      setState(() => _error = context.t(local));
      return;
    }
    await _run(() async {
      final res = await _app.api.post('auth/password/reset', body: {
        'email': _email.text.trim(),
        'code': _code.text.trim(),
        'password': _password.text,
        'password_confirmation': _confirm.text,
        'device_name': AppConfig.deviceName,
      });
      final data = asJson(res.data);
      if (data.s('token') != null) {
        await _signedIn(res);
        _finish();
      } else {
        // Reset without a token: sign in with the new password.
        _go(_Step.password);
      }
    });
  }

  Future<void> _social(String provider) => _run(() async {
        setState(() {
          _socialProvider = provider;
          _step = _Step.social;
        });
        try {
          await SocialSignIn(_app).start(provider);
        } on ApiException {
          if (mounted) setState(() => _step = _Step.email);
          rethrow;
        }
      });

  Future<void> _saveName() => _run(() async {
        if (_name.text.trim().length >= 2) {
          final res = await _app.api.patch('me', body: {'name': _name.text.trim(), 'locale': _app.locale});
          _app.updateUser(asJson(res.data));
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
              _Step.password => _passwordStep(),
              _Step.forgot => _forgotStep(),
              _Step.reset => _resetStep(),
              _Step.social => _socialStep(),
              _Step.phone => _phoneStep(),
              _Step.phoneCode => _phoneCodeStep(),
              _Step.addEmail => _addEmailStep(),
              _Step.addEmailCode => _addEmailCodeStep(),
            },
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(_error!, key: const ValueKey('sign-in-error'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
              ),
            // No password yet, or a wrong one: offer the email code.
            if (_step == _Step.password && _errorCode == 'invalid_credentials')
              TextButton(key: const ValueKey('sign-in-use-code'), onPressed: _busy ? null : _sendCode, child: Text(context.t('auth.useCode'))),
          ]),
        ),
      );

  Widget _spinner() => const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2));

  Widget _emailField({bool autofocus = false}) => TextField(
        key: const ValueKey('sign-in-email'),
        controller: _email,
        autofocus: autofocus,
        keyboardType: TextInputType.emailAddress,
        autofillHints: const [AutofillHints.email],
        textInputAction: TextInputAction.go,
        textDirection: TextDirection.ltr,
        onChanged: (_) => setState(() {}),
        onSubmitted: (_) => _emailValid && _step == _Step.email ? _sendCode() : null,
        decoration: InputDecoration(labelText: context.t('auth.email'), hintText: 'name@example.com'),
      );

  Widget _passwordField(TextEditingController controller, String label, {Key? key, VoidCallback? onSubmitted, List<String> hints = const [AutofillHints.password]}) => TextField(
        key: key,
        controller: controller,
        obscureText: true,
        autofillHints: hints,
        onChanged: (_) => setState(() {}),
        onSubmitted: onSubmitted == null ? null : (_) => onSubmitted(),
        decoration: InputDecoration(labelText: label),
      );

  Widget _emailStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.welcome'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.welcomeHint'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        _emailField(),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('sign-in-send'),
          onPressed: _busy || !_emailValid ? null : _sendCode,
          child: _busy ? _spinner() : Text(context.t('auth.sendCode')),
        ),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          key: const ValueKey('sign-in-with-password'),
          onPressed: _busy ? null : () => _go(_Step.password),
          icon: const Icon(Icons.lock_outline_rounded, size: 18),
          label: Text(context.t('auth.withPassword')),
        ),
        if (SocialSignIn.supported) ...[
          const SizedBox(height: 16),
          Row(children: [
            const Expanded(child: Divider()),
            Padding(padding: const EdgeInsets.symmetric(horizontal: 12), child: Text(context.t('auth.or'), style: NawyText.caption.copyWith(color: NawyColors.textMuted))),
            const Expanded(child: Divider()),
          ]),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            key: const ValueKey('sign-in-google'),
            onPressed: _busy ? null : () => _social('google'),
            icon: const Icon(Icons.g_mobiledata_rounded, size: 26),
            label: Text(context.t('auth.google')),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('sign-in-apple'),
            onPressed: _busy ? null : () => _social('apple'),
            icon: const Icon(Icons.apple_rounded, size: 22),
            label: Text(context.t('auth.apple')),
          ),
        ],
        const SizedBox(height: 12),
        TextButton(key: const ValueKey('sign-in-with-phone'), onPressed: _busy ? null : () => _go(_Step.phone), child: Text(context.t('auth.usePhone'), textAlign: TextAlign.center)),
        TextButton(key: const ValueKey('sign-in-guest'), onPressed: _guest, child: Text(context.t('auth.guest'))),
        const SizedBox(height: 8),
        Text(context.t('auth.terms'), textAlign: TextAlign.center, style: NawyText.caption.copyWith(color: NawyColors.textMuted)),
      ]);

  Widget _codeStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.codeTitle'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.codeHint', {'email': _email.text.trim()}), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        _codeField(),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('sign-in-verify'),
          onPressed: _busy || _code.text.trim().length < 4 ? null : _verify,
          child: _busy ? _spinner() : Text(context.t('auth.verify')),
        ),
        TextButton(onPressed: _busy ? null : _sendCode, child: Text(context.t('auth.resend'))),
        TextButton(onPressed: () => _go(_Step.email), child: Text(context.t('auth.changeEmail'))),
      ]);

  Widget _codeField() => TextField(
        key: const ValueKey('sign-in-code'),
        controller: _code,
        keyboardType: TextInputType.number,
        autofillHints: const [AutofillHints.oneTimeCode],
        maxLength: 8,
        textAlign: TextAlign.center,
        style: NawyText.h2.copyWith(letterSpacing: 8),
        onChanged: (_) => setState(() {}),
        decoration: const InputDecoration(counterText: '', hintText: '••••••'),
      );

  Widget _phoneStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.phoneTitle'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.phoneHint'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        TextField(
          key: const ValueKey('sign-in-phone'),
          controller: _phone,
          autofocus: true,
          keyboardType: TextInputType.phone,
          autofillHints: const [AutofillHints.telephoneNumber],
          textDirection: TextDirection.ltr,
          onChanged: (_) => setState(() {}),
          decoration: InputDecoration(labelText: context.t('auth.phone'), hintText: '+971 50 123 4567'),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('sign-in-phone-send'),
          onPressed: _busy || _phone.text.trim().length < 6 ? null : _sendPhoneCode,
          child: _busy ? _spinner() : Text(context.t('auth.sendSmsCode')),
        ),
        TextButton(onPressed: () => _go(_Step.email), child: Text(context.t('auth.back'))),
      ]);

  Widget _phoneCodeStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.codeTitle'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.phoneCodeHint', {'phone': _phone.text.trim()}), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        _codeField(),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('sign-in-phone-verify'),
          onPressed: _busy || _code.text.trim().length < 4 ? null : _verifyPhone,
          child: _busy ? _spinner() : Text(context.t('auth.verify')),
        ),
        TextButton(onPressed: _busy ? null : _sendPhoneCode, child: Text(context.t('auth.resend'))),
        TextButton(onPressed: () => _go(_Step.phone), child: Text(context.t('auth.changePhone'))),
      ]);

  Widget _addEmailStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.addEmailTitle'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.addEmailHint'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        TextField(
          key: const ValueKey('add-email'),
          controller: _newEmail,
          autofocus: true,
          keyboardType: TextInputType.emailAddress,
          autofillHints: const [AutofillHints.email],
          textDirection: TextDirection.ltr,
          onChanged: (_) => setState(() {}),
          decoration: InputDecoration(labelText: context.t('auth.email'), hintText: 'name@example.com'),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('add-email-send'),
          onPressed: _busy || !_newEmailValid ? null : _sendEmailConfirmation,
          child: _busy ? _spinner() : Text(context.t('auth.sendCode')),
        ),
      ]);

  Widget _addEmailCodeStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.codeTitle'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.codeHint', {'email': _newEmail.text.trim()}), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        _codeField(),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('add-email-verify'),
          onPressed: _busy || _code.text.trim().length < 4 ? null : _confirmEmail,
          child: _busy ? _spinner() : Text(context.t('auth.addEmailConfirm')),
        ),
        TextButton(onPressed: _busy ? null : _sendEmailConfirmation, child: Text(context.t('auth.resend'))),
        TextButton(onPressed: () => _go(_Step.addEmail), child: Text(context.t('auth.changeEmail'))),
      ]);

  Widget _passwordStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.passwordTitle'), style: NawyText.h2),
        const SizedBox(height: 20),
        _emailField(),
        const SizedBox(height: 12),
        _passwordField(_password, context.t('auth.password'), key: const ValueKey('sign-in-password'), onSubmitted: () => _emailValid && _password.text.isNotEmpty ? _passwordLogin() : null),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('sign-in-password-submit'),
          onPressed: _busy || !_emailValid || _password.text.isEmpty ? null : _passwordLogin,
          child: _busy ? _spinner() : Text(context.t('auth.signIn')),
        ),
        Align(
          alignment: AlignmentDirectional.centerEnd,
          child: TextButton(key: const ValueKey('sign-in-forgot'), onPressed: () => _go(_Step.forgot), child: Text(context.t('auth.forgot'))),
        ),
        TextButton(onPressed: () => _go(_Step.email), child: Text(context.t('auth.back'))),
      ]);

  Widget _forgotStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.forgotTitle'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.forgotHint'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        _emailField(),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('sign-in-forgot-send'),
          onPressed: _busy || !_emailValid ? null : _forgot,
          child: _busy ? _spinner() : Text(context.t('auth.sendResetCode')),
        ),
        TextButton(onPressed: () => _go(_Step.password), child: Text(context.t('auth.back'))),
      ]);

  Widget _resetStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('auth.resetTitle'), style: NawyText.h2),
        const SizedBox(height: 4),
        Text(context.t('auth.resetHint', {'email': _email.text.trim()}), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 20),
        _codeField(),
        const SizedBox(height: 12),
        _passwordField(_password, context.t('auth.newPassword'), key: const ValueKey('reset-password'), hints: const [AutofillHints.newPassword]),
        const SizedBox(height: 12),
        _passwordField(_confirm, context.t('auth.confirmPassword'), key: const ValueKey('reset-confirm'), hints: const [AutofillHints.newPassword], onSubmitted: _reset),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('reset-submit'),
          onPressed: _busy || _code.text.trim().length < 4 || _password.text.isEmpty ? null : _reset,
          child: _busy ? _spinner() : Text(context.t('auth.resetSubmit')),
        ),
        TextButton(onPressed: _busy ? null : _forgot, child: Text(context.t('auth.resend'))),
      ]);

  Widget _socialStep() {
    final provider = context.t('auth.provider.${_socialProvider ?? 'google'}');
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      const SizedBox(height: 24),
      const Center(child: SizedBox(width: 32, height: 32, child: CircularProgressIndicator(strokeWidth: 2.5))),
      const SizedBox(height: 20),
      Text(context.t('auth.socialWaiting', {'provider': provider}), key: const ValueKey('sign-in-social-waiting'), style: NawyText.h3, textAlign: TextAlign.center),
      const SizedBox(height: 8),
      Text(context.t('auth.socialWaitingHint', {'provider': provider}), style: NawyText.body.copyWith(color: NawyColors.textSecondary), textAlign: TextAlign.center),
      const SizedBox(height: 20),
      OutlinedButton(onPressed: _busy || _socialProvider == null ? null : () => _social(_socialProvider!), child: Text(context.t('auth.socialAgain'))),
      TextButton(onPressed: () => _go(_Step.email), child: Text(context.t('common.cancel'))),
    ]);
  }

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

/// A local check before `auth/password/reset` or `me/password`: null when
/// fine, otherwise the l10n key of the problem (the API checks it again).
String? passwordProblem(String password, String confirmation) {
  if (password.length < 8) return 'auth.passwordShort';
  if (password != confirmation) return 'auth.passwordMismatch';
  return null;
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
