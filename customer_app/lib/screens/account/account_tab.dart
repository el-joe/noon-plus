import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

/// Figma "29 Account": profile header, Nawy One card, quick tiles, menu.
class AccountTab extends StatelessWidget {
  const AccountTab({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    final user = app.user;
    final name = user?.s('name') ?? '';
    final initials = name.trim().isEmpty ? '?' : name.trim().split(RegExp(r'\s+')).take(2).map((w) => w.characters.first.toUpperCase()).join();

    void go(String route, [Object? args]) => Navigator.of(context).pushNamed(route, arguments: args);

    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () => app.refreshUnread().catchError((_) {}),
          child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
              child: app.signedIn
                  ? InkWell(
                      onTap: () => go(Routes.profile),
                      child: Row(children: [
                        CircleAvatar(
                          radius: 32,
                          backgroundColor: NawyColors.bgBrand,
                          foregroundImage: user?.s('avatar_url') != null ? netImageProvider(user!.str('avatar_url')) : null, onForegroundImageError: user?.s('avatar_url') != null ? (_, _) {} : null,
                          child: Text(initials, style: NawyText.h3),
                        ),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(context.t('account.hello', {'name': name}), style: NawyText.h2),
                            Text('${user?.s('email') ?? ''} · ${app.countryName(app.country)}', style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                          ]),
                        ),
                      ]),
                    )
                  : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Text(context.t('account.guestTitle'), style: NawyText.h2),
                      const SizedBox(height: 4),
                      Text(context.t('auth.signInHint'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
                      const SizedBox(height: 12),
                      FilledButton(key: const ValueKey('account-sign-in'), onPressed: () => go(Routes.signIn, {'pop': true}), child: Text(context.t('auth.signIn'))),
                    ]),
            ),
            // Nawy One card.
            Container(
              margin: const EdgeInsets.symmetric(horizontal: 16),
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: NawyColors.bgInverse, borderRadius: BorderRadius.circular(NawyRadius.card)),
              child: Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.t('membership.title'), style: NawyText.h3.copyWith(color: NawyColors.bgBrand)),
                    Text(context.t('membership.pitch'), style: NawyText.bodySm.copyWith(color: NawyColors.textInverse)),
                  ]),
                ),
                FilledButton(onPressed: () => go(Routes.membership), child: Text(context.t('membership.join'))),
              ]),
            ),
            const SizedBox(height: 12),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Row(children: [
                for (final (icon, label, route) in [
                  (Icons.inventory_2_outlined, context.t('account.orders'), Routes.orders),
                  (Icons.replay_rounded, context.t('account.returns'), Routes.returns),
                  (Icons.favorite_border_rounded, context.t('wishlist.title'), Routes.wishlist),
                  (Icons.account_balance_wallet_outlined, context.t('account.credits'), Routes.wallet),
                ])
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: Material(
                        color: NawyColors.bgSurface,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(NawyRadius.card), side: const BorderSide(color: NawyColors.borderDefault)),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(NawyRadius.card),
                          onTap: () => go(route),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            child: Column(children: [Icon(icon), const SizedBox(height: 6), Text(label, style: NawyText.caption, maxLines: 1, overflow: TextOverflow.ellipsis)]),
                          ),
                        ),
                      ),
                    ),
                  ),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              padding: EdgeInsets.zero,
              child: Column(children: [
                _row(context, Icons.account_balance_wallet_outlined, context.t('account.credits'), () => go(Routes.wallet)),
                _row(context, Icons.card_giftcard_rounded, context.t('giftCards.title'), () => go(Routes.giftCards)),
                _row(context, Icons.location_on_outlined, context.t('address.title'), () => go(Routes.addresses)),
                _row(context, Icons.credit_card_rounded, context.t('cards.title'), () => go(Routes.cards)),
                _row(context, Icons.notifications_none_rounded, context.t('notifications.title'), () => go(Routes.notifications), badge: app.unread),
                _row(context, Icons.flight_takeoff_rounded, context.t('travel.myBookings'), () => go(Routes.myBookings)),
                _row(context, Icons.campaign_outlined, context.t('souq.myAds'), () => go(Routes.myAds)),
                _row(context, Icons.event_available_outlined, context.t('souq.myRequests'), () => go(Routes.viewingRequests)),
                _row(context, Icons.rate_review_outlined, context.t('reviews.mine'), () => go(Routes.myReviews)),
                _row(context, Icons.storefront_outlined, context.t('seller.followedTitle'), () => go(Routes.followedStores)),
                _row(context, Icons.support_agent_rounded, context.t('support.title'), () => go(Routes.support)),
                _row(context, Icons.public_rounded, context.t('market.title'), () => go(Routes.market, {'fromSettings': true}),
                    trailing: '${app.country?.s('iso2') ?? app.countrySlug?.toUpperCase() ?? ''} · ${app.locale.toUpperCase()}'),
                _row(context, Icons.shield_outlined, context.t('security.title'), () => go(Routes.security)),
                _row(context, Icons.person_outline_rounded, context.t('profile.title'), () => go(Routes.profile)),
                _row(context, Icons.help_outline_rounded, context.t('help.title'), () => go(Routes.help), last: !app.signedIn),
                if (app.signedIn)
                  _row(context, Icons.logout_rounded, context.t('auth.signOut'), () async {
                    final ok = await showDialog<bool>(
                      context: context,
                      builder: (ctx) => AlertDialog(
                        title: Text(ctx.t('auth.signOutConfirm')),
                        actions: [
                          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(ctx.t('common.cancel'))),
                          TextButton(onPressed: () => Navigator.of(ctx).pop(true), child: Text(ctx.t('auth.signOut'))),
                        ],
                      ),
                    );
                    if (ok == true && context.mounted) {
                      await AppScope.read(context).signOut();
                      if (context.mounted) showToast(context, context.t('auth.signedOut'));
                    }
                  }, last: true, danger: true),
              ]),
            ),
          ]),
        ),
      ),
    );
  }

  Widget _row(BuildContext context, IconData icon, String label, VoidCallback onTap, {String? trailing, int badge = 0, bool last = false, bool danger = false}) => Column(children: [
        ListTile(
          leading: Icon(icon, color: danger ? NawyColors.textDanger : null),
          title: Text(label, style: NawyText.body.copyWith(color: danger ? NawyColors.textDanger : null)),
          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
            if (trailing != null)
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 120),
                child: Text(trailing, maxLines: 1, overflow: TextOverflow.ellipsis, style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
              ),
            if (badge > 0) Badge(label: Text('$badge'), backgroundColor: NawyColors.bgDanger),
            const Icon(Icons.chevron_right_rounded, color: NawyColors.textMuted),
          ]),
          onTap: onTap,
        ),
        if (!last) const Divider(indent: 56, height: 1),
      ]);
}

/// Shared helper for account lists: newest first by `created_at`.
List<Json> newestFirst(List<Json> items) => [...items]..sort((a, b) => b.str('created_at').compareTo(a.str('created_at')));
