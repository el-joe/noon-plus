import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../core/storage/secure_store.dart';
import '../../features/page_builder/vertical_cards.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';
import '../checkout/checkout_screen.dart';

/// Estimate for a booking request: Σ price_per_person × travellers per room
/// type of the departure (D8: an estimate, paid to the agency directly).
Money? travelEstimate(Json departure, Map<String, int> travellers) {
  Money? total;
  for (final room in departure.l('rooms')) {
    final count = travellers[room.str('type')] ?? 0;
    final price = Money.fromJson(room['price_per_person']);
    if (count <= 0 || price == null) continue;
    final line = price.times(count);
    total = total == null ? line : total + line;
  }
  return total;
}

/// One readable line for itinerary / policy entries (strings or objects).
String describeEntry(BuildContext context, dynamic entry) {
  if (entry is String) return entry;
  if (entry is Map) {
    final m = entry.cast<String, dynamic>();
    if (m.containsKey('days_before')) return context.t('travel.policyLine', {'days': m.s('days_before'), 'percent': m.s('refund_percent')});
    final day = m.s('day');
    final title = m.s('title') ?? m.s('name') ?? m.s('text') ?? '';
    final body = m.s('description') ?? m.s('body');
    return [if (day != null) context.t('travel.day', {'n': day}), title, ?body].where((s) => s.isNotEmpty).join(' · ');
  }
  return '$entry';
}

List<dynamic> _entries(dynamic value) => value is List ? value : (value is Map ? value.values.toList() : const []);

/// Figma "48 Travel": search + trip types + packages (`GET travel/packages`).
class TravelHome extends StatefulWidget {
  const TravelHome({super.key, this.embedded = false, this.args = const {}});

  final bool embedded;
  final Map<String, dynamic> args;

  @override
  State<TravelHome> createState() => _TravelHomeState();
}

class _TravelHomeState extends State<TravelHome> {
  late String? _destination = widget.args['destination'] as String?;
  String? _type;
  final _q = TextEditingController();

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    final body = AsyncBody<List<Json>>(
      key: ValueKey('$_destination-$_type-${AppScope.of(context).market}'),
      load: () => api.getList('travel/packages', query: {'destination': _destination, 'trip_type': _type}).then((r) => r.data),
      builder: (context, packages, reload) {
        final types = {for (final p in packages) if (p.s('trip_type') != null) p.str('trip_type')}.toList();
        return RefreshIndicator(
          onRefresh: reload,
          child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: TextField(
                controller: _q,
                textInputAction: TextInputAction.search,
                onSubmitted: (v) => setState(() => _destination = v.trim().isEmpty ? null : v.trim()),
                decoration: InputDecoration(hintText: context.t('travel.whereTo'), prefixIcon: const Icon(Icons.travel_explore_rounded)),
              ),
            ),
            SizedBox(
              height: 52,
              child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8), children: [
                ChoiceChip(label: Text(context.t('common.all')), selected: _type == null, onSelected: (_) => setState(() => _type = null)),
                for (final t in {...types, ?_type})
                  Padding(padding: const EdgeInsetsDirectional.only(start: 8), child: ChoiceChip(label: Text(context.l10n.status(t)), selected: _type == t, onSelected: (_) => setState(() => _type = t))),
              ]),
            ),
            ListTile(
              leading: const Icon(Icons.confirmation_number_outlined),
              title: Text(context.t('travel.myBookings')),
              trailing: const Icon(Icons.chevron_right_rounded),
              onTap: () => Navigator.of(context).pushNamed(Routes.myBookings),
            ),
            if (packages.isEmpty) EmptyView(icon: Icons.flight_takeoff_rounded, title: context.t('travel.empty')),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: LayoutBuilder(builder: (context, c) {
                final w = c.maxWidth > 600 ? (c.maxWidth - 12) / 2 : c.maxWidth;
                return Wrap(spacing: 12, runSpacing: 16, children: [for (final p in packages) TravelCard(package: p, width: w)]);
              }),
            ),
          ]),
        );
      },
    );
    if (widget.embedded) return body;
    return Scaffold(appBar: AppBar(title: Text(context.t('travel.title'))), body: body);
  }
}

/// Trip detail: departures with seats, itinerary, inclusions, policy,
/// "Request to book" (no payment, D8).
class TravelPackageScreen extends StatefulWidget {
  const TravelPackageScreen({super.key, required this.slug});

  final String slug;

  @override
  State<TravelPackageScreen> createState() => _TravelPackageScreenState();
}

class _TravelPackageScreenState extends State<TravelPackageScreen> {
  int? _departure;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(),
        body: AsyncBody<Json>(
          load: () => AppScope.read(context).api.getObject('travel/packages/${enc(widget.slug)}'),
          builder: (context, p, _) {
            final departures = p.l('departures');
            _departure ??= departures.where((d) => (d.i('seats_left') ?? 0) > 0).firstOrNull?.i('id');
            final from = Money.fromJson(p['from_price']);
            return Column(children: [
              Expanded(
                child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
                  AspectRatio(aspectRatio: 16 / 9, child: NetImage(p.m('image')?.s('url') ?? p.s('cover_url'), icon: Icons.flight_takeoff_rounded)),
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(p.str('title'), style: NawyText.h3),
                      Text([p.s('agency'), p.s('destination'), if (p.i('nights') != null) context.t('travel.nights', {'n': p.i('nights')})].whereType<String>().join(' · '),
                          style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                      if (from != null) Text(context.t('travel.fromPerPerson', {'price': from.format(locale: context.l10n.locale)}), style: NawyText.h4.copyWith(color: NawyColors.textSuccess)),
                      if (p.s('description') != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(p.str('description'), style: NawyText.body)),
                    ]),
                  ),
                  SectionCard(
                    title: context.t('travel.departures'),
                    child: Column(children: [
                      if (departures.isEmpty) Text(context.t('travel.noDepartures'), style: NawyText.bodySm),
                      for (final d in departures)
                        RadioListTileLite(
                          key: ValueKey('departure-${d.i('id')}'),
                          selected: _departure == d.i('id'),
                          title: '${shortDate(context, d.s('departs_on'))} – ${shortDate(context, d.s('returns_on'))}',
                          subtitle: (d.i('seats_left') ?? 0) > 0 ? context.t('travel.seatsLeft', {'n': d.i('seats_left')}) : context.t('travel.full'),
                          onTap: (d.i('seats_left') ?? 0) > 0 ? () => setState(() => _departure = d.i('id')) : () {},
                        ),
                    ]),
                  ),
                  for (final (key, label) in [('itinerary', 'travel.itinerary'), ('inclusions', 'travel.inclusions'), ('exclusions', 'travel.exclusions'), ('cancellation_policy', 'travel.policy')])
                    if (_entries(p[key]).isNotEmpty)
                      SectionCard(
                        title: context.t(label),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          for (final e in _entries(p[key])) Padding(padding: const EdgeInsets.only(bottom: 4), child: Text('•  ${describeEntry(context, e)}', style: NawyText.body)),
                        ]),
                      ),
                ]),
              ),
              SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: FilledButton(
                    key: const ValueKey('travel-request'),
                    onPressed: _departure == null ? null : () => Navigator.of(context).pushNamed(Routes.travelRequest, arguments: {'package': p, 'departure': _departure}),
                    child: Text(context.t('travel.requestToBook')),
                  ),
                ),
              ),
            ]);
          },
        ),
      );
}

/// Figma "55 Travel request to book": contact details (guests welcome),
/// departure, rooms and travellers, optional names, notes, estimate and the
/// "pay the agency directly" notice. No payment on Nawy (D8).
class TravelRequestScreen extends StatefulWidget {
  const TravelRequestScreen({super.key, required this.package, required this.departureId});

  final Json package;
  final int departureId;

  @override
  State<TravelRequestScreen> createState() => _TravelRequestScreenState();
}

class _TravelRequestScreenState extends State<TravelRequestScreen> {
  late int _departureId = widget.departureId;
  final Map<String, int> _travellers = {};
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _notes = TextEditingController();
  final List<TextEditingController> _names = [];
  bool _agree = false;
  Map<String, String> _errors = {};

  Json get _departure => widget.package.l('departures').firstWhere((d) => d.i('id') == _departureId, orElse: () => {});
  int get _total => _travellers.values.fold(0, (s, v) => s + v);

  @override
  void initState() {
    super.initState();
    final app = AppScope.read(context);
    _name.text = app.user?.s('name') ?? '';
    _email.text = app.user?.s('email') ?? '';
    _phone.text = app.user?.s('phone') ?? app.phoneCode;
    final rooms = _departure.l('rooms');
    if (rooms.isNotEmpty) _travellers[rooms.first.str('type')] = 1;
  }

  @override
  void dispose() {
    for (final c in [_name, _email, _phone, _notes, ..._names]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_agree) {
      setState(() => _errors = {'agree': context.t('travel.agreeRequired')});
      return;
    }
    if (_total == 0) {
      setState(() => _errors = {'rooms': context.t('travel.pickTravellers')});
      return;
    }
    final app = AppScope.read(context);
    try {
      final res = await app.api.post('travel/departures/$_departureId/bookings', body: {
        'name': _name.text.trim(),
        'email': _email.text.trim(),
        'phone': _phone.text.replaceAll(' ', '').trim(),
        'rooms': [for (final e in _travellers.entries) if (e.value > 0) {'room_type': e.key, 'travellers': e.value}],
        'traveller_names': [for (final c in _names.take(_total)) c.text.trim()],
        'message': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
        'submit': true,
      });
      final booking = asJson(res.data);
      final token = res.headers['x-booking-token'] ?? booking.s('access_token');
      if (token != null) await rememberGuestBooking(app, booking.str('number'), token);
      if (!mounted) return;
      Navigator.of(context).pushReplacementNamed(Routes.travelSent, arguments: {'booking': booking});
    } on ApiException catch (e) {
      setState(() => _errors = {...e.fieldErrors, if (e.fieldErrors.isEmpty) 'form': e.message});
    }
  }

  @override
  Widget build(BuildContext context) {
    final departure = _departure;
    final estimate = travelEstimate(departure, _travellers);
    while (_names.length < _total) {
      _names.add(TextEditingController());
    }
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('travel.requestToBook'))),
      body: ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
        SectionCard(
          child: Row(children: [
            SizedBox(width: 64, height: 64, child: NetImage(widget.package.m('image')?.s('url') ?? widget.package.s('cover_url'), radius: NawyRadius.md, icon: Icons.flight_rounded)),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(widget.package.str('title'), style: NawyText.bodyMedium),
                Text(widget.package.str('agency'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
              ]),
            ),
          ]),
        ),
        SectionCard(
          title: context.t('travel.yourDetails'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (!app.signedIn) Text(context.t('travel.guestOk'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
            const SizedBox(height: 8),
            TextField(key: const ValueKey('travel-name'), controller: _name, decoration: InputDecoration(labelText: '${context.t('profile.name')} *', errorText: _errors['name'])),
            const SizedBox(height: 10),
            TextField(key: const ValueKey('travel-email'), controller: _email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: '${context.t('auth.email')} *', errorText: _errors['email'])),
            const SizedBox(height: 10),
            TextField(
              key: const ValueKey('travel-phone'),
              controller: _phone,
              keyboardType: TextInputType.phone,
              textDirection: TextDirection.ltr,
              decoration: InputDecoration(labelText: '${context.t('address.phone')} *', helperText: context.t('travel.phoneHint', {'code': app.phoneCode}), errorText: _errors['phone']),
            ),
          ]),
        ),
        SectionCard(
          title: context.t('travel.tripDetails'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final d in widget.package.l('departures'))
                ChoiceChip(
                  label: Text('${shortDate(context, d.s('departs_on'))} · ${context.t('travel.seatsLeft', {'n': d.i('seats_left')})}'),
                  selected: _departureId == d.i('id'),
                  onSelected: (d.i('seats_left') ?? 0) > 0
                      ? (_) => setState(() {
                            _departureId = d.i('id')!;
                            _travellers.removeWhere((k, _) => !_departure.l('rooms').any((r) => r.s('type') == k));
                          })
                      : null,
                ),
            ]),
            const SizedBox(height: 12),
            for (final room in departure.l('rooms'))
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(context.l10n.status(room.s('type')), style: NawyText.bodyMedium),
                      Text(context.t('travel.perPerson', {'price': formatMoney(room['price_per_person'], locale: context.l10n.locale)}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                    ]),
                  ),
                  QuantityStepper(
                    key: ValueKey('room-${room.str('type')}'),
                    value: _travellers[room.str('type')] ?? 0,
                    max: (departure.i('seats_left') ?? 20).clamp(0, 20),
                    onChanged: (v) => setState(() => _travellers[room.str('type')] = v),
                  ),
                ]),
              ),
            if (_errors['rooms'] != null) Text(_errors['rooms']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
          ]),
        ),
        if (_total > 0)
          SectionCard(
            title: context.t('travel.travellerNames'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(context.t('travel.noPassports'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
              for (var i = 0; i < _total; i++)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: TextField(controller: _names[i], decoration: InputDecoration(labelText: context.t('travel.traveller', {'n': i + 1}), hintText: context.t('common.optional'))),
                ),
            ]),
          ),
        SectionCard(
          title: context.t('travel.notes'),
          child: TextField(controller: _notes, maxLines: 3, decoration: InputDecoration(hintText: context.t('travel.notesHint'))),
        ),
        SectionCard(
          title: context.t('travel.estimate'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            for (final room in departure.l('rooms'))
              if ((_travellers[room.str('type')] ?? 0) > 0)
                InfoRow('${_travellers[room.str('type')]} × ${context.l10n.status(room.s('type'))}',
                    Money.fromJson(room['price_per_person'])!.times(_travellers[room.str('type')]!).format(locale: context.l10n.locale)),
            InfoRow(context.t('travel.estimatedTotal'), estimate?.format(locale: context.l10n.locale) ?? '—', bold: true),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: NawyColors.bgInfoSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t('travel.payAgency'), style: NawyText.bodyMedium),
                Text(context.t('travel.payAgencyHint', {'agency': widget.package.str('agency')}), style: NawyText.bodySm),
              ]),
            ),
          ]),
        ),
        CheckboxListTile(
          key: const ValueKey('travel-agree'),
          value: _agree,
          onChanged: (v) => setState(() => _agree = v ?? false),
          title: Text(context.t('travel.agree')),
          subtitle: _errors['agree'] == null ? null : Text(_errors['agree']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
          controlAffinity: ListTileControlAffinity.leading,
        ),
        if (_errors['form'] != null) Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: Text(_errors['form']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(context.t('travel.noPaymentNow'), textAlign: TextAlign.center, style: NawyText.caption.copyWith(color: NawyColors.textSuccess)),
            const SizedBox(height: 6),
            BusyButton(key: const ValueKey('travel-send'), label: context.t('travel.send'), onPressed: _submit),
          ]),
        ),
      ]),
    );
  }
}

/// Guest booking tokens kept on the device so "My bookings" can reopen them.
Future<void> rememberGuestBooking(AppState app, String number, String token) =>
    app.pushRecent(StoreKeys.guestBookings, {'number': number, 'token': token, 'market': app.market}, idKey: 'number', max: 30);

Future<String?> guestBookingToken(AppState app, String number) async {
  final list = await app.readList(StoreKeys.guestBookings);
  return list.firstWhere((b) => b.s('number') == number, orElse: () => {}).s('token');
}

/// Figma "56 Travel request sent".
class TravelSentScreen extends StatelessWidget {
  const TravelSentScreen({super.key, required this.booking});

  final Json booking;

  @override
  Widget build(BuildContext context) {
    final hold = DateTime.tryParse(booking.s('hold_expires_at') ?? '');
    return Scaffold(
      appBar: AppBar(automaticallyImplyLeading: false),
      body: ListView(padding: const EdgeInsets.all(24), children: [
        const Icon(Icons.mark_email_read_rounded, size: 80, color: NawyColors.bgSuccess),
        const SizedBox(height: 16),
        Text(context.t('travel.sentTitle'), textAlign: TextAlign.center, style: NawyText.h2),
        const SizedBox(height: 8),
        Text(context.t('travel.sentHint', {'number': booking.str('number'), 'agency': booking.str('agency')}), textAlign: TextAlign.center, style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        if (hold != null && hold.isAfter(DateTime.now()))
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [Text('${context.t('travel.seatsHeld')} '), Countdown(endsAt: hold)]),
          ),
        const SizedBox(height: 16),
        SectionCard(
          margin: EdgeInsets.zero,
          child: Column(children: [
            InfoRow(context.t('travel.reference'), booking.str('number')),
            InfoRow(context.t('travel.status'), context.l10n.status(booking.s('status'))),
            InfoRow(context.t('travel.estimatedTotal'), formatMoney(booking['quoted_total'], locale: context.l10n.locale)),
          ]),
        ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: () => Navigator.of(context).pushReplacementNamed(Routes.travelBooking, arguments: {'number': booking.str('number')}),
          child: Text(context.t('travel.viewStatus')),
        ),
        TextButton(onPressed: () => Navigator.of(context).popUntil((r) => r.settings.name == Routes.shell || r.isFirst), child: Text(context.t('common.done'))),
      ]),
    );
  }
}

const bookingSteps = ['requested', 'confirmed', 'completed'];

/// Figma "58 Booking status": status chips, details, cancel, change request.
class TravelBookingScreen extends StatefulWidget {
  const TravelBookingScreen({super.key, required this.number, this.token});

  final String number;
  final String? token;

  @override
  State<TravelBookingScreen> createState() => _TravelBookingScreenState();
}

class _TravelBookingScreenState extends State<TravelBookingScreen> {
  int _gen = 0;
  String? _token;

  Future<Map<String, String>> _headers() async {
    final app = AppScope.read(context);
    _token ??= widget.token ?? await guestBookingToken(app, widget.number);
    if (widget.token != null) await rememberGuestBooking(app, widget.number, widget.token!);
    return {'X-Booking-Token': ?_token};
  }

  Future<void> _post(String path, Map<String, dynamic> body, String success) async {
    final api = AppScope.read(context).api;
    final headers = await _headers();
    if (!mounted) return;
    final ok = await guarded(context, () => api.post('travel/bookings/${enc(widget.number)}/$path', body: body, headers: headers), success: success);
    if (ok != null && mounted) setState(() => _gen++);
  }

  Future<void> _cancel() async {
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.t('travel.cancelTitle')),
        content: TextField(controller: reason, decoration: InputDecoration(labelText: ctx.t('orders.cancelReason'))),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(ctx.t('common.back'))),
          TextButton(onPressed: () => Navigator.of(ctx).pop(true), child: Text(ctx.t('travel.cancelBooking'))),
        ],
      ),
    );
    final text = reason.text.trim();
    reason.dispose();
    if (ok == true && mounted) await _post('cancel', {'reason': text.length >= 3 ? text : 'Cancelled by customer'}, context.t('travel.cancelled'));
  }

  Future<void> _modify() async {
    String type = 'special_request';
    final note = TextEditingController();
    final ok = await showNawySheet<bool>(
      context,
      title: context.t('travel.requestChange'),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => ListView(padding: const EdgeInsets.all(16), shrinkWrap: true, children: [
          for (final t in const ['change_departure', 'name_correction', 'special_request', 'other'])
            RadioListTileLite(selected: type == t, title: ctx.t('travel.change.$t'), onTap: () => setSheet(() => type = t)),
          TextField(controller: note, maxLines: 3, decoration: InputDecoration(labelText: ctx.t('travel.changeDetails'))),
          const SizedBox(height: 12),
          FilledButton(onPressed: () => Navigator.of(ctx).pop(true), child: Text(ctx.t('travel.sendChange'))),
        ]),
      ),
    );
    final text = note.text.trim();
    note.dispose();
    if (ok == true && mounted) await _post('modifications', {'type': type, 'details': {'note': text}}, context.t('travel.changeSent'));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.number)),
        body: AsyncBody<Json>(
          key: ValueKey(_gen),
          load: () async => AppScope.read(context).api.getObject('travel/bookings/${enc(widget.number)}', headers: await _headers()),
          builder: (context, b, reload) {
            final status = b.str('status');
            final step = bookingSteps.indexOf(status);
            final open = const {'held', 'requested', 'confirmed'}.contains(status);
            final hold = DateTime.tryParse(b.s('hold_expires_at') ?? '');
            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
                SectionCard(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(b.m('package')?.str('title') ?? '', style: NawyText.h4),
                    Text(b.str('agency'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                    const SizedBox(height: 12),
                    Wrap(spacing: 6, runSpacing: 6, children: [
                      for (final (i, s) in bookingSteps.indexed)
                        Pill(context.l10n.status(s),
                            background: i <= step ? NawyColors.bgSuccessSubtle : NawyColors.bgPage, foreground: i <= step ? NawyColors.textSuccess : NawyColors.textMuted, icon: i <= step ? Icons.check_rounded : null),
                      if (step < 0) Pill.status(context, status),
                    ]),
                    if (status == 'held' && hold != null && hold.isAfter(DateTime.now()))
                      Row(children: [Text('${context.t('travel.seatsHeld')} '), Countdown(endsAt: hold)]),
                    if (b.s('decision_reason') != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(b.str('decision_reason'), style: NawyText.bodySm)),
                    if (b.s('confirmation_number') != null) InfoRow(context.t('travel.confirmation'), b.str('confirmation_number')),
                  ]),
                ),
                SectionCard(
                  title: context.t('travel.tripDetails'),
                  child: Column(children: [
                    InfoRow(context.t('travel.dates'), '${shortDate(context, b.s('departs_on'))} – ${shortDate(context, b.s('returns_on'))}'),
                    for (final r in b.l('rooms')) InfoRow(context.l10n.status(r.s('room_type')), '${r.i('travellers')} ×'),
                    InfoRow(context.t('travel.seats'), '${b.i('seats') ?? ''}'),
                    InfoRow(context.t('travel.estimatedTotal'), formatMoney(b['quoted_total'], locale: context.l10n.locale), bold: true),
                    Text(context.t('travel.payAgency'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                  ]),
                ),
                SectionCard(
                  title: context.t('travel.contact'),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text([b.m('contact')?.s('name'), b.m('contact')?.s('email'), b.m('contact')?.s('phone')].whereType<String>().join('\n'), style: NawyText.body),
                    if ((b['travellers'] as List?)?.whereType<String>().isNotEmpty ?? false) ...[
                      const SizedBox(height: 8),
                      Text(context.t('travel.travellerNames'), style: NawyText.bodyMedium),
                      for (final n in (b['travellers'] as List).whereType<String>()) Text(n, style: NawyText.bodySm),
                    ],
                  ]),
                ),
                if (open)
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: Row(children: [
                      Expanded(child: OutlinedButton(onPressed: _modify, child: Text(context.t('travel.requestChange')))),
                      const SizedBox(width: 8),
                      Expanded(child: OutlinedButton(onPressed: _cancel, child: Text(context.t('travel.cancelBooking')))),
                    ]),
                  ),
              ]),
            );
          },
        ),
      );
}

/// Figma "57 My bookings": the account's bookings plus guest bookings made on
/// this device, and "Resend my booking link".
class MyBookingsScreen extends StatefulWidget {
  const MyBookingsScreen({super.key});

  @override
  State<MyBookingsScreen> createState() => _MyBookingsScreenState();
}

class _MyBookingsScreenState extends State<MyBookingsScreen> {
  Future<List<Json>> _load() async {
    final app = AppScope.read(context);
    final out = <String, Json>{};
    if (app.signedIn) {
      for (final b in (await app.api.getList('travel/bookings')).data) {
        out[b.str('number')] = b;
      }
    }
    for (final g in await app.readList(StoreKeys.guestBookings)) {
      if (g.s('market') != app.market || out.containsKey(g.str('number'))) continue;
      try {
        out[g.str('number')] = await app.api.getObject('travel/bookings/${enc(g.str('number'))}', headers: {'X-Booking-Token': g.str('token')});
      } catch (_) {}
    }
    return out.values.toList();
  }

  Future<void> _resend() async {
    final email = TextEditingController(text: AppScope.read(context).user?.s('email') ?? '');
    await showNawySheet<void>(
      context,
      title: context.t('travel.resendTitle'),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(ctx.t('travel.resendHint'), style: NawyText.bodySm),
          const SizedBox(height: 8),
          TextField(controller: email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: ctx.t('auth.email'))),
          const SizedBox(height: 12),
          BusyButton(
            label: ctx.t('travel.resend'),
            onPressed: () async {
              final ok = await guarded(ctx, () => AppScope.read(ctx).api.post('travel/bookings/resend-link', body: {'email': email.text.trim()}), success: ctx.t('travel.resent'));
              if (ok != null && ctx.mounted) Navigator.of(ctx).pop();
            },
          ),
        ]),
      ),
    );
    email.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('travel.myBookings')), actions: [IconButton(tooltip: context.t('travel.resendTitle'), onPressed: _resend, icon: const Icon(Icons.forward_to_inbox_rounded))]),
        body: AsyncBody<List<Json>>(
          load: _load,
          isEmpty: (l) => l.isEmpty,
          empty: EmptyView(
            icon: Icons.flight_takeoff_rounded,
            title: context.t('travel.noBookings'),
            subtitle: context.t('travel.noBookingsHint'),
            actionLabel: context.t('travel.resendTitle'),
            onAction: _resend,
          ),
          builder: (context, bookings, reload) => RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.only(top: 12), children: [
              for (final b in bookings)
                InkWell(
                  onTap: () => Navigator.of(context).pushNamed(Routes.travelBooking, arguments: {'number': b.str('number')}),
                  child: SectionCard(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [Expanded(child: Text(b.m('package')?.str('title') ?? b.str('number'), style: NawyText.bodyMedium)), Pill.status(context, b.s('status'))]),
                      Text('${b.str('number')} · ${shortDate(context, b.s('departs_on'))}', style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                      Text(formatMoney(b['quoted_total'], locale: context.l10n.locale), style: NawyText.label),
                    ]),
                  ),
                ),
            ]),
          ),
        ),
      );
}

