import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart' show LatLng;
import 'package:nawy_core/device.dart' show AttachmentPicker, PhoneLink, PickedMedia, UploadRules, rejectionMessages;

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../core/platform/platform_bridge.dart';
import '../../features/page_builder/vertical_cards.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/paged_list.dart';
import '../../ui/widgets/states.dart';
import 'listing_map.dart';

/// Flatten the classified category tree with depth (post-ad picker).
List<(Json, int)> flattenCategories(List<Json> tree, [int depth = 0]) => [
      for (final c in tree) ...[(c, depth), ...flattenCategories(c.l('children'), depth + 1)],
    ];

String specValue(dynamic v) {
  if (v == null) return '';
  if (v is List) return v.map(specValue).join(', ');
  if (v is bool) return v ? '✓' : '✗';
  if (v is Map) return v.values.map(specValue).join(' ');
  return '$v';
}

/// Photo limits of a classified category (`GET classifieds/categories` and
/// `…/{slug}/attributes`): `max_photos` per ad and `photo_max_kb` per photo,
/// both inherited by the API, which enforces them on
/// `POST classifieds/ads/{n}/photos`. Falls back to 20 / 8192 (the
/// `classified.max_photos` / `classified.photo_max_kb` defaults).
class AdPhotoLimits {
  const AdPhotoLimits({this.maxPhotos = defaultMaxPhotos, this.photoMaxKb = defaultPhotoMaxKb});

  /// The attributes answer wins over the category node; missing or
  /// non-positive values use the defaults.
  factory AdPhotoLimits.of(Json? attributes, [Json? category]) {
    int? pick(String key) {
      for (final source in [attributes, category]) {
        final v = source?.i(key);
        if (v != null && v > 0) return v;
      }
      return null;
    }

    return AdPhotoLimits(maxPhotos: pick('max_photos') ?? defaultMaxPhotos, photoMaxKb: pick('photo_max_kb') ?? defaultPhotoMaxKb);
  }

  static const defaultMaxPhotos = 20;
  static const defaultPhotoMaxKb = 8192;

  final int maxPhotos;
  final int photoMaxKb;

  /// Rules for the photos still to add next to [uploaded] ones.
  UploadRules rules({int uploaded = 0}) => UploadRules.adPhotos(maxCount: math.max(0, maxPhotos - uploaded), maxKb: photoMaxKb);
}

/// Submits a draft ad for review (`POST classifieds/ads/{n}/submit`); when
/// its category needs the seller agreement, asks the user to accept it and
/// signs it. Returns the ad's number and status.
Future<Json> submitAd(BuildContext context, ApiClient api, String number) async {
  final submitted = asJson((await api.post('classifieds/ads/${enc(number)}/submit')).data);
  final contract = submitted.m('contract');
  if (contract != null && context.mounted) {
    final signed = await signAdContract(context, api, number, contract);
    if (signed != null) return {...submitted, ...signed};
  }
  return submitted;
}

/// The seller-agreement click-wrap of an ad (`POST classifieds/ads/{n}/contract`
/// with the account name); null when the user cancels.
Future<Json?> signAdContract(BuildContext context, ApiClient api, String number, Json contract) async {
  final name = AppScope.read(context).user?.s('name') ?? '';
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(contract.str('title')),
      content: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(ctx.t('souq.contractHint')),
          if (contract.s('text') != null) ...[const SizedBox(height: 12), Text(contract.str('text'), style: NawyText.bodySm)],
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(ctx.t('common.cancel'))),
        TextButton(key: const ValueKey('ad-contract-accept'), onPressed: () => Navigator.of(ctx).pop(true), child: Text(ctx.t('contracts.accept'))),
      ],
    ),
  );
  if (ok != true) return null;
  return asJson((await api.post('classifieds/ads/${enc(number)}/contract', body: {'full_name': name})).data);
}

/// Figma "47 Souq" / "49 Property": Open Souq classifieds (or the property
/// vertical) — categories, search, sort, ads (`GET classifieds/ads`), and a
/// map of the ads with a location (`GET classifieds/map`, FR-CLS-5).
class SouqHome extends StatefulWidget {
  const SouqHome({super.key, this.embedded = false, this.vertical, this.args = const {}});

  final bool embedded;
  final String? vertical;
  final Map<String, dynamic> args;

  @override
  State<SouqHome> createState() => _SouqHomeState();
}

class _SouqHomeState extends State<SouqHome> {
  late String? _q = widget.args['q'] as String?;
  late String? _category = widget.args['category'] as String?;
  String _sort = 'recommended';
  late String? _purpose = widget.args['purpose'] as String?;
  late bool _map = widget.args['view'] == 'map';
  final _search = TextEditingController();

  bool get _property => widget.vertical == 'property';

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    final filters = <String, dynamic>{
      'q': _q,
      'category': _category,
      'vertical': widget.vertical,
      if (_purpose != null) 'attr[purpose]': _purpose,
    };
    final body = Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
        child: Row(children: [
          Expanded(
            child: TextField(
              controller: _search,
              textInputAction: TextInputAction.search,
              onSubmitted: (v) => setState(() => _q = v.trim().isEmpty ? null : v.trim()),
              decoration: InputDecoration(hintText: context.t(_property ? 'property.search' : 'souq.search'), prefixIcon: const Icon(Icons.search_rounded)),
            ),
          ),
          const SizedBox(width: 8),
          PopupMenuButton<String>(
            tooltip: context.t('plp.sortBy'),
            icon: const Icon(Icons.swap_vert_rounded),
            onSelected: (v) => setState(() => _sort = v),
            itemBuilder: (ctx) => [for (final s in const ['recommended', 'newest', 'price_asc', 'price_desc']) CheckedPopupMenuItem(value: s, checked: _sort == s, child: Text(ctx.t('sort.$s')))],
          ),
          IconButton(
            key: const ValueKey('souq-view-toggle'),
            tooltip: context.t(_map ? 'souq.listView' : 'souq.mapView'),
            onPressed: () => setState(() => _map = !_map),
            icon: Icon(_map ? Icons.view_list_rounded : Icons.map_outlined),
          ),
        ]),
      ),
      OnceFuture<List<Json>>(
        load: () => api.getList('classifieds/categories').then((r) => r.data),
        builder: (context, snap) {
          final roots = (snap.data ?? const <Json>[]).where((c) => _property ? c.s('vertical') == 'property' : c.s('vertical') != 'property').toList();
          final chips = [for (final r in roots) ...[r, ...r.l('children')]];
          return SizedBox(
            height: 52,
            child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8), children: [
              if (_property)
                for (final p in const ['rent', 'sale'])
                  Padding(
                    padding: const EdgeInsetsDirectional.only(end: 8),
                    child: ChoiceChip(label: Text(context.t('property.$p')), selected: _purpose == p, onSelected: (_) => setState(() => _purpose = _purpose == p ? null : p)),
                  ),
              ChoiceChip(label: Text(context.t('common.all')), selected: _category == null, onSelected: (_) => setState(() => _category = null)),
              for (final c in chips)
                Padding(
                  padding: const EdgeInsetsDirectional.only(start: 8),
                  child: ChoiceChip(label: Text(c.str('name')), selected: _category == c.s('slug'), onSelected: (_) => setState(() => _category = c.s('slug'))),
                ),
            ]),
          );
        },
      ),
      Expanded(
        child: _map
            ? ListingsMapView(key: ValueKey('map-$_q-$_category-$_purpose-${widget.vertical}'), query: filters, onShowList: () => setState(() => _map = false))
            : PagedList(
                key: ValueKey('$_q-$_category-$_sort-$_purpose-${widget.vertical}'),
                load: (page) => api.getList('classifieds/ads', query: {
                  ...filters,
                  'sort': _sort == 'recommended' ? null : _sort,
                  'page': page,
                }),
                header: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                  child: Row(children: [
                    Expanded(child: OutlinedButton.icon(onPressed: () => Navigator.of(context).pushNamed(Routes.postAd), icon: const Icon(Icons.add_rounded), label: Text(context.t('souq.postAd')))),
                    const SizedBox(width: 8),
                    Expanded(child: OutlinedButton.icon(onPressed: () => Navigator.of(context).pushNamed(Routes.myAds), icon: const Icon(Icons.campaign_outlined), label: Text(context.t('souq.myAds')))),
                  ]),
                ),
                empty: EmptyView(icon: _property ? Icons.apartment_rounded : Icons.storefront_outlined, title: context.t('souq.empty')),
                itemBuilder: (context, ad) => Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 12), child: AdCard(ad: ad, horizontal: true)),
              ),
      ),
    ]);
    if (widget.embedded) return body;
    return Scaffold(appBar: AppBar(title: Text(context.t(_property ? 'property.title' : 'souq.title'))), body: body);
  }
}

/// Ad / property detail: gallery, price, facts, specs, seller, D8 viewing /
/// reserve requests, chat, phone reveal, favourite, report.
class AdDetailScreen extends StatefulWidget {
  const AdDetailScreen({super.key, required this.number});

  final String number;

  @override
  State<AdDetailScreen> createState() => _AdDetailScreenState();
}

class _AdDetailScreenState extends State<AdDetailScreen> {
  String? _phone;
  bool _fav = false;

  Future<void> _favourite() async {
    if (!await requireSignIn(context) || !mounted) return;
    final api = AppScope.read(context).api;
    final next = !_fav;
    final ok = await guarded(context, () => next ? api.post('classifieds/favourites/${enc(widget.number)}') : api.delete('classifieds/favourites/${enc(widget.number)}'),
        success: context.t(next ? 'wishlist.added' : 'wishlist.removed'));
    if (ok != null && mounted) setState(() => _fav = next);
  }

  Future<void> _chat(Json ad) async {
    final app = AppScope.read(context);
    final message = TextEditingController();
    final name = TextEditingController(text: app.user?.s('name') ?? '');
    final email = TextEditingController(text: app.user?.s('email') ?? '');
    final phone = TextEditingController(text: app.user?.s('phone') ?? app.phoneCode);
    await showNawySheet<void>(
      context,
      title: context.t('souq.messageSeller'),
      builder: (ctx) => ListView(padding: const EdgeInsets.all(16), shrinkWrap: true, children: [
        if (!app.signedIn) ...[
          TextField(controller: name, decoration: InputDecoration(labelText: ctx.t('profile.name'))),
          const SizedBox(height: 8),
          TextField(controller: email, decoration: InputDecoration(labelText: ctx.t('auth.email'))),
          const SizedBox(height: 8),
          TextField(controller: phone, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: ctx.t('address.phone'))),
          const SizedBox(height: 8),
        ],
        TextField(controller: message, maxLines: 4, decoration: InputDecoration(labelText: ctx.t('souq.message'))),
        const SizedBox(height: 12),
        BusyButton(
          label: ctx.t('qa.send'),
          onPressed: () async {
            final ok = await guarded(
              ctx,
              () => app.api.post('classifieds/ads/${enc(widget.number)}/inquiries', body: {'name': name.text.trim(), 'email': email.text.trim(), 'phone': phone.text.trim(), 'message': message.text.trim()}),
              success: ctx.t('souq.messageSent'),
            );
            if (ok != null && ctx.mounted) Navigator.of(ctx).pop();
          },
        ),
      ]),
    );
    for (final c in [message, name, email, phone]) {
      c.dispose();
    }
  }

  Future<void> _report() async {
    final reason = await showNawySheet<String>(
      context,
      title: context.t('souq.report'),
      builder: (ctx) => ListView(shrinkWrap: true, children: [
        for (final r in const ['spam', 'fraud', 'wrong_category', 'offensive', 'sold', 'other']) ListTile(title: Text(ctx.l10n.status(r)), onTap: () => Navigator.of(ctx).pop(r)),
      ]),
    );
    if (reason == null || !mounted) return;
    await guarded(context, () => AppScope.read(context).api.post('classifieds/ads/${enc(widget.number)}/reports', body: {'reason': reason}), success: context.t('souq.reported'));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(actions: [
          IconButton(tooltip: context.t('wishlist.title'), onPressed: _favourite, icon: Icon(_fav ? Icons.favorite_rounded : Icons.favorite_border_rounded, color: _fav ? NawyColors.bgDanger : null)),
          IconButton(tooltip: context.t('souq.report'), onPressed: _report, icon: const Icon(Icons.flag_outlined)),
        ]),
        body: AsyncBody<Json>(
          load: () => AppScope.read(context).api.getObject('classifieds/ads/${enc(widget.number)}'),
          builder: (context, ad, _) {
            final media = ad.l('media').where((m) => m.s('type') != 'video').toList();
            final specs = {...?ad.m('key_specs'), ...?ad.m('specs')};
            final property = ad.m('property');
            final seller = ad.m('seller') ?? const {};
            final shop = seller.m('shop');
            final broker = seller.m('broker');
            final requests = ad.strings('request_types');
            return Column(children: [
              Expanded(
                child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
                  AspectRatio(
                    aspectRatio: 4 / 3,
                    child: media.isEmpty
                        ? NetImage(ad.s('image'), icon: property != null ? Icons.apartment_rounded : Icons.storefront_outlined)
                        : PageView(children: [for (final m in media) NetImage(m.s('url'), fit: BoxFit.contain, background: NawyColors.bgSurface)]),
                  ),
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(formatMoney(ad['price'], locale: context.l10n.locale, empty: context.t('souq.priceOnRequest')), style: NawyText.h2),
                      if (ad.b('is_negotiable')) Text(context.t('souq.negotiable'), style: NawyText.caption.copyWith(color: NawyColors.textSuccess)),
                      Text(ad.str('title'), style: NawyText.h4),
                      Text([ad.s('area'), ad.s('city'), shortDate(context, ad.s('posted_at'))].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
                          style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                      if (ad.s('status') != 'active') Padding(padding: const EdgeInsets.only(top: 6), child: Pill.status(context, ad.s('status'))),
                    ]),
                  ),
                  if (property != null)
                    SectionCard(
                      title: context.t('property.facts'),
                      child: Column(children: [
                        for (final e in property.entries)
                          if (e.value != null && e.value is! Map && e.value is! List) InfoRow(context.l10n.status(e.key), context.l10n.status('${e.value}')),
                      ]),
                    ),
                  if (specs.isNotEmpty)
                    SectionCard(
                      title: context.t('souq.specs'),
                      child: Column(children: [for (final e in specs.entries) InfoRow(context.l10n.status(e.key), specValue(e.value))]),
                    ),
                  if (ad.s('description') != null) SectionCard(title: context.t('souq.description'), child: Text(ad.str('description'), style: NawyText.body)),
                  SectionCard(
                    title: context.t('souq.seller'),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(shop?.s('name') ?? broker?.s('name') ?? context.t('souq.individual'), style: NawyText.bodyMedium),
                      if ((shop?.b('verified') ?? false) || (broker?.b('verified') ?? false)) Pill(context.t('souq.verified'), background: NawyColors.bgSuccessSubtle, foreground: NawyColors.textSuccess),
                      if (shop?.s('rating') != null) Text('★ ${shop!.s('rating')} (${shop.i('rating_count') ?? 0})', style: NawyText.caption),
                      if (seller.s('member_since') != null) Text(context.t('souq.memberSince', {'date': shortDate(context, seller.s('member_since'))}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                      if (seller.b('has_phone'))
                        TextButton.icon(
                          key: const ValueKey('ad-phone'),
                          // P12: once revealed, the number opens the dialler.
                          onPressed: _phone != null
                              ? () => PlatformBridge.callPhone(_phone)
                              : () async {
                                  final res = await guarded(context, () => AppScope.read(context).api.post('classifieds/ads/${enc(widget.number)}/phone'));
                                  if (res != null && mounted) setState(() => _phone = asJson(res.data).s('phone'));
                                },
                          icon: Icon(_phone != null ? Icons.call_rounded : Icons.phone_outlined),
                          label: Text(_phone ?? context.t('souq.showPhone'), textDirection: _phone != null ? TextDirection.ltr : null),
                        ),
                    ]),
                  ),
                  if (ad.l('similar').isNotEmpty) ...[SectionHeader(title: context.t('souq.similar')), AdRail(ads: ad.l('similar'))],
                ]),
              ),
              if (ad.s('status') == 'active')
                SafeArea(
                  top: false,
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Row(children: [
                      Expanded(child: OutlinedButton.icon(onPressed: () => _chat(ad), icon: const Icon(Icons.chat_bubble_outline_rounded), label: Text(context.t('souq.chat')))),
                      for (final type in requests) ...[
                        const SizedBox(width: 8),
                        Expanded(
                          child: FilledButton(
                            key: ValueKey('request-$type'),
                            onPressed: () => Navigator.of(context).pushNamed(Routes.souqRequest, arguments: {'ad': ad, 'type': type}),
                            child: Text(context.t('souq.request.$type')),
                          ),
                        ),
                      ],
                    ]),
                  ),
                ),
            ]);
          },
        ),
      );
}

/// Figma "59 Souq viewing/reserve request" (D8: no payment, guests too).
class SouqRequestScreen extends StatefulWidget {
  const SouqRequestScreen({super.key, required this.ad, this.type = 'viewing'});

  final Json ad;
  final String type;

  @override
  State<SouqRequestScreen> createState() => _SouqRequestScreenState();
}

class _SouqRequestScreenState extends State<SouqRequestScreen> {
  late String _type = widget.type;
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _message = TextEditingController();
  DateTime _when = DateTime.now().add(const Duration(days: 1)).copyWith(hour: 17, minute: 0, second: 0, millisecond: 0, microsecond: 0);
  Map<String, String> _errors = {};

  @override
  void initState() {
    super.initState();
    final app = AppScope.read(context);
    _name.text = app.user?.s('name') ?? '';
    _email.text = app.user?.s('email') ?? '';
    _phone.text = app.user?.s('phone') ?? app.phoneCode;
  }

  @override
  void dispose() {
    for (final c in [_name, _email, _phone, _message]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickWhen() async {
    final date = await showDatePicker(context: context, initialDate: _when, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 90)));
    if (date == null || !mounted) return;
    final time = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(_when));
    if (!mounted) return;
    setState(() => _when = DateTime(date.year, date.month, date.day, time?.hour ?? _when.hour, time?.minute ?? _when.minute));
  }

  Future<void> _submit() async {
    try {
      final res = await AppScope.read(context).api.post('classifieds/ads/${enc(widget.ad.str('number'))}/viewings', body: {
        'type': _type,
        'name': _name.text.trim(),
        'email': _email.text.trim(),
        'phone': _phone.text.replaceAll(' ', '').trim(),
        'preferred_at': _when.toIso8601String(),
        'message': _message.text.trim().isEmpty ? null : _message.text.trim(),
      });
      if (!mounted) return;
      Navigator.of(context).pushReplacementNamed(Routes.souqSent, arguments: {'request': asJson(res.data), 'ad': widget.ad});
    } on ApiException catch (e) {
      setState(() => _errors = {...e.fieldErrors, if (e.fieldErrors.isEmpty) 'form': e.message});
    }
  }

  @override
  Widget build(BuildContext context) {
    final types = widget.ad.strings('request_types');
    return Scaffold(
      appBar: AppBar(title: Text(context.t('souq.request.$_type'))),
      body: ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
        SectionCard(
          child: Row(children: [
            SizedBox(width: 64, height: 64, child: NetImage(widget.ad.s('image'), radius: NawyRadius.md)),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(widget.ad.str('title'), style: NawyText.bodyMedium, maxLines: 2),
                Text(formatMoney(widget.ad['price'], locale: context.l10n.locale), style: NawyText.label),
              ]),
            ),
          ]),
        ),
        if (types.length > 1)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: Wrap(spacing: 8, children: [for (final t in types) ChoiceChip(label: Text(context.t('souq.request.$t')), selected: _type == t, onSelected: (_) => setState(() => _type = t))]),
          ),
        SectionCard(
          title: context.t('travel.yourDetails'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (!AppScope.of(context).signedIn) Text(context.t('travel.guestOk'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
            const SizedBox(height: 8),
            TextField(controller: _name, decoration: InputDecoration(labelText: '${context.t('profile.name')} *', errorText: _errors['name'])),
            const SizedBox(height: 10),
            TextField(controller: _email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: '${context.t('auth.email')} *', errorText: _errors['email'])),
            const SizedBox(height: 10),
            TextField(controller: _phone, keyboardType: TextInputType.phone, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: '${context.t('address.phone')} *', errorText: _errors['phone'])),
          ]),
        ),
        SectionCard(
          title: context.t('souq.preferredTime'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            OutlinedButton.icon(onPressed: _pickWhen, icon: const Icon(Icons.event_rounded), label: Text(shortDate(context, _when.toIso8601String(), time: true))),
            if (_errors['preferred_at'] != null) Text(_errors['preferred_at']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
            const SizedBox(height: 10),
            TextField(controller: _message, maxLines: 3, decoration: InputDecoration(labelText: context.t('souq.message'), hintText: context.t('common.optional'))),
          ]),
        ),
        Container(
          margin: const EdgeInsets.symmetric(horizontal: 16),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(color: NawyColors.bgInfoSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
          child: Text(context.t('souq.noPayment'), style: NawyText.bodySm),
        ),
        if (_errors['form'] != null) Padding(padding: const EdgeInsets.all(16), child: Text(_errors['form']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
        Padding(padding: const EdgeInsets.all(16), child: BusyButton(key: const ValueKey('souq-send'), label: context.t('travel.send'), onPressed: _submit)),
      ]),
    );
  }
}

/// Figma "60 Souq request sent". Owner decision D12: the request is a
/// record only, so the customer sees "Request sent" and no status.
class SouqSentScreen extends StatelessWidget {
  const SouqSentScreen({super.key, required this.request, required this.ad});

  final Json request;
  final Json ad;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(automaticallyImplyLeading: false),
        body: ListView(padding: const EdgeInsets.all(24), children: [
          const Icon(Icons.event_available_rounded, size: 80, color: NawyColors.bgSuccess),
          const SizedBox(height: 16),
          Text(context.t('souq.sentTitle'), textAlign: TextAlign.center, style: NawyText.h2),
          const SizedBox(height: 8),
          Text(context.t('souq.sentHint', {'title': ad.str('title')}), textAlign: TextAlign.center, style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
          if (request['id'] != null) ...[
            const SizedBox(height: 8),
            Text(context.t('souq.reference', {'id': '${request['id']}'}), textAlign: TextAlign.center, style: NawyText.caption),
          ],
          const SizedBox(height: 24),
          if (AppScope.of(context).signedIn)
            FilledButton(onPressed: () => Navigator.of(context).pushReplacementNamed(Routes.viewingRequests), child: Text(context.t('souq.myRequests'))),
          TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.t('common.done'))),
        ]),
      );
}

/// Viewing / reserve requests as plain records (owner decision D12: no
/// status, no actions). "Sent" lists the customer's own requests
/// (`GET me/viewing-requests`); "On my ads" lists the requests received on
/// the user's own ads with the customer's contact details
/// (`GET classifieds/mine/requests`), to contact them outside Nawy.
class ViewingRequestsScreen extends StatefulWidget {
  const ViewingRequestsScreen({super.key});

  @override
  State<ViewingRequestsScreen> createState() => _ViewingRequestsScreenState();
}

class _ViewingRequestsScreenState extends State<ViewingRequestsScreen> {
  bool _received = false;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('souq.myRequests'))),
        body: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: Wrap(spacing: 8, children: [
              ChoiceChip(key: const ValueKey('requests-sent'), label: Text(context.t('souq.requestsSent')), selected: !_received, onSelected: (_) => setState(() => _received = false)),
              ChoiceChip(key: const ValueKey('requests-received'), label: Text(context.t('souq.requestsReceived')), selected: _received, onSelected: (_) => setState(() => _received = true)),
            ]),
          ),
          if (_received)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: Text(context.t('souq.requestsReceivedHint'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
            ),
          Expanded(
            child: PagedList(
              key: ValueKey(_received),
              load: (page) => AppScope.read(context).api.getList(_received ? 'classifieds/mine/requests' : 'me/viewing-requests', query: {'page': page}),
              empty: EmptyView(icon: Icons.event_available_outlined, title: context.t(_received ? 'souq.noRequestsReceived' : 'souq.noRequests')),
              itemBuilder: (context, r) => _RequestRecord(r),
            ),
          ),
        ]),
      );
}

class _RequestRecord extends StatelessWidget {
  const _RequestRecord(this.r);

  final Json r;

  @override
  Widget build(BuildContext context) {
    final contact = r.m('contact');
    return InkWell(
      onTap: r.m('ad') == null ? null : () => Navigator.of(context).pushNamed(Routes.souqAd, arguments: {'number': r.m('ad')!.str('number')}),
      child: SectionCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(r.m('ad')?.str('title') ?? '', style: NawyText.bodyMedium),
          Text(context.t('souq.request.${r.str('type', 'viewing')}'), style: NawyText.caption),
          if (r.s('created_at') != null) Text(context.t('souq.requestSentOn', {'date': shortDate(context, r.s('created_at'))}), style: NawyText.caption),
          Text('${context.t('souq.preferredTime')}: ${shortDate(context, r.s('preferred_at'), time: true)}', style: NawyText.caption),
          if (r.s('note') != null) Text(r.str('note'), style: NawyText.bodySm),
          if (contact != null) ...[
            const SizedBox(height: 6),
            Text(contact.str('name'), style: NawyText.bodyMedium),
            SelectableText(contact.str('email'), style: NawyText.bodySm),
            if (contact.s('phone') != null) PhoneLink(contact.str('phone'), t: context.t, style: NawyText.bodySm),
          ],
        ]),
      ),
    );
  }
}

/// My ads (`GET classifieds/mine`) with pause/resume/renew/archive/sold;
/// drafts get their photos managed and are submitted from here, and an ad
/// waiting for the seller agreement can sign it.
class MyAdsScreen extends StatefulWidget {
  const MyAdsScreen({super.key});

  @override
  State<MyAdsScreen> createState() => _MyAdsScreenState();
}

class _MyAdsScreenState extends State<MyAdsScreen> {
  int _gen = 0;

  Future<void> _action(Json ad, String action) async {
    final api = AppScope.read(context).api;
    final n = enc(ad.str('number'));
    if (action == 'sold') {
      final price = TextEditingController();
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(ctx.t('souq.markSold')),
          content: TextField(controller: price, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: ctx.t('souq.finalPrice'))),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(ctx.t('common.cancel'))),
            TextButton(onPressed: () => Navigator.of(ctx).pop(true), child: Text(ctx.t('common.save'))),
          ],
        ),
      );
      final value = price.text.trim();
      price.dispose();
      if (ok != true || !mounted) return;
      await guarded(context, () => api.post('classifieds/ads/$n/sold', body: {'final_price': value}));
    } else if (action == 'duplicate') {
      await guarded(context, () => api.post('classifieds/ads/$n/duplicate'), success: context.t('souq.duplicated'));
    } else if (action == 'photos') {
      await Navigator.of(context).pushNamed(Routes.adPhotos, arguments: {'number': ad.str('number'), 'category': ad.s('category')});
    } else if (action == 'submit') {
      await guarded(context, () => submitAd(context, api, ad.str('number')), success: context.t('souq.submitted'));
    } else if (action == 'contract') {
      final res = await guarded(context, () => api.getObject('classifieds/ads/$n/contract'));
      final contract = res?.m('contract');
      if (contract == null || !mounted) return;
      await guarded(context, () => signAdContract(context, api, ad.str('number'), contract));
    } else {
      await guarded(context, () => api.post('classifieds/ads/$n/$action'));
    }
    if (mounted) setState(() => _gen++);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('souq.myAds'))),
        floatingActionButton: FloatingActionButton.extended(onPressed: () => Navigator.of(context).pushNamed(Routes.postAd), icon: const Icon(Icons.add_rounded), label: Text(context.t('souq.postAd'))),
        body: PagedList(
          key: ValueKey(_gen),
          load: (page) => AppScope.read(context).api.getList('classifieds/mine', query: {'page': page}),
          empty: EmptyView(icon: Icons.campaign_outlined, title: context.t('souq.noAds')),
          itemBuilder: (context, ad) {
            final status = ad.str('status');
            final actions = <String>[
              if (status == 'draft') ...['photos', 'submit'],
              if (status == 'pending_contract') ...['contract', 'photos'],
              if (status == 'active') ...['pause', 'sold'],
              if (status == 'paused') 'resume',
              if (status == 'expired') 'renew',
              if (const {'active', 'paused', 'expired'}.contains(status)) 'archive',
              if (const {'rejected', 'expired', 'archived', 'sold'}.contains(status)) 'duplicate',
            ];
            return SectionCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                AdCard(ad: ad, horizontal: true),
                const SizedBox(height: 8),
                Row(children: [
                  Pill.status(context, status),
                  const Spacer(),
                  if (actions.isNotEmpty)
                    PopupMenuButton<String>(
                      key: ValueKey('my-ad-actions-${ad.str('number')}'),
                      tooltip: context.t('common.more'),
                      onSelected: (a) => _action(ad, a),
                      itemBuilder: (ctx) => [for (final a in actions) PopupMenuItem(value: a, child: Text(ctx.t('souq.action.$a')))],
                    ),
                ]),
              ]),
            );
          },
        ),
      );
}

/// Photos of a draft ad (the API takes media changes while the ad is a
/// draft or waits for the seller agreement): the uploaded ones with remove
/// (`DELETE classifieds/ads/{n}/media/{id}`) and new ones within the
/// category's `max_photos` / `photo_max_kb` (`POST …/photos`, one each).
class AdPhotosScreen extends StatefulWidget {
  const AdPhotosScreen({super.key, required this.number, this.category});

  final String number;

  /// Category slug (from the ad card) for the photo limits.
  final String? category;

  @override
  State<AdPhotosScreen> createState() => _AdPhotosScreenState();
}

class _AdPhotosScreenState extends State<AdPhotosScreen> {
  int _gen = 0;
  List<PickedMedia> _photos = const [];
  String? _progress;
  String? _error;

  Future<(Json, AdPhotoLimits)> _load() async {
    final api = AppScope.read(context).api;
    final ad = await api.getObject('classifieds/ads/${enc(widget.number)}');
    final slug = widget.category ?? ad.s('category');
    Json? attributes;
    if (slug != null) {
      try {
        attributes = await api.getObject('classifieds/categories/${enc(slug)}/attributes');
      } on ApiException {
        attributes = null; // The defaults still apply; the API enforces the real limits.
      }
    }
    return (ad, AdPhotoLimits.of(attributes));
  }

  Future<void> _upload(AdPhotoLimits limits, int uploaded) async {
    final api = AppScope.read(context).api;
    final rules = limits.rules(uploaded: uploaded);
    final check = rules.check(const [], _photos);
    if (check.rejected.isNotEmpty) {
      setState(() => _error = rejectionMessages(check.rejected, rules, context.t).join('\n'));
      return;
    }
    final failed = <PickedMedia>[];
    final reasons = <String>{};
    for (final (i, photo) in _photos.indexed) {
      setState(() => _progress = context.t('souq.photosUploading', {'n': i + 1, 'total': _photos.length}));
      try {
        await api.multipart('classifieds/ads/${enc(widget.number)}/photos', files: [photo.part('photo')]);
      } on ApiException catch (e) {
        failed.add(photo);
        reasons.add(e.message);
      }
      if (!mounted) return;
    }
    setState(() {
      _progress = null;
      _photos = failed;
      _error = failed.isEmpty ? null : [context.t('souq.photosNotAdded', {'n': failed.length}), ...reasons].join('\n');
      _gen++;
    });
    if (failed.isEmpty) showToast(context, context.t('souq.photosAdded'));
  }

  Future<void> _remove(Json media) async {
    final ok = await guarded(context, () => AppScope.read(context).api.delete('classifieds/ads/${enc(widget.number)}/media/${media.i('id')}'));
    if (ok != null && mounted) setState(() => _gen++);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('souq.managePhotos'))),
        body: AsyncBody<(Json, AdPhotoLimits)>(
          key: ValueKey(_gen),
          load: _load,
          builder: (context, data, _) {
            final (ad, limits) = data;
            final existing = ad.l('media').where((m) => m.s('type') == 'photo').toList();
            final editable = const {'draft', 'pending_contract'}.contains(ad.s('status'));
            return ListView(padding: const EdgeInsets.all(16), children: [
              Text(ad.str('title'), style: NawyText.h4),
              const SizedBox(height: 4),
              Text(context.t('souq.photosOf', {'n': existing.length, 'max': limits.maxPhotos}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
              const SizedBox(height: 12),
              if (existing.isNotEmpty)
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final m in existing)
                    Stack(clipBehavior: Clip.none, children: [
                      SizedBox(width: 88, height: 88, child: NetImage(m.s('url'), radius: NawyRadius.sm)),
                      if (editable)
                        PositionedDirectional(
                          top: -8,
                          end: -8,
                          child: IconButton(
                            key: ValueKey('ad-media-remove-${m.i('id')}'),
                            tooltip: context.t('media.remove'),
                            onPressed: () => _remove(m),
                            icon: const CircleAvatar(radius: 11, backgroundColor: NawyColors.bgInverse, child: Icon(Icons.close, size: 13, color: NawyColors.textInverse)),
                          ),
                        ),
                    ]),
                ]),
              const SizedBox(height: 16),
              if (!editable)
                Text(context.t('souq.photosLocked'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary))
              else ...[
                Text(context.t('souq.addPhotos'), style: NawyText.label),
                const SizedBox(height: 8),
                AttachmentPicker(
                  key: const ValueKey('ad-photos-picker'),
                  files: _photos,
                  rules: limits.rules(uploaded: existing.length),
                  t: context.t,
                  enabled: _progress == null,
                  onChanged: (files) => setState(() {
                    _photos = files;
                    _error = null;
                  }),
                ),
                if (_progress != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_progress!, style: NawyText.bodySm)),
                if (_error != null)
                  Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, key: const ValueKey('ad-photos-error'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
                const SizedBox(height: 16),
                BusyButton(key: const ValueKey('ad-photos-upload'), label: context.t('souq.uploadPhotos'), onPressed: _photos.isEmpty ? null : () => _upload(limits, existing.length)),
              ],
            ]);
          },
        ),
      );
}

/// Figma app "17 Post ad": category → details (attributes) → submit, with
/// the seller contract click-wrap when the category needs one. Photos come
/// from the camera / gallery (image_picker, D16), within the category's
/// `max_photos` / `photo_max_kb`, and are uploaded one by one after the ad
/// is created, before it is submitted for review. When a photo fails the ad
/// stays a draft and the failed photos stay here to retry or remove.
class PostAdScreen extends StatefulWidget {
  const PostAdScreen({super.key});

  @override
  State<PostAdScreen> createState() => _PostAdScreenState();
}

class _PostAdScreenState extends State<PostAdScreen> {
  int _step = 0;
  Json? _category;
  Json? _attributes;
  final Map<String, TextEditingController> _specs = {};
  final Map<String, String?> _choices = {};
  final _title = TextEditingController();
  final _description = TextEditingController();
  final _price = TextEditingController();
  final _city = TextEditingController();
  final _area = TextEditingController();
  bool _negotiable = false;
  String _kind = 'offer';
  LatLng? _location;
  List<PickedMedia> _photos = const [];
  String? _progress;
  Map<String, String> _errors = {};
  Json? _result;

  /// The draft once `POST classifieds/ads` created it (a retry updates it).
  String? _number;

  /// Photos already uploaded to [_number].
  int _uploaded = 0;

  AdPhotoLimits get _limits => AdPhotoLimits.of(_attributes, _category);

  UploadRules get _photoRules => _limits.rules(uploaded: _uploaded);

  /// One `photo` per call to `classifieds/ads/{number}/photos` (D16 / P7).
  /// The uploaded photos leave [_photos]; the failed ones stay. Returns the
  /// API's reasons for the failures.
  Future<Set<String>> _uploadPhotos(ApiClient api, String number) async {
    final failed = <PickedMedia>[];
    final reasons = <String>{};
    for (final (i, photo) in _photos.indexed) {
      if (mounted) setState(() => _progress = context.t('souq.photosUploading', {'n': i + 1, 'total': _photos.length}));
      try {
        await api.multipart('classifieds/ads/${enc(number)}/photos', files: [photo.part('photo')]);
        _uploaded++;
      } on ApiException catch (e) {
        failed.add(photo);
        reasons.add(e.message);
      }
    }
    if (mounted) {
      setState(() {
        _progress = null;
        _photos = failed;
      });
    }
    return reasons;
  }

  @override
  void dispose() {
    for (final c in [_title, _description, _price, _city, _area, ..._specs.values]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickCategory(Json c) async {
    final attrs = await guarded(context, () => AppScope.read(context).api.getObject('classifieds/categories/${enc(c.str('slug'))}/attributes'));
    if (attrs == null || !mounted) return;
    // Photos picked under another category must fit this one's limits.
    final rules = AdPhotoLimits.of(attrs, c).rules();
    final check = rules.check(const [], _photos);
    setState(() {
      _category = c;
      _attributes = attrs;
      _photos = check.accepted;
      _step = 1;
    });
    if (check.rejected.isNotEmpty) showToast(context, rejectionMessages(check.rejected, rules, context.t).join('\n'), error: true);
  }

  Future<void> _submit() async {
    final api = AppScope.read(context).api;
    final rules = _photoRules;
    final check = rules.check(const [], _photos);
    if (check.rejected.isNotEmpty) {
      setState(() => _errors = {'photos': rejectionMessages(check.rejected, rules, context.t).join('\n')});
      return;
    }
    final specs = <String, dynamic>{
      for (final e in _specs.entries)
        if (e.value.text.trim().isNotEmpty) e.key: e.value.text.trim(),
      for (final e in _choices.entries)
        if (e.value != null) e.key: e.value,
    };
    final body = {
      'category_id': _category!.i('id'),
      'kind': _kind,
      'title': _title.text.trim(),
      'description': _description.text.trim(),
      'price': _price.text.trim().isEmpty ? null : _price.text.trim(),
      'is_negotiable': _negotiable,
      'city': _city.text.trim(),
      'area': _area.text.trim(),
      'latitude': _location?.latitude,
      'longitude': _location?.longitude,
      'specs': specs,
    };
    try {
      if (_number == null) {
        final created = asJson((await api.post('classifieds/ads', body: body)).data);
        _number = created.str('number');
      } else {
        await api.put('classifieds/ads/${enc(_number!)}', body: body);
      }
      final number = _number!;
      if (_photos.isNotEmpty) {
        final reasons = await _uploadPhotos(api, number);
        if (_photos.isNotEmpty) {
          if (mounted) setState(() => _errors = {'photos': [context.t('souq.photosFailed', {'n': _photos.length}), ...reasons].join('\n')});
          return;
        }
      }
      if (!mounted) return;
      final submitted = await submitAd(context, api, number);
      if (mounted) setState(() => _result = {...submitted, 'number': number});
    } on ApiException catch (e) {
      setState(() => _errors = {...e.fieldErrors, if (e.fieldErrors.isEmpty) 'form': e.message});
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('souq.postAd'))),
        body: _result != null
            ? EmptyView(
                icon: Icons.check_circle_rounded,
                title: context.t('souq.submitted'),
                subtitle: context.t('souq.submittedHint', {'number': _result!.str('number'), 'status': context.l10n.status(_result!.s('status'))}),
                actionLabel: context.t('souq.myAds'),
                onAction: () => Navigator.of(context).pushReplacementNamed(Routes.myAds),
              )
            : _step == 0
                ? _categoryStep(context)
                : _detailsStep(context),
      );

  Widget _categoryStep(BuildContext context) => AsyncBody<List<Json>>(
        load: () => AppScope.read(context).api.getList('classifieds/categories').then((r) => r.data),
        isEmpty: (l) => l.isEmpty,
        builder: (context, tree, _) => ListView(children: [
          Padding(padding: const EdgeInsets.all(16), child: Text(context.t('souq.pickCategory'), style: NawyText.h4)),
          for (final (c, depth) in flattenCategories(tree))
            ListTile(
              contentPadding: EdgeInsetsDirectional.only(start: 16.0 + depth * 16, end: 16),
              title: Text(c.str('name'), style: depth == 0 ? NawyText.bodyMedium : NawyText.body),
              trailing: c.l('children').isEmpty ? const Icon(Icons.chevron_right_rounded) : null,
              onTap: c.l('children').isEmpty ? () => _pickCategory(c) : null,
            ),
        ]),
      );

  Widget _detailsStep(BuildContext context) {
    final attrs = _attributes?.l('attributes') ?? const <Json>[];
    final limits = _limits;
    return ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        Expanded(child: Text(_category!.str('name'), style: NawyText.h4)),
        // The draft keeps its category once created.
        if (_number == null) TextButton(onPressed: () => setState(() => _step = 0), child: Text(context.t('common.change'))),
      ]),
      Wrap(spacing: 8, children: [
        for (final k in const ['offer', 'wanted']) ChoiceChip(label: Text(context.t('souq.kind.$k')), selected: _kind == k, onSelected: (_) => setState(() => _kind = k)),
      ]),
      const SizedBox(height: 12),
      TextField(controller: _title, decoration: InputDecoration(labelText: '${context.t('souq.adTitle')} *', errorText: _errors['title'])),
      const SizedBox(height: 10),
      TextField(controller: _description, maxLines: 4, decoration: InputDecoration(labelText: context.t('souq.description'), errorText: _errors['description'])),
      const SizedBox(height: 10),
      TextField(
        controller: _price,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        decoration: InputDecoration(labelText: '${context.t('souq.price')} (${AppScope.of(context).currency})', errorText: _errors['price']),
      ),
      SwitchListTile(contentPadding: EdgeInsets.zero, value: _negotiable, onChanged: (v) => setState(() => _negotiable = v), title: Text(context.t('souq.negotiable'))),
      Row(children: [
        Expanded(child: TextField(controller: _city, decoration: InputDecoration(labelText: '${context.t('address.city')} *', errorText: _errors['city']))),
        const SizedBox(width: 8),
        Expanded(child: TextField(controller: _area, decoration: InputDecoration(labelText: context.t('address.area'), errorText: _errors['area']))),
      ]),
      // Shown on the map view (FR-CLS-5) when set.
      ListTile(
        key: const ValueKey('post-ad-location'),
        contentPadding: EdgeInsets.zero,
        leading: const Icon(Icons.place_outlined),
        title: Text(context.t('souq.location')),
        subtitle: Text(
          _location == null ? context.t('souq.locationNone') : '${_location!.latitude.toStringAsFixed(5)}, ${_location!.longitude.toStringAsFixed(5)}',
          textDirection: _location == null ? null : TextDirection.ltr,
          style: NawyText.caption.copyWith(color: _errors['latitude'] != null ? NawyColors.textDanger : NawyColors.textSecondary),
        ),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          if (_location != null) IconButton(key: const ValueKey('post-ad-location-clear'), tooltip: context.t('media.remove'), onPressed: () => setState(() => _location = null), icon: const Icon(Icons.close_rounded)),
          TextButton(
            key: const ValueKey('post-ad-location-set'),
            onPressed: () async {
              final picked = await Navigator.of(context).push<LatLng>(MaterialPageRoute(builder: (_) => LocationPickerScreen(initial: _location)));
              if (picked != null && mounted) setState(() => _location = picked);
            },
            child: Text(context.t(_location == null ? 'souq.locationSet' : 'common.change')),
          ),
        ]),
      ),
      for (final a in attrs) ...[
        const SizedBox(height: 10),
        if (a.l('options').isNotEmpty || a['options'] is List && (a['options'] as List).isNotEmpty)
          DropdownButtonFormField<String>(
            initialValue: _choices[a.str('key')],
            isExpanded: true,
            decoration: InputDecoration(labelText: '${a.s('label') ?? a.str('key')}${a.b('required') ? ' *' : ''}', errorText: _errors['specs.${a.str('key')}']),
            items: [
              for (final o in (a['options'] as List))
                DropdownMenuItem(
                  value: o is Map ? '${o['value']}' : '$o',
                  child: Text(o is Map ? '${o['label_${context.l10n.locale}'] ?? o['label'] ?? o['value']}' : '$o'),
                ),
            ],
            onChanged: (v) => setState(() => _choices[a.str('key')] = v),
          )
        else
          TextField(
            controller: _specs.putIfAbsent(a.str('key'), TextEditingController.new),
            keyboardType: const {'number', 'year'}.contains(a.s('type')) ? TextInputType.number : TextInputType.text,
            decoration: InputDecoration(
              labelText: '${a.s('label') ?? a.str('key')}${a.s('unit') != null ? ' (${a.s('unit')})' : ''}${a.b('required') ? ' *' : ''}',
              errorText: _errors['specs.${a.str('key')}'],
            ),
          ),
      ],
      const SizedBox(height: 12),
      Text(context.t('souq.photos'), style: NawyText.label),
      Text(context.t('souq.photosHint'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
      if (_uploaded > 0) Text(context.t('souq.photosOf', {'n': _uploaded, 'max': limits.maxPhotos}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
      const SizedBox(height: 8),
      AttachmentPicker(
        key: const ValueKey('post-ad-photos'),
        files: _photos,
        rules: _photoRules,
        t: context.t,
        enabled: _progress == null,
        onChanged: (files) => setState(() {
          _photos = files;
          _errors.remove('photos');
        }),
      ),
      if (_progress != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_progress!, key: const ValueKey('post-ad-progress'), style: NawyText.bodySm)),
      if (_errors['photos'] != null)
        Padding(padding: const EdgeInsets.only(top: 8), child: Text(_errors['photos']!, key: const ValueKey('post-ad-photos-error'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
      if (_errors['form'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_errors['form']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
      const SizedBox(height: 16),
      BusyButton(key: const ValueKey('post-ad-submit'), label: context.t(_number != null && _photos.isNotEmpty ? 'souq.retryPhotos' : 'souq.submit'), onPressed: _submit),
    ]);
  }
}
