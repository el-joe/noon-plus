import 'dart:async';

import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:url_launcher/url_launcher.dart';

import '../data/travel_api.dart';

/// In-app promotion purchase (D14): the card page opens in an in-app
/// browser tab (Custom Tab / SFSafariViewController); the gateway returns
/// to `nawytravel://promotions/return?payment=…`, which brings the app back.
/// The result is always asked from the API (`POST …/verify`), never read
/// from the link. Tests replace [launch] / [closeTab].
class PromotionCheckout {
  PromotionCheckout._();

  /// Opens the gateway page in an in-app browser tab.
  static Future<bool> Function(Uri uri) launch = (uri) => launchUrl(uri, mode: LaunchMode.inAppBrowserView);

  /// Closes that tab when the app comes back (no-op where unsupported).
  static Future<void> Function() closeTab = () async {
    try {
      await closeInAppWebView();
    } catch (_) {
      // Nothing open, or the platform cannot close it.
    }
  };

  /// Gateway return references from deep links, for the open payment screen.
  static final returns = StreamController<String>.broadcast();

  /// Whether a payment screen is open and listening.
  static bool get hasPaymentScreen => returns.hasListener;
}

/// Catches the gateway return deep link before the app's router does (it is
/// registered in `main()` ahead of the MaterialApp, so it sees
/// `didPushRouteInformation` first) and hands the payment reference to the
/// travel shell ([links]); other routes go on as usual.
class PromotionReturnLinks with WidgetsBindingObserver {
  PromotionReturnLinks._();

  static final instance = PromotionReturnLinks._();

  static final links = StreamController<String>.broadcast();

  static bool _installed = false;

  static void install() {
    if (_installed) return;
    WidgetsBinding.instance.addObserver(instance);
    _installed = true;
  }

  static void uninstall() {
    WidgetsBinding.instance.removeObserver(instance);
    _installed = false;
  }

  @override
  Future<bool> didPushRouteInformation(RouteInformation routeInformation) async {
    final reference = promotionReturnReference(routeInformation.uri);
    if (reference == null) return false;
    links.add(reference);
    return true;
  }
}

/// Step 1: choose one of the agency's live trips and a promotion option
/// (price VAT included, duration), then pay.
class PromoteTripScreen extends StatefulWidget {
  const PromoteTripScreen({super.key, this.packageId});

  /// Preselected trip.
  final int? packageId;

  @override
  State<PromoteTripScreen> createState() => _PromoteTripScreenState();
}

class _PromoteTripScreenState extends State<PromoteTripScreen> {
  int? _package;
  int? _product;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _package = widget.packageId;
  }

  Future<void> _buy() async {
    final package = _package;
    final product = _product;
    if (package == null || product == null) return;
    setState(() => _busy = true);
    final navigator = Navigator.of(context);
    try {
      final started = await TravelApi(context.api).buyPromotion(packageId: package, productId: product);
      if (!mounted) return;
      await navigator.pushReplacement(MaterialPageRoute(builder: (_) => PromotionPaymentScreen(start: started)));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _busy = false);
      showSnack(context, e.code == 'transition_refused' ? e.message : apiErrorMessage(context, e));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('promote.title'))),
        body: AsyncBody<Json>(
          load: () => TravelApi(context.api).promotionOptions(),
          refreshable: false,
          builder: (context, data, _) {
            final packages = data.l('packages');
            final products = data.l('products');
            if (packages.isEmpty || products.isEmpty) {
              return EmptyView(
                icon: Icons.campaign_outlined,
                title: context.t(packages.isEmpty ? 'promote.noTrips' : 'promote.noOptions'),
                subtitle: context.t(packages.isEmpty ? 'promote.noTripsHint' : 'promote.noOptionsHint'),
              );
            }
            final chosen = products.where((p) => p.i('id') == _product).firstOrNull;
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Text(context.t('promote.trip'), style: NawyText.h4),
              const SizedBox(height: 4),
              RadioGroup<int>(
                groupValue: _package,
                onChanged: (v) => setState(() => _package = v),
                child: Column(children: [
                  for (final p in packages)
                    RadioListTile<int>(key: Key('promote-package-${p.i('id')}'), value: p.i('id') ?? 0, title: Text(p.str('title')), contentPadding: EdgeInsets.zero),
                ]),
              ),
              const SizedBox(height: 12),
              Text(context.t('promote.option'), style: NawyText.h4),
              const SizedBox(height: 4),
              RadioGroup<int>(
                groupValue: _product,
                onChanged: (v) => setState(() => _product = v),
                child: Column(children: [
                  for (final p in products)
                    RadioListTile<int>(
                      key: Key('promote-product-${p.i('id')}'),
                      value: p.i('id') ?? 0,
                      contentPadding: EdgeInsets.zero,
                      title: Text(p.str('name')),
                      subtitle: Text(context.t('promotions.days', {'count': p.i('duration_days') ?? 0})),
                      secondary: Text(money(context, p['price']), style: NawyText.bodyMedium),
                    ),
                ]),
              ),
              if (chosen != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    context.t('promote.total', {'amount': money(context, chosen['price']), 'vat': money(context, chosen['vat'])}),
                    key: const Key('promote-total'),
                    style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
                  ),
                ),
              const SizedBox(height: 8),
              Text(context.t('promote.cardHint'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
              const SizedBox(height: 16),
              FilledButton.icon(
                key: const Key('promote-pay'),
                onPressed: _busy || _package == null || _product == null ? null : _buy,
                icon: const Icon(Icons.lock_outline),
                label: Text(chosen == null ? context.t('promote.pay') : context.t('promote.payAmount', {'amount': money(context, chosen['price'])})),
              ),
            ]);
          },
        ),
      );
}

/// Step 2: the payment in the in-app tab and its result. Started with the
/// buy / pay answer ([start]) or, for a deep link that arrived with no
/// payment screen open (e.g. after a cold start), only the [reference].
class PromotionPaymentScreen extends StatefulWidget {
  const PromotionPaymentScreen({super.key, this.start, this.reference, this.recheckEvery = const Duration(seconds: 5)});

  final Json? start;
  final String? reference;

  /// Automatic re-check while the gateway has not confirmed yet.
  final Duration recheckEvery;

  @override
  State<PromotionPaymentScreen> createState() => _PromotionPaymentScreenState();
}

class _PromotionPaymentScreenState extends State<PromotionPaymentScreen> with WidgetsBindingObserver {
  Json? _state;
  bool _busy = false;
  bool _tabOpen = false;
  String? _error;
  Timer? _timer;
  StreamSubscription<String>? _returns;

  int? get _promotionId => _state?.m('promotion')?.i('id');
  String get _result => _state?.str('result') ?? 'pending';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _returns = PromotionCheckout.returns.stream.listen((reference) => _verify(reference: reference, fromReturn: true));
    final start = widget.start;
    if (start != null) {
      _state = start;
      WidgetsBinding.instance.addPostFrameCallback((_) => _openTab());
    } else if (widget.reference != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _resolve(widget.reference!));
    }
    _schedule();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _returns?.cancel();
    _timer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Back from the payment tab (deep link or the user closed it): ask the API.
    if (state == AppLifecycleState.resumed && _tabOpen) _verify(fromReturn: true);
  }

  void _schedule() {
    _timer?.cancel();
    if (_result == 'pending' && !_tabOpen) {
      _timer = Timer.periodic(widget.recheckEvery, (_) {
        if (!_busy && _result == 'pending' && !_tabOpen) _verify();
      });
    }
  }

  Future<void> _openTab() async {
    final url = _state?.m('payment')?.s('redirect_url');
    if (url == null || url.isEmpty || _result != 'pending') {
      setState(_schedule);
      return;
    }
    final uri = Uri.tryParse(url);
    final opened = uri != null && await PromotionCheckout.launch(uri);
    if (!mounted) return;
    setState(() {
      _tabOpen = opened;
      _error = opened ? null : context.t('checkout.openFailed');
      _schedule();
    });
  }

  /// A deep link with no screen behind it: find the promotion waiting for that payment.
  Future<void> _resolve(String reference) async {
    setState(() => _busy = true);
    final api = TravelApi(context.api);
    try {
      final rows = (await api.promotions()).data.where((p) => p.str('status') == 'pending_payment' || p.b('can_pay')).toList();
      Json? found;
      for (final row in rows) {
        final answer = await api.verifyPromotion(row.i('id') ?? 0, payment: reference);
        if (answer.m('payment')?.s('reference') == reference) {
          found = answer;
          break;
        }
      }
      // Already settled promotions are no longer pending: check the newest ones.
      if (found == null) {
        for (final row in (await api.promotions()).data.take(5)) {
          final answer = await api.promotion(row.i('id') ?? 0);
          if (answer.m('payment')?.s('reference') == reference) {
            found = answer;
            break;
          }
        }
      }
      if (!mounted) return;
      setState(() {
        _busy = false;
        _state = found;
        _error = found == null ? context.t('checkout.notFound') : null;
        _schedule();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = apiErrorMessage(context, e);
      });
    }
  }

  Future<void> _verify({String? reference, bool fromReturn = false}) async {
    final id = _promotionId;
    if (fromReturn) await PromotionCheckout.closeTab();
    if (id == null) {
      if (reference != null && mounted) await _resolve(reference);
      return;
    }
    if (!mounted) return;
    setState(() {
      _busy = true;
      if (fromReturn) _tabOpen = false;
    });
    try {
      final answer = await TravelApi(context.api).verifyPromotion(id, payment: reference ?? _state?.m('payment')?.s('reference'));
      if (!mounted) return;
      setState(() {
        _busy = false;
        _state = answer;
        _error = null;
        _schedule();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = apiErrorMessage(context, e);
        _schedule();
      });
    }
  }

  Future<void> _payAgain() async {
    final id = _promotionId;
    if (id == null) return;
    setState(() => _busy = true);
    try {
      final answer = await TravelApi(context.api).payPromotion(id);
      if (!mounted) return;
      setState(() {
        _busy = false;
        _state = answer;
        _error = null;
      });
      await _openTab();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.code == 'transition_refused' ? e.message : apiErrorMessage(context, e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = _state;
    final promotion = state?.m('promotion') ?? const <String, dynamic>{};
    final payment = state?.m('payment');
    final result = _result;
    final ml = MaterialLocalizations.of(context);
    String? date(String key) {
      final d = DateTime.tryParse(promotion.str(key));
      return d == null ? null : ml.formatMediumDate(d.toLocal());
    }

    final (IconData icon, Color color, String title, String body) = switch (result) {
      'active' => (Icons.check_circle_outline, NawyColors.textSuccess, context.t('checkout.active'), context.t('checkout.activeBody', {'date': date('ends_at') ?? '—'})),
      'scheduled' => (Icons.event_available_outlined, NawyColors.textSuccess, context.t('checkout.scheduled'), context.t('checkout.scheduledBody', {'start': date('starts_at') ?? '—', 'date': date('ends_at') ?? '—'})),
      'failed' => (Icons.error_outline, NawyColors.textDanger, context.t('checkout.failed'), payment?.s('failure_message') ?? context.t('checkout.failedBody')),
      'paid_inactive' => (Icons.support_agent_outlined, NawyColors.textBrand, context.t('checkout.paidInactive'), context.t('checkout.paidInactiveBody')),
      'closed' => (Icons.block_outlined, NawyColors.textSecondary, context.t('checkout.closed'), context.t('checkout.closedBody')),
      _ => (Icons.hourglass_top_outlined, NawyColors.textBrand, context.t(_tabOpen ? 'checkout.inTab' : 'checkout.pending'), context.t(_tabOpen ? 'checkout.inTabBody' : 'checkout.pendingBody')),
    };

    return Scaffold(
      appBar: AppBar(title: Text(context.t('checkout.title'))),
      body: state == null && _busy
          ? const Center(child: CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              if (state != null) ...[
                Icon(icon, size: 56, color: color),
                const SizedBox(height: 12),
                Text(title, key: Key('checkout-result-$result'), style: NawyText.h3, textAlign: TextAlign.center),
                const SizedBox(height: 8),
                Text(body, key: const Key('checkout-body'), style: NawyText.body, textAlign: TextAlign.center),
                const SizedBox(height: 16),
                Card(
                  child: ListTile(
                    title: Text(promotion.m('package')?.str('title', '—') ?? '—'),
                    subtitle: Text([promotion.str('number'), if (promotion.m('product')?.s('name') != null) promotion.m('product')!.str('name')].where((s) => s.isNotEmpty).join(' · ')),
                    trailing: Text(money(context, promotion['amount']), style: NawyText.bodyMedium),
                  ),
                ),
              ],
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Text(_error!, key: const Key('checkout-error'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger), textAlign: TextAlign.center),
                ),
              const SizedBox(height: 16),
              if (result == 'pending' && state != null) ...[
                if (_tabOpen || (payment?.s('redirect_url') ?? '').isNotEmpty)
                  OutlinedButton.icon(
                    key: const Key('checkout-open'),
                    onPressed: _busy ? null : _openTab,
                    icon: const Icon(Icons.open_in_new),
                    label: Text(context.t('checkout.openAgain')),
                  ),
                const SizedBox(height: 8),
                FilledButton.icon(
                  key: const Key('checkout-check'),
                  onPressed: _busy ? null : () => _verify(fromReturn: _tabOpen),
                  icon: const Icon(Icons.refresh),
                  label: Text(context.t('checkout.checkAgain')),
                ),
              ],
              if (result == 'failed' && promotion.b('can_pay'))
                FilledButton.icon(
                  key: const Key('checkout-retry'),
                  onPressed: _busy ? null : _payAgain,
                  icon: const Icon(Icons.credit_card),
                  label: Text(context.t('checkout.tryAgain')),
                ),
              if (result != 'pending')
                TextButton(
                  key: const Key('checkout-done'),
                  onPressed: () => Navigator.of(context).maybePop(),
                  child: Text(context.t('checkout.done')),
                ),
            ]),
    );
  }
}
