import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../routing/navigation.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/paged_list.dart';
import '../../ui/widgets/states.dart';

/// Figma "35 Credits": Nawy Credits balances (incl. promotional and expiring)
/// and transactions (`GET wallet`, `GET wallet/transactions`), voucher redeem.
class WalletScreen extends StatefulWidget {
  const WalletScreen({super.key});

  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  int _gen = 0;

  Future<void> _redeem() async {
    final code = TextEditingController();
    final ok = await showNawySheet<bool>(
      context,
      title: context.t('wallet.redeemVoucher'),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          TextField(controller: code, textCapitalization: TextCapitalization.characters, decoration: InputDecoration(labelText: ctx.t('wallet.voucherCode'))),
          const SizedBox(height: 12),
          BusyButton(
            label: ctx.t('wallet.redeem'),
            onPressed: () async {
              final res = await guarded(ctx, () => AppScope.read(ctx).api.post('vouchers/redeem', body: {'code': code.text.trim()}), success: ctx.t('wallet.redeemed'));
              if (res != null && ctx.mounted) Navigator.of(ctx).pop(true);
            },
          ),
        ]),
      ),
    );
    code.dispose();
    if (ok == true && mounted) setState(() => _gen++);
  }

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    final locale = context.l10n.locale;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('account.credits')), actions: [TextButton(onPressed: _redeem, child: Text(context.t('wallet.redeemVoucher')))]),
      body: PagedList(
        key: ValueKey(_gen),
        load: (page) => api.getList('wallet/transactions', query: {'page': page}),
        header: OnceFuture<Json>(
          load: () => api.getObject('wallet'),
          builder: (context, snap) {
            final w = snap.data;
            if (w == null) return snap.hasError ? ErrorView(error: snap.error!) : const LoadingView();
            return Column(children: [
              Container(
                margin: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(color: NawyColors.bgInverse, borderRadius: BorderRadius.circular(NawyRadius.card)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t('wallet.spendable'), style: NawyText.bodySm.copyWith(color: NawyColors.textMuted)),
                  Text(formatMoney(w['spendable'] ?? w['available'], locale: locale), style: NawyText.h1.copyWith(color: NawyColors.textInverse)),
                  const SizedBox(height: 8),
                  Text('${context.t('wallet.available')}: ${formatMoney(w['available'], locale: locale)} · ${context.t('wallet.promotional')}: ${formatMoney(w['promotional'], locale: locale)}',
                      style: NawyText.caption.copyWith(color: NawyColors.textInverse)),
                  if (!(Money.fromJson(w['frozen'])?.isZero ?? true))
                    Text('${context.t('wallet.frozen')}: ${formatMoney(w['frozen'], locale: locale)}', style: NawyText.caption.copyWith(color: NawyColors.textMuted)),
                ]),
              ),
              for (final e in w.l('promotional_expiring'))
                Container(
                  margin: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(color: NawyColors.bgWarningSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
                  child: Text(context.t('wallet.expiring', {'amount': formatMoney(e['amount'], locale: locale), 'date': shortDate(context, e.s('expires_at'))}), style: NawyText.label),
                ),
              SectionHeader(title: context.t('wallet.transactions')),
            ]);
          },
        ),
        empty: EmptyView(icon: Icons.receipt_long_outlined, title: context.t('wallet.noTransactions')),
        itemBuilder: (context, t) => ListTile(
          leading: Icon(t.s('direction') == 'in' ? Icons.south_west_rounded : Icons.north_east_rounded, color: t.s('direction') == 'in' ? NawyColors.bgSuccess : NawyColors.textDanger),
          title: Text(t.s('description') ?? context.l10n.status(t.s('type'))),
          subtitle: Text('${shortDate(context, t.s('at'), time: true)}${t.s('bucket') == 'promotional' ? ' · ${context.t('wallet.promotional')}' : ''}'),
          trailing: Text('${t.s('direction') == 'in' ? '+' : '-'}${formatMoney(t['amount'], locale: locale)}',
              style: NawyText.bodyMedium.copyWith(color: t.s('direction') == 'in' ? NawyColors.textSuccess : NawyColors.textPrimary)),
        ),
      ),
    );
  }
}

/// Figma "38 Notifications": unread badge, mark read / read all, deep links.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  int _gen = 0;
  bool _unreadOnly = false;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('notifications.title')),
        actions: [
          if (app.unread > 0)
            TextButton(
              key: const ValueKey('read-all'),
              onPressed: () async {
                final res = await guarded(context, () => app.api.post('me/notifications/read-all'));
                if (res != null) {
                  app.setUnread(asJson(res.data).i('unread') ?? 0);
                  if (mounted) setState(() => _gen++);
                }
              },
              child: Text(context.t('notifications.readAll')),
            ),
        ],
      ),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: Row(children: [
            ChoiceChip(label: Text(context.t('notifications.all')), selected: !_unreadOnly, onSelected: (_) => setState(() => _unreadOnly = false)),
            const SizedBox(width: 8),
            ChoiceChip(label: Text(context.t('notifications.unread', {'count': app.unread})), selected: _unreadOnly, onSelected: (_) => setState(() => _unreadOnly = true)),
          ]),
        ),
        Expanded(
          child: PagedList(
            key: ValueKey('$_gen-$_unreadOnly'),
            load: (page) async {
              final res = await app.api.getList('me/notifications', query: {'page': page, 'unread': _unreadOnly ? 1 : null});
              final unread = res.meta.i('unread');
              if (unread != null) app.setUnread(unread);
              return res;
            },
            empty: EmptyView(icon: Icons.notifications_none_rounded, title: context.t('notifications.empty')),
            itemBuilder: (context, n) => ListTile(
              tileColor: n.b('read') ? null : NawyColors.bgBrandSubtle,
              leading: Icon(_icon(n.str('type')), color: NawyColors.textPrimary),
              title: Text(n.s('title') ?? context.l10n.status(n.s('type')), style: n.b('read') ? NawyText.body : NawyText.bodyMedium),
              subtitle: Text([n.s('body'), shortDate(context, n.s('created_at'), time: true)].whereType<String>().join('\n')),
              onTap: () async {
                if (!n.b('read')) {
                  try {
                    final res = await app.api.post('me/notifications/${n.i('id')}/read');
                    app.setUnread(asJson(res.data).i('unread') ?? app.unread);
                    n['read'] = true;
                  } catch (_) {}
                }
                if (context.mounted) await openLink(context, n.s('link'));
              },
            ),
          ),
        ),
      ]),
    );
  }

  static IconData _icon(String type) {
    if (type.startsWith('order.')) return Icons.inventory_2_outlined;
    if (type.startsWith('travel_booking.')) return Icons.flight_takeoff_rounded;
    if (type.startsWith('return.')) return Icons.replay_rounded;
    if (type.startsWith('viewing.') || type.startsWith('reserve.')) return Icons.event_available_outlined;
    if (type.startsWith('classified_ad.')) return Icons.campaign_outlined;
    if (type.startsWith('review.') || type.startsWith('question.')) return Icons.rate_review_outlined;
    return Icons.notifications_none_rounded;
  }
}

/// Profile: name, email language, marketing channels, email change by code
/// (`GET/PATCH me`, `POST me/email`, `POST me/email/verify`). Avatar upload
/// needs an image picker (owner-review WP10); removing it works.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _name = TextEditingController();
  String _locale = 'en';
  Map<String, bool> _marketing = {};
  bool _loaded = false;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<Json> _load() async {
    final me = await AppScope.read(context).api.getObject('me');
    if (!_loaded) {
      _name.text = me.str('name');
      _locale = me.s('locale') ?? 'en';
      _marketing = {for (final k in const ['email', 'sms', 'push', 'whatsapp']) k: me.m('marketing')?.b(k) ?? false};
      _loaded = true;
    }
    return me;
  }

  Future<void> _save() async {
    final app = AppScope.read(context);
    final res = await guarded(context, () => app.api.patch('me', body: {'name': _name.text.trim(), 'locale': _locale, 'marketing': _marketing}), success: context.t('profile.saved'));
    if (res != null) app.updateUser(asJson(res.data));
  }

  Future<void> _changeEmail() async {
    final app = AppScope.read(context);
    final email = TextEditingController();
    final code = TextEditingController();
    var sent = false;
    await showNawySheet<void>(
      context,
      title: context.t('profile.changeEmail'),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: const EdgeInsets.all(16),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            TextField(controller: email, enabled: !sent, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: ctx.t('auth.email'))),
            if (sent) ...[
              const SizedBox(height: 12),
              TextField(controller: code, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: ctx.t('auth.code'))),
            ],
            const SizedBox(height: 12),
            BusyButton(
              label: ctx.t(sent ? 'auth.verify' : 'auth.sendCode'),
              onPressed: () async {
                if (!sent) {
                  final ok = await guarded(ctx, () => app.api.post('me/email', body: {'email': email.text.trim()}));
                  if (ok != null) setSheet(() => sent = true);
                } else {
                  final res = await guarded(ctx, () => app.api.post('me/email/verify', body: {'email': email.text.trim(), 'code': code.text.trim()}), success: ctx.t('profile.emailChanged'));
                  if (res != null) {
                    app.updateUser(asJson(res.data));
                    if (ctx.mounted) Navigator.of(ctx).pop();
                  }
                }
              },
            ),
          ]),
        ),
      ),
    );
    email.dispose();
    code.dispose();
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('profile.title'))),
        body: AsyncBody<Json>(
          load: _load,
          builder: (context, me, _) => ListView(padding: const EdgeInsets.all(16), children: [
            Row(children: [
              CircleAvatar(radius: 32, backgroundColor: NawyColors.bgBrand, foregroundImage: me.s('avatar_url') != null ? NetworkImage(me.str('avatar_url')) : null, child: const Icon(Icons.person_rounded)),
              const SizedBox(width: 12),
              if (me.s('avatar_url') != null)
                TextButton(
                  onPressed: () async {
                    final app = AppScope.read(context);
                    final res = await guarded(context, () => app.api.delete('me/avatar'));
                    if (res != null && mounted) {
                      app.updateUser(asJson(res.data));
                      setState(() {});
                    }
                  },
                  child: Text(context.t('profile.removeAvatar')),
                ),
            ]),
            const SizedBox(height: 16),
            TextField(controller: _name, decoration: InputDecoration(labelText: context.t('profile.name'))),
            const SizedBox(height: 12),
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(context.t('auth.email')),
              subtitle: Text(AppScope.of(context).user?.s('email') ?? me.str('email')),
              trailing: TextButton(onPressed: _changeEmail, child: Text(context.t('common.change'))),
            ),
            const SizedBox(height: 8),
            Text(context.t('profile.emailLanguage'), style: NawyText.bodyMedium),
            Row(children: [
              ChoiceChip(label: const Text('English'), selected: _locale == 'en', onSelected: (_) => setState(() => _locale = 'en')),
              const SizedBox(width: 8),
              ChoiceChip(label: const Text('العربية'), selected: _locale == 'ar', onSelected: (_) => setState(() => _locale = 'ar')),
            ]),
            const SizedBox(height: 16),
            Text(context.t('profile.marketing'), style: NawyText.bodyMedium),
            for (final k in const ['email', 'sms', 'push', 'whatsapp'])
              SwitchListTile(contentPadding: EdgeInsets.zero, value: _marketing[k] ?? false, onChanged: (v) => setState(() => _marketing[k] = v), title: Text(context.t('profile.channel.$k'))),
            const SizedBox(height: 16),
            BusyButton(label: context.t('common.save'), onPressed: _save),
          ]),
        ),
      );
}

/// Figma "41 Security": devices (revoke) and login history.
class SecurityScreen extends StatefulWidget {
  const SecurityScreen({super.key});

  @override
  State<SecurityScreen> createState() => _SecurityScreenState();
}

class _SecurityScreenState extends State<SecurityScreen> {
  int _gen = 0;

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.t('security.title')),
          bottom: TabBar(tabs: [Tab(text: context.t('security.devices')), Tab(text: context.t('security.history'))]),
        ),
        body: TabBarView(children: [
          AsyncBody<List<Json>>(
            key: ValueKey(_gen),
            load: () => api.getList('me/devices').then((r) => r.data),
            isEmpty: (l) => l.isEmpty,
            builder: (context, devices, _) => ListView(children: [
              for (final d in devices)
                ListTile(
                  leading: const Icon(Icons.smartphone_rounded),
                  title: Text(d.str('name')),
                  subtitle: Text('${context.t('security.lastUsed')}: ${shortDate(context, d.s('last_used_at') ?? d.s('created_at'), time: true)}'),
                  trailing: d.b('is_current')
                      ? Pill(context.t('security.thisDevice'))
                      : TextButton(
                          onPressed: () async {
                            await guarded(context, () => api.delete('me/devices/${d.i('id')}'), success: context.t('security.revoked'));
                            if (mounted) setState(() => _gen++);
                          },
                          child: Text(context.t('security.revoke')),
                        ),
                ),
            ]),
          ),
          AsyncBody<List<Json>>(
            load: () => api.getList('me/login-history').then((r) => r.data),
            isEmpty: (l) => l.isEmpty,
            builder: (context, events, _) => ListView(children: [
              for (final e in events)
                ListTile(
                  leading: Icon(e.s('outcome') == 'succeeded' ? Icons.check_circle_outline_rounded : Icons.error_outline_rounded,
                      color: e.s('outcome') == 'succeeded' ? NawyColors.bgSuccess : NawyColors.bgDanger),
                  title: Text('${context.l10n.status(e.s('outcome'))} · ${e.s('channel') ?? ''}'),
                  subtitle: Text([shortDate(context, e.s('created_at'), time: true), e.s('ip_address'), e.s('user_agent')].whereType<String>().join('\n'), maxLines: 3),
                ),
            ]),
          ),
        ]),
      ),
    );
  }
}

