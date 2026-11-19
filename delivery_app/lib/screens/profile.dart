import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/location.dart';
import 'availability.dart';
import 'documents.dart';
import 'notifications.dart';
import 'payslips.dart';
import 'wallet.dart';

/// Figma "16 Profile & GPS": name, company, phone and vehicle (editable),
/// GPS sharing state, documents, notifications, availability and settings.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _key = GlobalKey<AsyncBodyState<Json>>();

  void _push(Widget screen) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('profile.title'))),
        body: AsyncBody<Json>(
          key: _key,
          load: () => DeliveryApi(context.api).me(),
          builder: (context, me, reload) => ListView(children: [
            ListTile(
              leading: CircleAvatar(backgroundColor: NawyColors.bgBrand, child: Text(me.str('name', '?').characters.first, style: NawyText.h4)),
              title: Text(me.str('name'), style: NawyText.h4),
              subtitle: Text([me.s('carrier') ?? context.t('profile.nawyFleet'), me.s('email')].whereType<String>().join(' · ')),
              trailing: StatusChip(me.str('status')),
            ),
            const Divider(),
            _VehicleForm(me: me, onSaved: reload),
            const Divider(),
            const _GpsTile(),
            ListTile(leading: const Icon(Icons.toggle_on_outlined), title: Text(context.t('availability.title')), trailing: const Icon(Icons.chevron_right), onTap: () => _push(const AvailabilityScreen())),
            ListTile(key: const Key('open-documents'), leading: const Icon(Icons.description_outlined), title: Text(context.t('documents.title')), trailing: const Icon(Icons.chevron_right), onTap: () => _push(const DocumentsScreen())),
            ListTile(key: const Key('profile-wallet'), leading: const Icon(Icons.account_balance_wallet_outlined), title: Text(context.t('wallet.title')), trailing: const Icon(Icons.chevron_right), onTap: () => _push(const WalletScreen())),
            ListTile(key: const Key('profile-payslips'), leading: const Icon(Icons.receipt_long_outlined), title: Text(context.t('payslips.title')), trailing: const Icon(Icons.chevron_right), onTap: () => _push(const PayslipsScreen())),
            ListTile(leading: const Icon(Icons.notifications_none), title: Text(context.t('notifications.title')), trailing: const Icon(Icons.chevron_right), onTap: () => _push(const NotificationsScreen())),
            ListTile(
              key: const Key('open-settings'),
              leading: const Icon(Icons.settings_outlined),
              title: Text(context.t('settings.title')),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => _push(const SettingsScreen(logoutPath: DeliveryApi.logoutPath)),
            ),
          ]),
        ),
      );
}

class _VehicleForm extends StatefulWidget {
  const _VehicleForm({required this.me, required this.onSaved});

  final Json me;
  final VoidCallback onSaved;

  @override
  State<_VehicleForm> createState() => _VehicleFormState();
}

class _VehicleFormState extends State<_VehicleForm> {
  late final _phone = TextEditingController(text: widget.me.s('phone') ?? '');
  late final _plate = TextEditingController(text: widget.me.m('vehicle')?.s('plate') ?? '');
  late String? _type = widget.me.m('vehicle')?.s('type');
  bool _busy = false;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(NawySpace.gutter),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(context.t('profile.vehicle'), style: NawyText.h4),
          const SizedBox(height: 8),
          TextField(key: const Key('phone'), controller: _phone, keyboardType: TextInputType.phone, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: context.t('profile.phone'))),
          const SizedBox(height: 8),
          DropdownButtonFormField<String?>(
            key: const Key('vehicle-type'),
            initialValue: _type,
            decoration: InputDecoration(labelText: context.t('profile.vehicleType')),
            items: [
              DropdownMenuItem(value: null, child: Text(context.t('profile.vehicleNone'))),
              for (final v in const ['bike', 'car', 'van', 'truck']) DropdownMenuItem(value: v, child: Text(context.t('vehicle.$v'))),
            ],
            onChanged: (v) => setState(() => _type = v),
          ),
          const SizedBox(height: 8),
          TextField(key: const Key('plate'), controller: _plate, decoration: InputDecoration(labelText: context.t('profile.plate'))),
          const SizedBox(height: 12),
          FilledButton(
            key: const Key('save-profile'),
            onPressed: _busy
                ? null
                : () async {
                    setState(() => _busy = true);
                    final body = {'phone': _phone.text.trim(), 'vehicle_type': _type, 'vehicle_plate': _plate.text.trim().isEmpty ? null : _plate.text.trim()};
                    final ok = await runAction(context, () => DeliveryApi(context.api).updateProfile(body), success: context.t('common.saved'));
                    if (!context.mounted) return;
                    setState(() => _busy = false);
                    if (ok != null) widget.onSaved();
                  },
            child: Text(context.t('common.save')),
          ),
        ]),
      );
}

/// Figma "16 Profile & GPS": whether the location is being shared.
class _GpsTile extends StatelessWidget {
  const _GpsTile();

  @override
  Widget build(BuildContext context) {
    final tracker = DutyScope.maybeOf(context);
    final state = tracker?.state ?? DutyGps.unsupported;
    final sentAt = tracker?.lastSentAt;
    final on = context.t(tracker?.tracking == true ? 'profile.gpsTrip' : 'profile.gpsOn');
    final subtitle = switch (state) {
      DutyGps.sharing || DutyGps.needsBackground => sentAt == null ? on : '$on\n${context.t('profile.gpsSent', {'time': TimeOfDay.fromDateTime(sentAt).format(context)})}',
      DutyGps.offDuty => context.t('profile.gpsOff'),
      DutyGps.needsPermission => context.t('profile.gpsNeedsPermission'),
      DutyGps.blocked => context.t('profile.gpsBlocked'),
      DutyGps.unsupported => context.t('profile.gpsUnsupported'),
    };
    final sharing = state == DutyGps.sharing || state == DutyGps.needsBackground;
    return ListTile(
      key: const Key('gps'),
      leading: Icon(sharing ? Icons.gps_fixed : Icons.gps_off, color: sharing ? NawyColors.textSuccess : NawyColors.textMuted),
      title: Text(context.t('profile.gps')),
      subtitle: Text(subtitle),
      trailing: switch (state) {
        DutyGps.needsPermission => TextButton(onPressed: tracker!.requestAccess, child: Text(context.t('gps.allow'))),
        DutyGps.blocked => TextButton(onPressed: tracker!.openSettings, child: Text(context.t('gps.openSettings'))),
        DutyGps.needsBackground => TextButton(onPressed: tracker!.requestBackground, child: Text(context.t('gps.allowAlways'))),
        _ => null,
      },
    );
  }
}
