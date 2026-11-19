import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/states.dart';
import '../account/addresses_screens.dart';

/// Header under the status bar (§14c, Figma "Context switcher" 94:11964):
/// the super-app tiles, then the address line (ETA in Minutes) and the pill
/// search. The whole header re-themes per context.
class AppHeader extends StatelessWidget {
  const AppHeader({super.key});

  static IconData contextIcon(String? icon) => switch (icon) {
        'home' => Icons.home_rounded,
        'shopping-bag' => Icons.shopping_bag_rounded,
        'utensils' => Icons.restaurant_rounded,
        'timer' => Icons.timer_rounded,
        'store' => Icons.storefront_rounded,
        'plane' => Icons.flight_rounded,
        'building' => Icons.apartment_rounded,
        'radio' => Icons.live_tv_rounded,
        _ => Icons.apps_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    final ctx = app.context;
    final theme = ctx.m('theme');
    final bg = hexColor(theme?.s('header_bg')) ?? NawyColors.bgBrand;
    final fg = hexColor(theme?.s('header_fg')) ?? NawyColors.textPrimary;
    final minutes = ctx.str('key') == 'minutes';

    return Material(
      color: bg,
      child: SafeArea(
        bottom: false,
        child: Column(children: [
          if (app.contexts.length > 1)
            SizedBox(
              height: 86,
              child: ListView.separated(
                key: const ValueKey('context-switcher'),
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                itemCount: app.contexts.length,
                separatorBuilder: (_, _) => const SizedBox(width: 8),
                itemBuilder: (context, i) {
                  final c = app.contexts[i];
                  final active = c.str('key') == app.activeContext;
                  final tileBg = hexColor(c.m('theme')?.s('header_bg')) ?? NawyColors.bgSurface;
                  final tileFg = hexColor(c.m('theme')?.s('header_fg')) ?? NawyColors.textPrimary;
                  return Semantics(
                    selected: active,
                    button: true,
                    label: c.str('name'),
                    child: InkWell(
                      key: ValueKey('context-${c.str('key')}'),
                      onTap: () => app.switchContext(c.str('key')),
                      borderRadius: BorderRadius.circular(NawyRadius.lg),
                      child: SizedBox(
                        width: 62,
                        child: Column(children: [
                          AnimatedContainer(
                            duration: const Duration(milliseconds: 150),
                            width: active ? 50 : 44,
                            height: active ? 50 : 44,
                            decoration: BoxDecoration(
                              color: tileBg,
                              borderRadius: BorderRadius.circular(NawyRadius.lg),
                              border: Border.all(color: active ? fg : NawyColors.bgSurface.withValues(alpha: 0.6), width: active ? 2 : 1),
                            ),
                            child: Icon(contextIcon(c.s('icon')), color: tileFg, size: 22),
                          ),
                          const SizedBox(height: 3),
                          Text(c.str('name'),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: NawyText.caption.copyWith(color: fg, fontWeight: active ? FontWeight.w700 : FontWeight.w500)),
                        ]),
                      ),
                    ),
                  );
                },
              ),
            ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 6, 16, 4),
            child: InkWell(
              onTap: () => Navigator.of(context).pushNamed(app.signedIn ? Routes.addresses : Routes.market, arguments: {'fromSettings': true}),
              child: Row(children: [
                Icon(minutes ? Icons.bolt_rounded : Icons.location_on_outlined, size: 18, color: fg),
                const SizedBox(width: 6),
                Expanded(child: minutes ? Text(context.t('header.minutesEta'), style: NawyText.label.copyWith(color: fg)) : _AddressLine(fg: fg)),
                Icon(Icons.keyboard_arrow_down_rounded, color: fg, size: 18),
              ]),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 10),
            child: Material(
              color: NawyColors.bgSurface,
              shape: const StadiumBorder(side: BorderSide(color: NawyColors.borderDefault)),
              child: InkWell(
                key: const ValueKey('search-pill'),
                customBorder: const StadiumBorder(),
                onTap: () => Navigator.of(context).pushNamed(Routes.search),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  child: Row(children: [
                    const Icon(Icons.search_rounded, size: 20, color: NawyColors.textSecondary),
                    const SizedBox(width: 8),
                    Expanded(child: Text(context.t('search.placeholder'), style: NawyText.body.copyWith(color: NawyColors.textMuted))),
                  ]),
                ),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}

class _AddressLine extends StatelessWidget {
  const _AddressLine({required this.fg});

  final Color fg;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    final countryName = app.country?.m('names')?.s(app.locale) ?? app.country?.s('name') ?? (app.countrySlug ?? '').toUpperCase();
    if (!app.signedIn) {
      return Text(context.t('header.deliverTo', {'place': countryName}), style: NawyText.label.copyWith(color: fg), maxLines: 1, overflow: TextOverflow.ellipsis);
    }
    return OnceFuture<List<Json>>(
      key: ValueKey('addr-${app.token}-${app.market}'),
      load: () => app.api.getList('addresses').then((r) => r.data),
      builder: (context, snap) {
        final list = snap.data ?? const <Json>[];
        final address = list.firstWhere((a) => a.b('is_default'), orElse: () => list.isNotEmpty ? list.first : <String, dynamic>{});
        if (address.isEmpty) {
          return Text(context.t('header.deliverTo', {'place': countryName}), style: NawyText.label.copyWith(color: fg), maxLines: 1);
        }
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(addressLabel(context, address.s('label')), style: NawyText.label.copyWith(color: fg)),
          Text([address.s('street'), address.s('area'), address.s('city')].whereType<String>().join(', '),
              maxLines: 1, overflow: TextOverflow.ellipsis, style: NawyText.caption.copyWith(color: fg.withValues(alpha: 0.85))),
        ]);
      },
    );
  }
}
