import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/vendor_api.dart';
import 'shell.dart';
import 'support.dart';

/// Shows [VendorShell] once the store has signed the current Seller
/// Agreement, otherwise the [AgreementScreen] (QC-017). Also switches to the
/// agreement when any call answers 403 `seller_agreement_required` (a new
/// version was published while the app was open).
class VendorGate extends StatefulWidget {
  const VendorGate({super.key});

  @override
  State<VendorGate> createState() => _VendorGateState();
}

class _VendorGateState extends State<VendorGate> {
  late final ApiClient _api = context.api;
  late Future<SellerAgreement> _future = VendorApi(_api).agreement();

  @override
  void initState() {
    super.initState();
    _api.errorListeners.add(_onError);
  }

  @override
  void dispose() {
    _api.errorListeners.remove(_onError);
    super.dispose();
  }

  void _onError(ApiException e) {
    if (e.status == 403 && e.code == agreementRequiredCode && mounted) _reload();
  }

  void _reload() {
    final next = VendorApi(_api).agreement();
    setState(() {
      _future = next;
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<SellerAgreement>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Scaffold(body: LoadingView());
          if (snap.hasError) return Scaffold(body: Center(child: ErrorView(error: snap.error!, onRetry: _reload)));
          final agreement = snap.data!;
          if (agreement.blocking) return AgreementScreen(agreement: agreement, onSigned: _reload, onOutdated: _reload);
          return const VendorShell();
        },
      );
}

/// Click-wrap Seller Agreement: the text, the signer's full name and
/// "I agree" (`POST ops/vendor/agreement/sign`). Staff who are not the owner
/// see that the owner must sign.
class AgreementScreen extends StatefulWidget {
  const AgreementScreen({super.key, required this.agreement, required this.onSigned, required this.onOutdated});

  final SellerAgreement agreement;
  final VoidCallback onSigned;
  final VoidCallback onOutdated;

  @override
  State<AgreementScreen> createState() => _AgreementScreenState();
}

class _AgreementScreenState extends State<AgreementScreen> {
  final _name = TextEditingController();
  bool _accept = false;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final profileName = context.session.profile?['name'];
    if (profileName is String) _name.text = profileName;
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  bool get _ready => _accept && _name.text.trim().length >= 2 && widget.agreement.versionId != null && !_busy;

  Future<void> _sign() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await VendorApi(context.api).signAgreement(widget.agreement.versionId!, _name.text.trim());
      if (!mounted) return;
      showSnack(context, context.t('agreement.signed'));
      widget.onSigned();
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.status == 409 && e.code == 'version_outdated') {
        showSnack(context, apiErrorMessage(context, e));
        widget.onOutdated();
        return;
      }
      setState(() => _error = apiErrorMessage(context, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.agreement;
    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('agreement.title')),
        actions: [
          // Support stays reachable before the agreement is signed (QC-007).
          IconButton(
            key: const Key('agreement-support'),
            tooltip: context.t('agreement.support'),
            icon: const Icon(Icons.support_agent_outlined),
            onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SupportListScreen())),
          ),
          IconButton(
            key: const Key('agreement-sign-out'),
            tooltip: context.t('settings.signOut'),
            icon: const Icon(Icons.logout),
            onPressed: () => context.session.signOut(logoutPath: VendorApi.logoutPath),
          ),
        ],
      ),
      body: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
        Text(context.t('agreement.intro'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 12),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (a.title.isNotEmpty) Text(a.title, key: const Key('agreement-title'), style: NawyText.h3),
              const SizedBox(height: 8),
              ConstrainedBox(
                constraints: const BoxConstraints(maxHeight: 360),
                child: SingleChildScrollView(child: SelectableText(a.text, key: const Key('agreement-text'), style: NawyText.body)),
              ),
            ]),
          ),
        ),
        const SizedBox(height: 16),
        if (!a.canSign)
          Container(
            key: const Key('agreement-owner-only'),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: NawyColors.bgWarningSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
            child: Text(context.t('agreement.ownerOnly'), style: NawyText.body),
          )
        else ...[
          Text(context.t('agreement.fullName'), style: NawyText.label),
          const SizedBox(height: 6),
          TextField(key: const Key('agreement-name'), controller: _name, textCapitalization: TextCapitalization.words, onChanged: (_) => setState(() {})),
          const SizedBox(height: 8),
          CheckboxListTile(
            key: const Key('agreement-accept'),
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: _accept,
            onChanged: (v) => setState(() => _accept = v ?? false),
            title: Text(context.t('agreement.accept')),
          ),
          if (_error != null) ...[
            Text(_error!, key: const Key('agreement-error'), style: NawyText.body.copyWith(color: NawyColors.textDanger)),
            const SizedBox(height: 8),
          ],
          FilledButton(
            key: const Key('agreement-sign'),
            onPressed: _ready ? _sign : null,
            child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(context.t('agreement.sign')),
          ),
        ],
      ]),
    );
  }
}
