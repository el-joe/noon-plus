import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/supervisor_api.dart';

/// Figma "Supervisor dispatch" / "assignments": parcels handed to the
/// company waiting for a driver, and the open assignments with their SLA.
class DispatchScreen extends StatelessWidget {
  const DispatchScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('dispatch.title'))),
        body: AsyncBody<Json>(
          load: () => SupervisorApi(context.api).dispatchBoard(),
          builder: (context, data, reload) {
            final unassigned = data.l('unassigned');
            final open = data.l('open');
            final drivers = data.l('drivers');
            final ml = MaterialLocalizations.of(context);
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              SectionTitle(context.t('dispatch.unassigned', {'count': unassigned.length})),
              if (unassigned.isEmpty) Text(context.t('dispatch.noneUnassigned'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
              for (final s in unassigned)
                Card(
                  child: ListTile(
                    key: Key('parcel-${s.i('id')}'),
                    title: Text(s.s('sub_order_number') ?? s.str('number')),
                    subtitle: Text([s.s('city'), if (s['cod_amount'] != null) context.t('dispatch.cod', {'amount': money(context, s['cod_amount'])})].whereType<String>().join(' · ')),
                    trailing: FilledButton(
                      key: Key('assign-${s.i('id')}'),
                      style: FilledButton.styleFrom(minimumSize: const Size(64, 36)),
                      onPressed: () async {
                        final choice = await showAssignSheet(context, drivers);
                        if (choice == null || !context.mounted) return;
                        final ok = await runAction(
                          context,
                          () => SupervisorApi(context.api).assign(s.i('id')!, agentId: choice.agentId),
                          success: context.t('dispatch.assigned'),
                        );
                        if (ok != null) reload();
                      },
                      child: Text(context.t('dispatch.assign')),
                    ),
                  ),
                ),
              SectionTitle(context.t('dispatch.open', {'count': open.length})),
              if (open.isEmpty) Text(context.t('dispatch.noneOpen'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
              for (final a in open)
                Card(
                  child: ListTile(
                    title: Text(a.str('number')),
                    subtitle: Text([
                      a.m('agent')?.s('name'),
                      a.s('city'),
                      if (DateTime.tryParse(a.str('sla_due_at')) != null)
                        context.t('dispatch.sla', {'time': ml.formatTimeOfDay(TimeOfDay.fromDateTime(DateTime.parse(a.str('sla_due_at')).toLocal()))}),
                    ].whereType<String>().join(' · ')),
                    trailing: StatusChip(a.str('status')),
                  ),
                ),
            ]);
          },
        ),
      );
}

/// The driver picked in the assign sheet; null [agentId] = auto-assign.
class AssignChoice {
  const AssignChoice(this.agentId);
  final int? agentId;
}

Future<AssignChoice?> showAssignSheet(BuildContext context, List<Json> drivers) => showModalBottomSheet<AssignChoice>(
      context: context,
      isScrollControlled: true,
      builder: (context) => SafeArea(
        child: ListView(shrinkWrap: true, children: [
          Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 8), child: Text(context.t('dispatch.pickDriver'), style: NawyText.h4)),
          ListTile(
            key: const Key('auto-assign'),
            leading: const Icon(Icons.auto_mode),
            title: Text(context.t('dispatch.auto')),
            subtitle: Text(context.t('dispatch.autoHint')),
            onTap: () => Navigator.pop(context, const AssignChoice(null)),
          ),
          const Divider(),
          for (final d in drivers)
            ListTile(
              key: Key('driver-${d.i('id')}'),
              leading: const Icon(Icons.person_outline),
              title: Text(d.str('name')),
              subtitle: Text(context.t('dispatch.driverOpen', {'count': d.i('open') ?? 0})),
              trailing: StatusChip(d.str('status')),
              enabled: d.str('status') == 'active',
              onTap: () => Navigator.pop(context, AssignChoice(d.i('id'))),
            ),
        ]),
      ),
    );
