import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/supervisor_api.dart';

/// Drivers of the company: status, vehicle, open stops, cash in hand and
/// last GPS ping; suspend / reactivate (new agents are activated by Nawy, P3);
/// "Invite driver" sends a team invitation (QA-003).
class DriversScreen extends StatelessWidget {
  const DriversScreen({super.key});

  Future<void> _invite(BuildContext context) async {
    final result = await showModalBottomSheet<Json>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const InviteDriverSheet(),
    );
    if (result != null && context.mounted) {
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          key: const Key('invitation-sent'),
          icon: const Icon(Icons.mark_email_read_outlined),
          title: Text(ctx.t('drivers.invitationSent')),
          content: Text(ctx.t('drivers.invitationSentHint', {'email': result.str('email')})),
          actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(ctx.t('common.confirm')))],
        ),
      );
    }
  }

  /// A refused suspension (422, e.g. open assignments: "reassign first")
  /// gets a dialog with the API's reason instead of a passing snack.
  Future<void> _suspend(BuildContext context, int id, VoidCallback reload) async {
    final reason = await askReason(context, title: context.t('drivers.suspendTitle'), minLength: 0, hint: context.t('drivers.reasonOptional'));
    if (reason == null || !context.mounted) return;
    try {
      await SupervisorApi(context.api).setDriverStatus(id, 'suspended', reason: reason.isEmpty ? null : reason);
      if (context.mounted) showSnack(context, context.t('drivers.suspended'));
      reload();
    } on ApiException catch (e) {
      if (!context.mounted) return;
      if (e.status == 422) {
        await showDialog<void>(
          context: context,
          builder: (ctx) => AlertDialog(
            key: const Key('suspend-refused'),
            title: Text(ctx.t('drivers.suspendRefused')),
            content: Text(apiErrorMessage(ctx, e)),
            actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(ctx.t('common.confirm')))],
          ),
        );
      } else {
        showSnack(context, apiErrorMessage(context, e));
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('drivers.title'))),
        floatingActionButton: FloatingActionButton.extended(
          key: const Key('invite-driver'),
          onPressed: () => _invite(context),
          icon: const Icon(Icons.person_add_alt_1_outlined),
          label: Text(context.t('drivers.invite')),
        ),
        body: AsyncBody<List<Json>>(
          load: () => SupervisorApi(context.api).drivers(),
          builder: (context, rows, reload) => rows.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.badge_outlined, title: context.t('drivers.none'))])
              : ListView.separated(
                  padding: const EdgeInsets.all(NawySpace.gutter),
                  itemCount: rows.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final d = rows[i];
                    final id = d.i('id')!;
                    final status = d.str('status');
                    final vehicle = d.m('vehicle') ?? const {};
                    final ping = DateTime.tryParse(d.str('last_ping_at'))?.toLocal();
                    final ml = MaterialLocalizations.of(context);
                    return Card(
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(children: [
                            Expanded(child: Text(d.str('name'), style: NawyText.h4)),
                            StatusChip(status),
                          ]),
                          if (d.s('phone') != null) PhoneLink(d.str('phone'), key: Key('call-driver-${d.i('id')}'), style: NawyText.bodySm),
                          Text(
                            [
                              if (vehicle.s('type') != null) context.t('vehicle.${vehicle.str('type')}'),
                              vehicle.s('plate'),
                              context.t('drivers.open', {'count': d.i('open_assignments') ?? 0}),
                              context.t('drivers.cod', {'amount': money(context, d['cod_in_hand'])}),
                            ].whereType<String>().join(' · '),
                            style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
                          ),
                          Text(
                            ping == null ? context.t('drivers.noPing') : context.t('drivers.ping', {'time': '${ml.formatShortDate(ping)} ${ml.formatTimeOfDay(TimeOfDay.fromDateTime(ping))}'}),
                            style: NawyText.bodySm.copyWith(color: NawyColors.textMuted),
                          ),
                          const SizedBox(height: 8),
                          if (status == 'active' || status == 'unavailable')
                            OutlinedButton(
                              key: Key('suspend-$id'),
                              style: OutlinedButton.styleFrom(foregroundColor: NawyColors.textDanger),
                              onPressed: () => _suspend(context, id, reload),
                              child: Text(context.t('drivers.suspend')),
                            )
                          else if (status == 'suspended' || status == 'pending_documents')
                            FilledButton(
                              key: Key('activate-$id'),
                              onPressed: () async {
                                final ok = await runAction(context, () => SupervisorApi(context.api).setDriverStatus(id, 'active'), success: context.t('drivers.activated'));
                                if (ok != null) reload();
                              },
                              child: Text(context.t('drivers.activate')),
                            ),
                        ]),
                      ),
                    );
                  },
                ),
        ),
      );
}

/// Name, email and optional phone / vehicle for `POST ops/supervisor/drivers`.
class InviteDriverSheet extends StatefulWidget {
  const InviteDriverSheet({super.key});

  @override
  State<InviteDriverSheet> createState() => _InviteDriverSheetState();
}

class _InviteDriverSheetState extends State<InviteDriverSheet> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _plate = TextEditingController();
  String? _vehicle;
  bool _busy = false;
  Map<String, String> _errors = {};

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _plate.dispose();
    super.dispose();
  }

  bool get _valid => _name.text.trim().length >= 2 && RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(_email.text.trim());

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _errors = {};
    });
    try {
      final result = await SupervisorApi(context.api).inviteDriver(
        name: _name.text.trim(),
        email: _email.text.trim(),
        phone: _phone.text.trim().isEmpty ? null : _phone.text.trim(),
        vehicleType: _vehicle,
        plate: _plate.text.trim().isEmpty ? null : _plate.text.trim(),
      );
      if (mounted) Navigator.pop(context, result);
    } on ApiException catch (e) {
      if (mounted) setState(() => _errors = {...e.fieldErrors, if (e.fieldErrors.isEmpty) 'form': apiErrorMessage(context, e)});
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(NawySpace.gutter, 8, NawySpace.gutter, NawySpace.gutter + MediaQuery.viewInsetsOf(context).bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(context.t('drivers.invite'), style: NawyText.h3),
          const SizedBox(height: 4),
          Text(context.t('drivers.inviteHint'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          const SizedBox(height: 12),
          TextField(key: const Key('invite-name'), controller: _name, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: context.t('drivers.name'), errorText: _errors['name'])),
          const SizedBox(height: 8),
          TextField(
            key: const Key('invite-email'),
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            textDirection: TextDirection.ltr,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(labelText: context.t('login.email'), errorText: _errors['email']),
          ),
          const SizedBox(height: 8),
          TextField(key: const Key('invite-phone'), controller: _phone, keyboardType: TextInputType.phone, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: context.t('drivers.phone'), errorText: _errors['phone'])),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(
              child: DropdownButtonFormField<String>(
                key: const Key('invite-vehicle'),
                isExpanded: true,
                initialValue: _vehicle,
                decoration: InputDecoration(labelText: context.t('drivers.vehicle')),
                items: [for (final v in const ['bike', 'car', 'van', 'truck']) DropdownMenuItem(value: v, child: Text(context.t('vehicle.$v')))],
                onChanged: (v) => setState(() => _vehicle = v),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(child: TextField(controller: _plate, decoration: InputDecoration(labelText: context.t('drivers.plate')))),
          ]),
          if (_errors['form'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_errors['form']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('invite-send'),
            onPressed: _valid && !_busy ? _send : null,
            child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(context.t('drivers.sendInvite')),
          ),
        ]),
      );
}
