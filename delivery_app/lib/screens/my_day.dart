import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
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

  Future<(Json, List<Assignment>)> _load() async {
    final session = context.session;
    final api = DeliveryApi(session.api);
    final results = await Future.wait([api.me(), api.assignments()]);
    final me = results[0] as Json;
    await session.updateProfile(me);
    return (me, results[1] as List<Assignment>);
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
            final codInHand = Money.parse(me.str('cod_in_hand', '0'), session.currency);
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
