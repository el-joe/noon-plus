import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/models.dart';

/// Figma "15 Event history": the stop's timeline from its timestamps.
class HistoryScreen extends StatelessWidget {
  const HistoryScreen({super.key, required this.number});

  final String number;

  static const steps = ['accepted_at', 'picked_up_at', 'arrived_at', 'delivered_at', 'failed_at'];

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('history.title'))),
        body: AsyncBody<Assignment>(
          load: () => DeliveryApi(context.api).assignment(number),
          builder: (context, a, _) {
            final events = [for (final key in steps) if (a.at(key) != null) (key, a.at(key)!)]..sort((x, y) => x.$2.compareTo(y.$2));
            final ml = MaterialLocalizations.of(context);
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              ListTile(leading: const Icon(Icons.flag_outlined), title: Text(context.t('history.assigned')), subtitle: Text(a.number)),
              for (final (key, at) in events)
                ListTile(
                  leading: Icon(key == 'failed_at' ? Icons.cancel_outlined : Icons.check_circle_outline, color: key == 'failed_at' ? NawyColors.textDanger : NawyColors.textSuccess),
                  title: Text(context.t('history.$key')),
                  subtitle: Text('${ml.formatShortDate(at)} · ${ml.formatTimeOfDay(TimeOfDay.fromDateTime(at))}'),
                ),
              if (a.slaDueAt != null) ListTile(leading: const Icon(Icons.timer_outlined), title: Text(context.t('history.sla')), subtitle: Text(ml.formatTimeOfDay(TimeOfDay.fromDateTime(a.slaDueAt!)))),
            ]);
          },
        ),
      );
}
