import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/vendor_api.dart';

/// Figma "Vendor orders": orders to confirm / pack, filtered by status.
class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key, this.initialStatus});

  final String? initialStatus;

  @override
  State<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends State<OrdersScreen> {
  late String? _status = widget.initialStatus;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: Text(context.t('orders.title')),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(48),
            child: SizedBox(
              height: 48,
              child: SingleChildScrollView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6), child: Row(children: [
                for (final s in orderFilters)
                  Padding(
                    padding: const EdgeInsetsDirectional.only(end: 6),
                    child: ChoiceChip(
                      key: Key('filter-${s ?? 'all'}'),
                      label: Text(s == null ? context.t('common.all') : context.l10n.status(s)),
                      selected: _status == s,
                      labelStyle: NawyText.label.copyWith(color: _status == s ? NawyColors.textInverse : NawyColors.textPrimary),
                      showCheckmark: false,
                      onSelected: (_) => setState(() => _status = s),
                    ),
                  ),
              ])),
            ),
          ),
        ),
        body: AsyncBody<List<Json>>(
          key: ValueKey(_status),
          load: () async => (await VendorApi(context.api).orders(status: _status)).data,
          builder: (context, orders, reload) => orders.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.receipt_long_outlined, title: context.t('orders.empty'))])
              : ListView.separated(
                  padding: const EdgeInsets.all(NawySpace.gutter),
                  itemCount: orders.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final o = orders[i];
                    return Card(
                      child: ListTile(
                        key: Key('order-${o.i('id')}'),
                        title: Text(o.str('number'), style: NawyText.bodyMedium),
                        subtitle: Text('${context.t('orders.items', {'count': o.i('items_count') ?? 0})} · ${money(context, o['total'])}'),
                        trailing: StatusChip(o.str('status')),
                        onTap: () async {
                          await Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderDetailScreen(id: o.i('id')!)));
                          reload();
                        },
                      ),
                    );
                  },
                ),
        ),
      );
}

/// Order detail: items, the next fulfilment step and cancel (strike warning).
class OrderDetailScreen extends StatefulWidget {
  const OrderDetailScreen({super.key, required this.id});

  final int id;

  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('orders.detail'))),
        body: AsyncBody<Json>(
          load: () => VendorApi(context.api).order(widget.id),
          builder: (context, o, reload) {
            final next = o.s('next_step');
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Row(children: [Expanded(child: Text(o.str('number'), style: NawyText.h3)), StatusChip(o.str('status'))]),
              const SizedBox(height: 4),
              Text(context.t('orders.total', {'amount': money(context, o['total'])}), style: NawyText.body),
              SectionTitle(context.t('orders.itemsTitle')),
              Card(
                child: Column(children: [
                  for (final item in o.l('items'))
                    ListTile(
                      title: Text(item.str('title')),
                      subtitle: Text(item.str('sku')),
                      trailing: Text('${item.i('quantity') ?? 1} × ${money(context, item['unit_price'])}'),
                    ),
                ]),
              ),
              const SizedBox(height: 16),
              if (next != null)
                FilledButton(
                  key: const Key('advance'),
                  onPressed: () async {
                    final ok = await runAction(context, () => VendorApi(context.api).advance(widget.id, next), success: context.t('common.saved'));
                    if (ok != null) reload();
                  },
                  child: Text(o.s('next_step_label') ?? context.t('orders.markAs', {'status': context.l10n.status(next)})),
                ),
              if (o.b('can_cancel')) ...[
                const SizedBox(height: 10),
                OutlinedButton(
                  key: const Key('cancel-order'),
                  style: OutlinedButton.styleFrom(foregroundColor: NawyColors.textDanger),
                  onPressed: () async {
                    final reason = await askReason(context, title: context.t('orders.cancelTitle'));
                    if (reason == null || !context.mounted) return;
                    final ok = await runAction(context, () => VendorApi(context.api).cancel(widget.id, reason), success: context.t('orders.cancelled'));
                    if (ok != null) reload();
                  },
                  child: Text(context.t('orders.cancel')),
                ),
                const SizedBox(height: 6),
                Text(context.t('orders.strikeWarning'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
              ],
            ]);
          },
        ),
      );
}
