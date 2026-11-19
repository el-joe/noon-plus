import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/vendor_api.dart';

/// Fulfilled by Nawy (Seller Center "Inventory & warehouses" on the phone):
/// FBN stock per SKU and warehouse, enrolment per listing (enrol / leave),
/// and inbound plans (list, new plan, submit, ship, cancel, carton labels PDF).
class FbnScreen extends StatelessWidget {
  const FbnScreen({super.key});

  @override
  Widget build(BuildContext context) => DefaultTabController(
        length: 3,
        child: Scaffold(
          appBar: AppBar(
            title: Text(context.t('fbn.title')),
            bottom: TabBar(tabs: [
              Tab(key: const Key('tab-fbn-stock'), text: context.t('fbn.stockTab')),
              Tab(key: const Key('tab-fbn-inbound'), text: context.t('fbn.inboundTab')),
              Tab(key: const Key('tab-fbn-enrol'), text: context.t('fbn.enrolTab')),
            ]),
          ),
          body: const TabBarView(children: [FbnStockList(), InboundList(), FbnEnrolmentList()]),
        ),
      );
}

/// FBN units per SKU, with the per-warehouse split and alerts.
class FbnStockList extends StatelessWidget {
  const FbnStockList({super.key});

  @override
  Widget build(BuildContext context) => AsyncBody<List<Json>>(
        load: () => VendorApi(context.api).fbnStock(),
        builder: (context, rows, reload) => rows.isEmpty
            ? ListView(children: [EmptyView(icon: Icons.warehouse_outlined, title: context.t('fbn.stockEmpty'), subtitle: context.t('fbn.stockEmptyHint'))])
            : ListView.separated(
                padding: const EdgeInsets.all(NawySpace.gutter),
                itemCount: rows.length,
                separatorBuilder: (_, _) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final r = rows[i];
                  final low = r.b('low_stock');
                  return Card(
                    key: Key('fbn-stock-${r.i('listing_id')}'),
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text(r.str('title', r.str('sku')), style: NawyText.h4, maxLines: 2, overflow: TextOverflow.ellipsis)),
                          Text('${r.i('available') ?? 0}', style: NawyText.h4.copyWith(color: low ? NawyColors.textDanger : null)),
                        ]),
                        Text('${r.str('sku')} · ${context.t('fbn.counts', {'reserved': r.i('reserved') ?? 0, 'damaged': r.i('damaged') ?? 0})}',
                            style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                        if (low || r.b('long_term') || r.b('unsellable'))
                          Padding(
                            padding: const EdgeInsets.only(top: 6),
                            child: Wrap(spacing: 6, children: [
                              if (low) _Flag(context.t('stock.low')),
                              if (r.b('long_term')) _Flag(context.t('fbn.longTerm')),
                              if (r.b('unsellable')) _Flag(context.t('fbn.unsellable')),
                            ]),
                          ),
                        for (final w in r.l('warehouses'))
                          Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: Text(context.t('fbn.warehouseRow', {'code': w.str('code'), 'available': w.i('available') ?? 0, 'onHand': w.i('on_hand') ?? 0}),
                                style: NawyText.caption),
                          ),
                      ]),
                    ),
                  );
                },
              ),
      );
}

class _Flag extends StatelessWidget {
  const _Flag(this.label);

  final String label;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
        decoration: BoxDecoration(color: NawyColors.bgDangerSubtle, borderRadius: BorderRadius.circular(4)),
        child: Text(label, style: NawyText.caption.copyWith(color: NawyColors.textDanger)),
      );
}

/// FBN enrolment per listing (Seller Center "Inventory & warehouses" →
/// enrol / leave): filter All / FBN / fulfilled by you; enrol takes the
/// seller's own declared units off sale, leaving needs an empty warehouse
/// (the server refuses with the reason otherwise).
class FbnEnrolmentList extends StatefulWidget {
  const FbnEnrolmentList({super.key});

  @override
  State<FbnEnrolmentList> createState() => _FbnEnrolmentListState();
}

class _FbnEnrolmentListState extends State<FbnEnrolmentList> {
  String? _filter;
  int _generation = 0;
  final _busy = <int>{};

  Future<void> _change(Json row, bool enrol) async {
    final id = row.i('listing_id')!;
    final sku = row.str('sku');
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        content: Text(context.t(enrol ? 'fbn.enrolConfirm' : 'fbn.leaveConfirm', {'sku': sku})),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(context.t('common.cancel'))),
          FilledButton(key: const Key('fbn-change-confirm'), onPressed: () => Navigator.pop(context, true), child: Text(context.t(enrol ? 'fbn.enrol' : 'fbn.leave'))),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _busy.add(id));
    try {
      final api = VendorApi(context.api);
      await (enrol ? api.enrolFbn(id) : api.leaveFbn(id));
      if (!mounted) return;
      showSnack(context, context.t(enrol ? 'fbn.enrolled' : 'fbn.left', {'sku': sku}));
      setState(() => _generation++);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.code == 'transition_refused' && e.message.isNotEmpty ? e.message : apiErrorMessage(context, e));
    } finally {
      if (mounted) setState(() => _busy.remove(id));
    }
  }

  @override
  Widget build(BuildContext context) {
    Widget chip(String? value, String label) => Padding(
          padding: const EdgeInsetsDirectional.only(end: 8),
          child: ChoiceChip(
            key: Key('fbn-filter-${value ?? 'all'}'),
            label: Text(context.t(label)),
            selected: _filter == value,
            onSelected: (_) => setState(() {
              _filter = value;
              _generation++;
            }),
          ),
        );
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(NawySpace.gutter, 12, NawySpace.gutter, 0),
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(children: [chip(null, 'fbn.filterAll'), chip('fbn', 'fbn.filterFbn'), chip('fbm', 'fbn.filterFbm')]),
        ),
      ),
      Expanded(
        child: AsyncBody<ApiResponse<List<Json>>>(
          key: ValueKey('enrol-$_generation'),
          load: () => VendorApi(context.api).fbnListings(fulfilment: _filter),
          builder: (context, res, reload) {
            final rows = res.data;
            final noWarehouse = res.meta['has_warehouses'] == false;
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              if (noWarehouse)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(context.t('fbn.noWarehouseHint'), key: const Key('fbn-no-warehouse'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                ),
              if (rows.isEmpty) EmptyView(icon: Icons.inventory_2_outlined, title: context.t('fbn.listingsEmpty')),
              for (final r in rows) ...[_EnrolCard(row: r, busy: _busy.contains(r.i('listing_id')), onChange: _change), const SizedBox(height: 8)],
            ]);
          },
        ),
      ),
    ]);
  }
}

class _EnrolCard extends StatelessWidget {
  const _EnrolCard({required this.row, required this.busy, required this.onChange});

  final Json row;
  final bool busy;
  final Future<void> Function(Json row, bool enrol) onChange;

  @override
  Widget build(BuildContext context) {
    final id = row.i('listing_id');
    final fbn = row.str('fulfilment') == 'fbn';
    return Card(
      key: Key('fbn-listing-$id'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(row.str('title', row.str('sku')), style: NawyText.h4, maxLines: 2, overflow: TextOverflow.ellipsis)),
            StatusChip(fbn ? 'active' : 'pending', key: Key('fbn-chip-$id'), label: context.t(fbn ? 'fbn.badge' : 'fbn.fbmChip')),
          ]),
          Text(row.str('sku'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          if (fbn) Text(context.t('fbn.units', {'count': row.i('warehouse_units') ?? 0}), style: NawyText.bodySm),
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: fbn
                ? OutlinedButton(key: Key('fbn-leave-$id'), onPressed: busy ? null : () => onChange(row, false), child: Text(context.t('fbn.leave')))
                : FilledButton(key: Key('fbn-enrol-$id'), onPressed: busy || !row.b('can_enrol') ? null : () => onChange(row, true), child: Text(context.t('fbn.enrol'))),
          ),
        ]),
      ),
    );
  }
}

/// The store's inbound plans, newest first, with a "new plan" button.
class InboundList extends StatefulWidget {
  const InboundList({super.key});

  @override
  State<InboundList> createState() => _InboundListState();
}

class _InboundListState extends State<InboundList> {
  int _generation = 0;

  Future<void> _open(Widget screen) async {
    final changed = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => screen));
    if (changed == true) setState(() => _generation++);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        floatingActionButton: FloatingActionButton.extended(
          key: const Key('new-inbound'),
          onPressed: () => _open(const NewInboundScreen()),
          icon: const Icon(Icons.add),
          label: Text(context.t('fbn.newPlan')),
        ),
        body: AsyncBody<List<Json>>(
          key: ValueKey(_generation),
          load: () async => (await VendorApi(context.api).inboundPlans()).data,
          builder: (context, rows, reload) => rows.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.local_shipping_outlined, title: context.t('fbn.inboundEmpty'))])
              : ListView.separated(
                  padding: const EdgeInsets.fromLTRB(NawySpace.gutter, NawySpace.gutter, NawySpace.gutter, 96),
                  itemCount: rows.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final r = rows[i];
                    return Card(
                      child: ListTile(
                        key: Key('inbound-${r.i('id')}'),
                        title: Text(r.str('reference')),
                        subtitle: Text(context.t('fbn.planRow', {'warehouse': r.m('warehouse')?.str('code') ?? '', 'units': r.i('units') ?? 0, 'cartons': r.i('cartons') ?? 0})),
                        trailing: Text(context.t('fbnStatus.${r.str('status')}'), style: NawyText.bodySm),
                        onTap: () => _open(InboundDetailScreen(id: r.i('id')!)),
                      ),
                    );
                  },
                ),
        ),
      );
}

/// New inbound plan: warehouse and units per FBN product; submit at once
/// or keep as a draft.
class NewInboundScreen extends StatefulWidget {
  const NewInboundScreen({super.key});

  @override
  State<NewInboundScreen> createState() => _NewInboundScreenState();
}

class _NewInboundScreenState extends State<NewInboundScreen> {
  int? _warehouseId;
  final _units = <int, TextEditingController>{};
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    for (final c in _units.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<(List<Json>, List<Json>)> _load() async {
    final api = VendorApi(context.api);
    final warehouses = await api.fbnWarehouses();
    final stock = await api.fbnStock();
    return (warehouses, stock);
  }

  Future<void> _save(bool submit) async {
    final draft = InboundDraft(
      warehouseId: _warehouseId,
      submit: submit,
      units: {for (final e in _units.entries) e.key: int.tryParse(e.value.text.trim()) ?? 0},
    );
    final invalid = draft.validate();
    if (invalid != null) {
      setState(() => _error = context.t(invalid));
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await VendorApi(context.api).createInboundPlan(draft);
      if (mounted) {
        showSnack(context, context.t(submit ? 'fbn.submitted' : 'fbn.drafted'));
        Navigator.pop(context, true);
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(context, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('fbn.newPlan'))),
        body: AsyncBody<(List<Json>, List<Json>)>(
          refreshable: false,
          load: _load,
          builder: (context, data, reload) {
            final (warehouses, products) = data;
            if (warehouses.isEmpty || products.isEmpty) {
              return ListView(children: [EmptyView(icon: Icons.warehouse_outlined, title: context.t(warehouses.isEmpty ? 'fbn.noWarehouse' : 'fbn.stockEmpty'))]);
            }
            _warehouseId ??= warehouses.first.i('id');
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              DropdownButtonFormField<int>(
                key: const Key('inbound-warehouse'),
                initialValue: _warehouseId,
                decoration: InputDecoration(labelText: context.t('fbn.warehouse')),
                items: [for (final w in warehouses) DropdownMenuItem(value: w.i('id'), child: Text('${w.str('code')} · ${w.str('name')}'))],
                onChanged: (v) => setState(() => _warehouseId = v),
              ),
              const SizedBox(height: 12),
              Text(context.t('fbn.unitsToSend'), style: NawyText.h4),
              for (final p in products)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: TextField(
                    key: Key('inbound-units-${p.i('listing_id')}'),
                    controller: _units.putIfAbsent(p.i('listing_id')!, TextEditingController.new),
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(labelText: '${p.str('title')} · ${p.str('sku')}'),
                  ),
                ),
              if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, key: const Key('inbound-error'), style: NawyText.body.copyWith(color: NawyColors.textDanger))),
              const SizedBox(height: 16),
              FilledButton(key: const Key('submit-inbound'), onPressed: _busy ? null : () => _save(true), child: Text(context.t('fbn.submit'))),
              const SizedBox(height: 8),
              OutlinedButton(key: const Key('draft-inbound'), onPressed: _busy ? null : () => _save(false), child: Text(context.t('fbn.saveDraft'))),
            ]);
          },
        ),
      );
}

/// One inbound plan: lines with received / damaged / short counts and the
/// actions its status allows.
class InboundDetailScreen extends StatefulWidget {
  const InboundDetailScreen({super.key, required this.id});

  final int id;

  @override
  State<InboundDetailScreen> createState() => _InboundDetailScreenState();
}

class _InboundDetailScreenState extends State<InboundDetailScreen> {
  int _generation = 0;
  bool _changed = false;

  Future<void> _act(String action, {Json body = const {}}) async {
    try {
      await VendorApi(context.api).inboundAction(widget.id, action, body: body);
      if (!mounted) return;
      showSnack(context, context.t('fbn.updated'));
      setState(() {
        _changed = true;
        _generation++;
      });
    } on ApiException catch (e) {
      if (mounted) showSnack(context, apiErrorMessage(context, e));
    }
  }

  Future<void> _downloadLabels(Json plan) async {
    try {
      final file = await VendorApi(context.api).inboundLabels(widget.id);
      final path = await PlatformBridge.saveDocument(file.filename ?? 'labels-${plan.str('number', '${widget.id}')}.pdf', file.bytes);
      if (mounted) showSnack(context, context.t('fbn.labelsSaved', {'path': path}));
    } catch (e) {
      if (mounted) showSnack(context, apiErrorMessage(context, e));
    }
  }

  Future<void> _ship() async {
    final shipped = await showDialog<Json>(context: context, builder: (_) => const _ShipDialog());
    if (shipped != null) await _act('ship', body: shipped);
  }

  @override
  Widget build(BuildContext context) => PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) Navigator.pop(context, _changed);
        },
        child: Scaffold(
          appBar: AppBar(title: Text(context.t('fbn.plan'))),
          body: AsyncBody<Json>(
            key: ValueKey(_generation),
            load: () => VendorApi(context.api).inboundPlan(widget.id),
            builder: (context, plan, reload) => ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Text(plan.str('reference'), style: NawyText.h3),
              Text('${context.t('fbnStatus.${plan.str('status')}')} · ${plan.m('warehouse')?.str('code') ?? ''}', key: const Key('inbound-status')),
              if (plan.s('tracking_number') != null) Text('${plan.str('carrier_name')} · ${plan.str('tracking_number')}', style: NawyText.bodySm),
              const SizedBox(height: 12),
              for (final line in plan.l('lines'))
                Card(
                  child: ListTile(
                    title: Text(line.str('title', line.str('sku'))),
                    subtitle: Text(context.t('fbn.lineCounts', {
                      'expected': line.i('quantity_expected') ?? 0,
                      'received': line.i('quantity_received') ?? 0,
                      'damaged': line.i('quantity_damaged') ?? 0,
                    })),
                    trailing: line.i('quantity_short') == null ? null : Text(context.t('fbn.short', {'count': line.i('quantity_short')!})),
                  ),
                ),
              const SizedBox(height: 16),
              if (plan.b('can_submit')) FilledButton(key: const Key('inbound-submit'), onPressed: () => _act('submit'), child: Text(context.t('fbn.submit'))),
              if (plan.b('can_ship')) FilledButton(key: const Key('inbound-ship'), onPressed: _ship, child: Text(context.t('fbn.markShipped'))),
              if (plan.b('can_cancel'))
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: OutlinedButton(key: const Key('inbound-cancel'), onPressed: () => _act('cancel'), child: Text(context.t('fbn.cancelPlan'))),
                ),
              if (plan.s('labels_path') != null) ...[
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: OutlinedButton.icon(
                    key: const Key('inbound-labels'),
                    onPressed: () => _downloadLabels(plan),
                    icon: const Icon(Icons.picture_as_pdf_outlined),
                    label: Text(context.t('fbn.downloadLabels')),
                  ),
                ),
                Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('fbn.labelsHint'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary))),
              ],
            ]),
          ),
        ),
      );
}

/// Carrier (required) and tracking number of a shipped plan.
class _ShipDialog extends StatefulWidget {
  const _ShipDialog();

  @override
  State<_ShipDialog> createState() => _ShipDialogState();
}

class _ShipDialogState extends State<_ShipDialog> {
  final _carrier = TextEditingController();
  final _tracking = TextEditingController();

  @override
  void dispose() {
    _carrier.dispose();
    _tracking.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(context.t('fbn.shipTitle')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(key: const Key('ship-carrier'), controller: _carrier, decoration: InputDecoration(labelText: context.t('fbn.carrier'))),
          TextField(key: const Key('ship-tracking'), controller: _tracking, decoration: InputDecoration(labelText: context.t('fbn.tracking'))),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(context.t('common.cancel'))),
          FilledButton(
            key: const Key('ship-confirm'),
            onPressed: () {
              final carrier = _carrier.text.trim();
              if (carrier.isEmpty) return;
              Navigator.pop(context, <String, dynamic>{'carrier_name': carrier, if (_tracking.text.trim().isNotEmpty) 'tracking_number': _tracking.text.trim()});
            },
            child: Text(context.t('fbn.markShipped')),
          ),
        ],
      );
}
