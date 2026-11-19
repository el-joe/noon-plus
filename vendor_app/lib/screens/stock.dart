import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/vendor_api.dart';
import 'fbn.dart';

/// Figma "Vendor inventory": search, low-stock filter and stock moves.
class StockScreen extends StatefulWidget {
  const StockScreen({super.key, this.initialLowOnly = false});

  final bool initialLowOnly;

  @override
  State<StockScreen> createState() => _StockScreenState();
}

class _StockScreenState extends State<StockScreen> {
  late bool _low = widget.initialLowOnly;
  String _q = '';
  final _debounce = Debouncer();

  @override
  void dispose() {
    _debounce.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('stock.title')), actions: [
          IconButton(
            key: const Key('open-fbn'),
            tooltip: context.t('fbn.open'),
            icon: const Icon(Icons.warehouse_outlined),
            onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const FbnScreen())),
          ),
        ]),
        body: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: TextField(
              key: const Key('stock-search'),
              decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: context.t('stock.search')),
              onChanged: (v) => _debounce(() => setState(() => _q = v.trim())),
            ),
          ),
          SwitchListTile(
            key: const Key('low-only'),
            value: _low,
            title: Text(context.t('stock.lowOnly')),
            onChanged: (v) => setState(() => _low = v),
          ),
          Expanded(
            child: AsyncBody<List<Json>>(
              key: ValueKey('$_q|$_low'),
              load: () async => (await VendorApi(context.api).stock(q: _q.isEmpty ? null : _q, low: _low)).data,
              builder: (context, rows, reload) => rows.isEmpty
                  ? ListView(children: [EmptyView(icon: Icons.inventory_2_outlined, title: context.t('stock.empty'))])
                  : ListView.separated(
                      padding: const EdgeInsets.all(NawySpace.gutter),
                      itemCount: rows.length,
                      separatorBuilder: (_, _) => const SizedBox(height: 8),
                      itemBuilder: (context, i) {
                        final r = rows[i];
                        final low = isLowStock(r);
                        final fbn = r.str('fulfilment') == 'fbn';
                        return Card(
                          child: ListTile(
                            key: Key('stock-${r.i('listing_id')}'),
                            title: Text(r.str('title', r.str('sku')), maxLines: 2, overflow: TextOverflow.ellipsis),
                            subtitle: Text(fbn ? '${r.str('sku')} · ${context.t('fbn.badge')}' : r.str('sku')),
                            trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                              Text('${r.i('stock') ?? 0}', style: NawyText.h4.copyWith(color: low ? NawyColors.textDanger : null)),
                              if (low) Text(context.t('stock.low'), style: NawyText.caption.copyWith(color: NawyColors.textDanger)),
                            ]),
                            onTap: () async {
                              if (fbn) {
                                showSnack(context, context.t('fbn.managedByWarehouse'));
                                return;
                              }
                              final saved = await showModalBottomSheet<Json>(
                                context: context,
                                isScrollControlled: true,
                                builder: (_) => AdjustStockSheet(row: r, api: VendorApi(context.api)),
                              );
                              if (saved != null) reload();
                            },
                          ),
                        );
                      },
                    ),
            ),
          ),
        ]),
      );
}

/// Add or remove units with a reason (restock / correction / damaged / lost).
class AdjustStockSheet extends StatefulWidget {
  const AdjustStockSheet({super.key, required this.row, required this.api});

  final Json row;
  final VendorApi api;

  @override
  State<AdjustStockSheet> createState() => _AdjustStockSheetState();
}

class _AdjustStockSheetState extends State<AdjustStockSheet> {
  final _qty = TextEditingController();
  bool _remove = false;
  String? _reason = 'restock';
  String? _error;
  bool _busy = false;

  Future<void> _save() async {
    final current = widget.row.i('stock') ?? 0;
    final invalid = StockAdjustment.validate(raw: _qty.text, remove: _remove, reason: _reason, current: current);
    if (invalid != null) {
      setState(() => _error = context.t(invalid, {'count': current}));
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final saved = await widget.api.adjustStock(widget.row.i('listing_id')!, StockAdjustment.from(raw: _qty.text, remove: _remove, reason: _reason!));
      if (mounted) Navigator.pop(context, saved);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.fieldErrors['quantity'] ?? apiErrorMessage(context, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(16, 0, 16, 16 + MediaQuery.viewInsetsOf(context).bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(widget.row.str('title', widget.row.str('sku')), style: NawyText.h4),
          Text(context.t('stock.current', {'count': widget.row.i('stock') ?? 0}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          const SizedBox(height: 12),
          SegmentedButton<bool>(
            key: const Key('direction'),
            showSelectedIcon: false,
            segments: [
              ButtonSegment(value: false, label: Text(context.t('stock.add'))),
              ButtonSegment(value: true, label: Text(context.t('stock.remove'))),
            ],
            selected: {_remove},
            onSelectionChanged: (s) => setState(() {
              _remove = s.first;
              _reason = _remove ? 'damaged' : 'restock';
            }),
          ),
          const SizedBox(height: 12),
          TextField(key: const Key('quantity'), controller: _qty, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: context.t('stock.quantity'))),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: const Key('reason'),
            initialValue: _reason,
            decoration: InputDecoration(labelText: context.t('common.reason')),
            items: [for (final r in stockReasons) DropdownMenuItem(value: r, child: Text(context.t('stockReason.$r')))],
            onChanged: (v) => setState(() => _reason = v),
          ),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, key: const Key('stock-error'), style: NawyText.body.copyWith(color: NawyColors.textDanger))),
          const SizedBox(height: 16),
          FilledButton(key: const Key('save-stock'), onPressed: _busy ? null : _save, child: Text(context.t('common.save'))),
        ]),
      );
}
