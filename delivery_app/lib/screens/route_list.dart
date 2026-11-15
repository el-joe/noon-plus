import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/models.dart';
import 'my_day.dart';

/// Figma "03 Route map": the day's stops in order with one-tap navigation.
/// No map tiles in the app (no map package); "Navigate" hands the address
/// to the device's maps app through the `nawy/platform` channel.
class RouteScreen extends StatefulWidget {
  const RouteScreen({super.key});

  @override
  State<RouteScreen> createState() => _RouteScreenState();
}

class _RouteScreenState extends State<RouteScreen> {
  String _status = 'open';

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: Text(context.t('route.title')),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(52),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: SegmentedButton<String>(
                key: const Key('route-filter'),
                showSelectedIcon: false,
                segments: [
                  ButtonSegment(value: 'open', label: Text(context.t('route.open'))),
                  ButtonSegment(value: 'done', label: Text(context.t('route.done'))),
                ],
                selected: {_status},
                onSelectionChanged: (s) => setState(() => _status = s.first),
              ),
            ),
          ),
        ),
        body: AsyncBody<List<Assignment>>(
          key: ValueKey(_status),
          load: () => DeliveryApi(context.api).assignments(status: _status),
          builder: (context, stops, reload) => stops.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.route_outlined, title: context.t(_status == 'open' ? 'day.noStops' : 'route.noneDone'))])
              : ListView.separated(
                  padding: const EdgeInsets.all(NawySpace.gutter),
                  itemCount: stops.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final stop = stops[i];
                    return StopCard(
                      index: i + 1,
                      stop: stop,
                      onChanged: reload,
                      trailing: stop.status == 'picked_up'
                          ? IconButton.filledTonal(
                              key: Key('navigate-${stop.number}'),
                              tooltip: context.t('stop.navigate'),
                              icon: const Icon(Icons.navigation_outlined),
                              onPressed: () => PlatformBridge.openMaps(stop.addressLine),
                            )
                          : null,
                    );
                  },
                ),
        ),
      );
}
