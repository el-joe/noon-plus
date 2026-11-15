import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../features/page_builder/vertical_cards.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/paged_list.dart';
import '../../ui/widgets/states.dart';

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

/// Figma "47 Souq" / "49 Property": Open Souq classifieds (or the property
/// vertical) — categories, search, sort, ads (`GET classifieds/ads`).
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
        child: PagedList(
          key: ValueKey('$_q-$_category-$_sort-$_purpose-${widget.vertical}'),
          load: (page) => api.getList('classifieds/ads', query: {
            'q': _q,
            'category': _category,
            'sort': _sort == 'recommended' ? null : _sort,
            'vertical': widget.vertical,
            if (_purpose != null) 'attr[purpose]': _purpose,
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
                          onPressed: _phone != null
                              ? null
                              : () async {
                                  final res = await guarded(context, () => AppScope.read(context).api.post('classifieds/ads/${enc(widget.number)}/phone'));
                                  if (res != null && mounted) setState(() => _phone = asJson(res.data).s('phone'));
                                },
                          icon: const Icon(Icons.phone_outlined),
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

/// Figma "60 Souq request sent".
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
          const SizedBox(height: 16),
          SectionCard(margin: EdgeInsets.zero, child: InfoRow(context.t('travel.status'), context.l10n.status(request.s('status')))),
          const SizedBox(height: 24),
          if (AppScope.of(context).signedIn)
            FilledButton(onPressed: () => Navigator.of(context).pushReplacementNamed(Routes.viewingRequests), child: Text(context.t('souq.myRequests'))),
          TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.t('common.done'))),
        ]),
      );
}

/// My viewing / reserve requests (`GET me/viewing-requests`).
class ViewingRequestsScreen extends StatelessWidget {
  const ViewingRequestsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('souq.myRequests'))),
        body: PagedList(
          load: (page) => AppScope.read(context).api.getList('me/viewing-requests', query: {'page': page}),
          empty: EmptyView(icon: Icons.event_available_outlined, title: context.t('souq.noRequests')),
          itemBuilder: (context, r) => InkWell(
            onTap: r.m('ad') == null ? null : () => Navigator.of(context).pushNamed(Routes.souqAd, arguments: {'number': r.m('ad')!.str('number')}),
            child: SectionCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [Expanded(child: Text(r.m('ad')?.str('title') ?? '', style: NawyText.bodyMedium)), Pill.status(context, r.s('status'))]),
                Text('${context.t('souq.request.${r.str('type', 'viewing')}')} · ${shortDate(context, r.s('scheduled_at') ?? r.s('preferred_at'), time: true)}', style: NawyText.caption),
                if (r.s('note') != null) Text(r.str('note'), style: NawyText.bodySm),
              ]),
            ),
          ),
        ),
      );
}

/// My ads (`GET classifieds/mine`) with pause/resume/renew/archive/sold.
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

/// Figma app "17 Post ad": category → details (attributes) → submit, with
/// the seller contract click-wrap when the category needs one. Photo upload
/// needs an image picker package (owner-review WP10); ads can get photos on
/// the web.
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
  Map<String, String> _errors = {};
  Json? _result;

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
    setState(() {
      _category = c;
      _attributes = attrs;
      _step = 1;
    });
  }

  Future<void> _submit() async {
    final api = AppScope.read(context).api;
    final specs = <String, dynamic>{
      for (final e in _specs.entries)
        if (e.value.text.trim().isNotEmpty) e.key: e.value.text.trim(),
      for (final e in _choices.entries)
        if (e.value != null) e.key: e.value,
    };
    try {
      final created = asJson((await api.post('classifieds/ads', body: {
        'category_id': _category!.i('id'),
        'kind': _kind,
        'title': _title.text.trim(),
        'description': _description.text.trim(),
        'price': _price.text.trim().isEmpty ? null : _price.text.trim(),
        'is_negotiable': _negotiable,
        'city': _city.text.trim(),
        'area': _area.text.trim(),
        'specs': specs,
      }))
          .data);
      final number = created.str('number');
      final submitted = asJson((await api.post('classifieds/ads/${enc(number)}/submit')).data);
      final contract = submitted.m('contract');
      if (contract != null && mounted) {
        final name = AppScope.read(context).user?.s('name') ?? '';
        final ok = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: Text(contract.str('title')),
            content: Text(ctx.t('souq.contractHint')),
            actions: [
              TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(ctx.t('common.cancel'))),
              TextButton(onPressed: () => Navigator.of(ctx).pop(true), child: Text(ctx.t('contracts.accept'))),
            ],
          ),
        );
        if (ok == true) await api.post('classifieds/ads/${enc(number)}/contract', body: {'full_name': name});
      }
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
    return ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        Expanded(child: Text(_category!.str('name'), style: NawyText.h4)),
        TextButton(onPressed: () => setState(() => _step = 0), child: Text(context.t('common.change'))),
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
      Text(context.t('souq.photosOnWeb'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
      if (_errors['form'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_errors['form']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
      const SizedBox(height: 16),
      BusyButton(key: const ValueKey('post-ad-submit'), label: context.t('souq.submit'), onPressed: _submit),
    ]);
  }
}

