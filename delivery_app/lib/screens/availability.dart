import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';

/// Figma "13 Availability": go online / offline. The backend refuses going
/// offline while a parcel is being carried (owner review W10).
class AvailabilityScreen extends StatelessWidget {
  const AvailabilityScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('availability.title'))),
        body: AsyncBody<Json>(
          load: () => DeliveryApi(context.api).me(),
          builder: (context, me, reload) {
            final available = me.b('available');
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Icon(available ? Icons.wifi_tethering : Icons.wifi_tethering_off, size: 64, color: available ? NawyColors.textSuccess : NawyColors.textMuted),
              const SizedBox(height: 12),
              Text(context.t(available ? 'day.online' : 'day.offline'), style: NawyText.h2, textAlign: TextAlign.center),
              const SizedBox(height: 6),
              Text(context.t(available ? 'day.onlineHint' : 'day.offlineHint'), style: NawyText.body.copyWith(color: NawyColors.textSecondary), textAlign: TextAlign.center),
              const SizedBox(height: 20),
              FilledButton(
                key: const Key('toggle-availability'),
                onPressed: me.str('status') == 'suspended'
                    ? null
                    : () async {
                        final ok = await runAction(context, () => DeliveryApi(context.api).setAvailable(!available));
                        if (ok != null) reload();
                      },
                child: Text(context.t(available ? 'availability.goOffline' : 'availability.goOnline')),
              ),
              const SizedBox(height: 12),
              Text(context.t('availability.carrying'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary), textAlign: TextAlign.center),
            ]);
          },
        ),
      );
}
