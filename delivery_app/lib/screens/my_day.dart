import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/location.dart';
import '../data/models.dart';
import 'notifications.dart';
import 'stop_detail.dart';

/// Figma "02 My day": greeting, online state, stops / pickups / COD held,
/// start route and the next stops.
class MyDayScreen extends StatefulWidget {
  const MyDayScreen({super.key, this.onOpenStops});

  final VoidCallback? onOpenStops;

  @override
  State<MyDayScreen> createState() => _MyDayScreenState();
}

class _MyDayScreenState extends State<MyDayScreen> {
  final _body = GlobalKey<AsyncBodyState<(Json, List<Assignment>)>>();
  final _cancelled = CancelledWatch();

  Future<(Json, List<Assignment>)> _load() async {
    final session = context.session;
    final api = DeliveryApi(session.api);
    final results = await Future.wait([api.me(), api.assignments()]);
    final me = results[0] as Json;
    await session.updateProfile(me);
    if (mounted) DutyScope.read(context)?.setOnDuty(me.b('available'));
    final stops = results[1] as List<Assignment>;
    if (mounted) DutyScope.read(context)?.updateStops(stops);
    final cancelled = await _cancelled.check(api.cancelledAmong, stops);
    if (cancelled.isNotEmpty && mounted) showCancelledNotice(context, cancelled);
    return (me, stops);
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    return Scaffold(
      body: SafeArea(
        child: AsyncBody<(Json, List<Assignment>)>(
          key: _body,
          load: _load,
          builder: (context, data, reload) {
            final (me, stops) = data;
            final available = me.b('available');
            final codInHand = codInHandOf(me, session.currency);
            final toCollect = CodMath.toCollect(stops, session.currency);
            final pickups = stops.where((s) => s.status != 'picked_up').length;
            final locale = context.l10n.locale;
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.t('day.hello', {'name': me.str('name').split(' ').first}), style: NawyText.h2),
                    if (me.s('carrier') != null) Text(me.str('carrier'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                  ]),
                ),
                IconButton(
                  key: const Key('open-notifications'),
                  tooltip: context.t('notifications.title'),
                  icon: const Icon(Icons.notifications_none),
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const NotificationsScreen())),
                ),
              ]),
              const SizedBox(height: 8),
              Card(
                  child: SwitchListTile(
                    key: const Key('availability'),
                    value: available,
                    title: Text(context.t(available ? 'day.online' : 'day.offline'), style: NawyText.h4),
                    subtitle: Text(context.t(available ? 'day.onlineHint' : 'day.offlineHint')),
                    onChanged: (v) async {
                      final ok = await runAction(context, () => DeliveryApi(context.api).setAvailable(v));
                      if (ok != null) reload();
                    },
                  ),
              ),
              const GpsBanner(),
              const SizedBox(height: 12),
              KpiGrid(tiles: [
                KpiTile(key: const Key('kpi-stops'), value: '${stops.length}', label: context.t('day.stops'), onTap: widget.onOpenStops),
                KpiTile(value: '$pickups', label: context.t('day.pickups')),
                KpiTile(key: const Key('kpi-cod'), value: codInHand.format(locale: locale), label: context.t('day.codHeld'), highlight: true),
              ]),
              if (!toCollect.isZero) ...[
                const SizedBox(height: 8),
                Text(context.t('day.toCollect', {'amount': toCollect.format(locale: locale)}), key: const Key('to-collect'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
              ],
              const SizedBox(height: 16),
              FilledButton(
                key: const Key('start-route'),
                onPressed: stops.isEmpty ? null : widget.onOpenStops,
                child: Text(context.t('day.startRoute', {'count': stops.length})),
              ),
              SectionTitle(context.t('day.nextStops')),
              if (stops.isEmpty) EmptyView(icon: Icons.local_shipping_outlined, title: context.t('day.noStops'), subtitle: context.t('day.noStopsHint')),
              for (final (i, stop) in stops.take(5).indexed) ...[
                StopCard(index: i + 1, stop: stop, onChanged: reload),
                const SizedBox(height: 8),
              ],
            ]);
          },
        ),
      ),
    );
  }
}

/// "Delivery … was cancelled and removed from your stops" (QA-005).
void showCancelledNotice(BuildContext context, List<Assignment> cancelled) {
  final numbers = cancelled.map((a) => a.number).join(', ');
  ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(
    key: const Key('cancelled-notice'),
    duration: const Duration(seconds: 8),
    content: Text(context.t('stop.cancelledNotice', {'numbers': numbers})),
  ));
}

/// A stop row: number bubble, address, ETA/SLA and COD / status tag.
class StopCard extends StatelessWidget {
  const StopCard({super.key, required this.index, required this.stop, this.onChanged, this.trailing});

  final int index;
  final Assignment stop;
  final VoidCallback? onChanged;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final locale = context.l10n.locale;
    final due = stop.slaDueAt;
    final subtitle = [
      if (due != null) context.t('stop.due', {'time': TimeOfDay.fromDateTime(due).format(context)}),
      if (stop.isCod) context.t('stop.cod', {'amount': stop.codAmount!.format(locale: locale)}),
    ].join(' · ');
    return Card(
      child: InkWell(
        key: Key('stop-${stop.number}'),
        borderRadius: BorderRadius.circular(NawyRadius.card),
        onTap: () async {
          await Navigator.of(context).push(MaterialPageRoute(builder: (_) => StopDetailScreen(number: stop.number)));
          onChanged?.call();
        },
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(children: [
            CircleAvatar(radius: 14, backgroundColor: NawyColors.bgBrand, child: Text('$index', style: NawyText.label)),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(stop.addressLine.isEmpty ? stop.shipmentNumber : stop.addressLine, style: NawyText.bodyMedium, maxLines: 2, overflow: TextOverflow.ellipsis),
                if (subtitle.isNotEmpty) Text(subtitle, style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
              ]),
            ),
            const SizedBox(width: 8),
            trailing ?? (stop.isCod && stop.isOpen ? StatusChip('pending', label: context.t('stop.codTag')) : StatusChip(stop.status)),
          ]),
        ),
      ),
    );
  }
}

/// Asks for the location permission (with the reason first) while the agent
/// is online or on the road, points to the settings when it was refused / GPS
/// is off, and on the road asks for "all the time" (explained first) so
/// tracking keeps going with the screen off.
class GpsBanner extends StatelessWidget {
  const GpsBanner({super.key});

  @override
  Widget build(BuildContext context) {
    final tracker = DutyScope.maybeOf(context);
    final state = tracker?.state;
    if (tracker == null || !const {DutyGps.needsPermission, DutyGps.blocked, DutyGps.needsBackground}.contains(state)) return const SizedBox.shrink();
    final blocked = state == DutyGps.blocked;
    final background = state == DutyGps.needsBackground;
    final servicesOff = tracker.access == LocationAccess.servicesOff;
    final title = background ? 'gps.alwaysTitle' : (tracker.onTrip ? 'gps.tripTitle' : 'gps.bannerTitle');
    final body = background
        ? 'gps.alwaysBody'
        : servicesOff
            ? 'gps.servicesOff'
            : blocked
                ? 'gps.blocked'
                : (tracker.onTrip ? 'gps.tripBody' : 'gps.bannerBody');
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Card(
        key: const Key('gps-banner'),
        color: NawyColors.bgWarningSubtle,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const Icon(Icons.location_on_outlined, color: NawyColors.textBrand),
              const SizedBox(width: 8),
              Expanded(child: Text(context.t(title), style: NawyText.h4)),
            ]),
            const SizedBox(height: 4),
            Text(context.t(body), style: NawyText.bodySm),
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: Wrap(spacing: 4, children: [
                if (background) TextButton(key: const Key('gps-not-now'), onPressed: tracker.dismissBackground, child: Text(context.t('gps.notNow'))),
                TextButton(
                  key: const Key('gps-allow'),
                  onPressed: background ? tracker.requestBackground : (blocked ? tracker.openSettings : tracker.requestAccess),
                  child: Text(context.t(background ? 'gps.allowAlways' : (servicesOff ? 'gps.turnOn' : (blocked ? 'gps.openSettings' : 'gps.allow')))),
                ),
              ]),
            ),
          ]),
        ),
      ),
    );
  }
}
