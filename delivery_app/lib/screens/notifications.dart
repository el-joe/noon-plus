import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';

/// Figma "14 Notifications": the user's in-app notifications
/// (`GET me/notifications`). Push needs `firebase_messaging` (owner review).
class NotificationsScreen extends StatelessWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('notifications.title'))),
        body: AsyncBody<List<Json>>(
          load: () async => (await DeliveryApi(context.api).notifications()).data,
          builder: (context, items, reload) => items.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.notifications_none, title: context.t('notifications.empty'))])
              : ListView(children: [
                  Align(
                    alignment: AlignmentDirectional.centerEnd,
                    child: TextButton(
                      onPressed: () async {
                        await runAction(context, () => DeliveryApi(context.api).readAllNotifications());
                        reload();
                      },
                      child: Text(context.t('notifications.readAll')),
                    ),
                  ),
                  for (final n in items)
                    ListTile(
                      leading: Icon(n.b('read') ? Icons.notifications_none : Icons.notifications_active, color: n.b('read') ? NawyColors.textMuted : NawyColors.textBrand),
                      title: Text(n.str('title'), style: n.b('read') ? NawyText.body : NawyText.bodyMedium),
                      subtitle: Text(n.str('body')),
                    ),
                ]),
        ),
      );
}
