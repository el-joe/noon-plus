import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/models.dart';

/// Figma "12 Pickup scan". Barcode scanning needs a camera package
/// (`mobile_scanner`, owner review WP10); until then the agent ticks each
/// parcel by hand, then starts the delivery (picked up → out for delivery).
class PickupScreen extends StatefulWidget {
  const PickupScreen({super.key, required this.assignment});

  final Assignment assignment;

  @override
  State<PickupScreen> createState() => _PickupScreenState();
}

class _PickupScreenState extends State<PickupScreen> {
  late final List<bool> _checked = List.filled(widget.assignment.items.length, false);
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final a = widget.assignment;
    final allChecked = _checked.every((c) => c);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('pickup.title'))),
      body: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
        Text(context.t('pickup.from', {'seller': a.seller ?? '—'}), style: NawyText.h3),
        const SizedBox(height: 4),
        Text(context.t('pickup.hint'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            for (final (i, item) in a.items.indexed)
              CheckboxListTile(
                key: Key('parcel-$i'),
                value: _checked[i],
                onChanged: (v) => setState(() => _checked[i] = v ?? false),
                title: Text(item.str('title')),
                subtitle: Text('× ${item.i('quantity') ?? 1} · ${a.shipmentNumber}'),
              ),
          ]),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('pickup-start'),
          onPressed: !allChecked || _busy
              ? null
              : () async {
                  setState(() => _busy = true);
                  final ok = await runAction(context, () => DeliveryApi(context.api).start(a.number), success: context.t('pickup.started'));
                  if (!context.mounted) return;
                  setState(() => _busy = false);
                  if (ok != null) Navigator.pop(context, ok);
                },
          child: Text(context.t('pickup.start')),
        ),
      ]),
    );
  }
}
