import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../state/compare_list.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

/// Compare products side by side (QC-043, `GET products/compare?slugs=`):
/// 2–4 products from [CompareList] with price, rating, stock, seller, model
/// number and the specification rows; remove and add to cart per product.
class CompareScreen extends StatelessWidget {
  const CompareScreen({super.key});

  @override
  Widget build(BuildContext context) => ValueListenableBuilder<List<String>>(
        valueListenable: CompareList.instance,
        builder: (context, slugs, _) => Scaffold(
          appBar: AppBar(
            title: Text(context.t('compare.title')),
            actions: [
              if (slugs.isNotEmpty)
                TextButton(key: const ValueKey('compare-clear'), onPressed: CompareList.instance.clear, child: Text(context.t('common.clear'))),
            ],
          ),
          body: slugs.length < 2
              ? EmptyView(
                  icon: Icons.compare_arrows_rounded,
                  title: context.t('compare.needTwo'),
                  subtitle: context.t('compare.needTwoHint'),
                  actionLabel: context.t('compare.browse'),
                  onAction: () => Navigator.of(context).pop(),
                )
              : AsyncBody<Json>(
                  key: ValueKey(slugs.join(',')),
                  load: () => AppScope.read(context).api.getObject('products/compare', query: {'slugs': slugs.join(',')}),
                  builder: (context, data, _) => _CompareTable(data: data),
                ),
        ),
      );
}

class _CompareTable extends StatelessWidget {
  const _CompareTable({required this.data});

  final Json data;

  static const _column = 150.0;
  static const _labelWidth = 110.0;

  @override
  Widget build(BuildContext context) {
    final products = data.l('products');
    final specs = data.l('specifications');
    final locale = context.l10n.locale;
    String text(Object? v) => v == null || '$v'.isEmpty ? '—' : '$v';

    final rows = <(String, List<String>)>[
      (context.t('compare.price'), [for (final p in products) Money.fromJson(p['price'])?.format(locale: locale) ?? '—']),
      (context.t('compare.rating'), [for (final p in products) p.m('rating')?.s('average') == null ? '—' : '${p.m('rating')!.s('average')} ★ (${p.m('rating')?.i('count') ?? 0})']),
      (context.t('compare.stock'), [for (final p in products) context.t(p.b('in_stock') ? 'compare.inStock' : 'product.outOfStock')]),
      (context.t('compare.brand'), [for (final p in products) text(p.s('brand'))]),
      (context.t('compare.seller'), [for (final p in products) text(p.s('seller'))]),
      (context.t('compare.model'), [for (final p in products) text(p.s('model_number'))]),
      for (final s in specs) (s.str('label'), [for (var i = 0; i < products.length; i++) text(i < (s['values'] as List? ?? const []).length ? (s['values'] as List)[i] : null)]),
    ];

    return SingleChildScrollView(
      padding: const EdgeInsets.only(bottom: 24),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const SizedBox(width: _labelWidth),
            for (final p in products) SizedBox(width: _column, child: _ProductHead(product: p)),
          ]),
          const Divider(),
          for (final (label, values) in rows)
            Container(
              padding: const EdgeInsets.symmetric(vertical: 8),
              decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: NawyColors.borderDefault))),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SizedBox(width: _labelWidth, child: Text(label, style: NawyText.label.copyWith(color: NawyColors.textSecondary))),
                for (final v in values)
                  SizedBox(width: _column, child: Padding(padding: const EdgeInsetsDirectional.only(end: 8), child: Text(v, style: NawyText.bodySm))),
              ]),
            ),
        ]),
      ),
    );
  }
}

class _ProductHead extends StatelessWidget {
  const _ProductHead({required this.product});

  final Json product;

  @override
  Widget build(BuildContext context) {
    final slug = product.str('slug');
    final listing = product.i('listing_id');
    return Padding(
      padding: const EdgeInsetsDirectional.only(end: 8),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Stack(children: [
          InkWell(
            onTap: () => Navigator.of(context).pushNamed(Routes.product, arguments: {'slug': slug}),
            child: SizedBox(width: 140, height: 140, child: NetImage(product.m('image')?.s('url'), radius: NawyRadius.card)),
          ),
          PositionedDirectional(
            top: 0,
            end: 0,
            child: IconButton(
              key: ValueKey('compare-remove-$slug'),
              tooltip: context.t('compare.remove'),
              visualDensity: VisualDensity.compact,
              onPressed: () => CompareList.instance.remove(slug),
              icon: const Icon(Icons.close_rounded, size: 18),
            ),
          ),
        ]),
        const SizedBox(height: 6),
        Text(product.str('title'), maxLines: 2, overflow: TextOverflow.ellipsis, style: NawyText.caption),
        const SizedBox(height: 6),
        SizedBox(
          width: 140,
          child: OutlinedButton(
            key: ValueKey('compare-add-$slug'),
            onPressed: listing == null || !product.b('in_stock') ? null : () => guarded(context, () => AppScope.read(context).cart.add(listing), success: context.t('cart.added')),
            child: Text(context.t('cart.add')),
          ),
        ),
      ]),
    );
  }
}
