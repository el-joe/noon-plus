import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

/// Figma "05 Categories": left rail of top categories, right pane of
/// sub-category grids grouped by heading, then "Top brands"
/// (`GET categories/tree`, `GET brands?category=`).
class CategoriesTab extends StatefulWidget {
  const CategoriesTab({super.key});

  @override
  State<CategoriesTab> createState() => _CategoriesTabState();
}

class _CategoriesTabState extends State<CategoriesTab> {
  int _selected = 0;

  void _open(Json category) =>
      Navigator.of(context).pushNamed(Routes.products, arguments: {'category': category.str('slug'), 'title': category.str('name')});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('tabs.categories')),
        actions: [IconButton(tooltip: context.t('search.title'), onPressed: () => Navigator.of(context).pushNamed(Routes.search), icon: const Icon(Icons.search_rounded))],
      ),
      body: AsyncBody<List<Json>>(
        key: ValueKey('tree-${app.market}'),
        load: () => app.api.getList('categories/tree').then((r) => r.data),
        isEmpty: (tree) => tree.isEmpty,
        empty: EmptyView(icon: Icons.category_outlined, title: context.t('categories.empty')),
        builder: (context, tree, _) {
          if (_selected >= tree.length) _selected = 0;
          final root = tree[_selected];
          final groups = root.l('children');
          return Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Container(
              width: 96,
              color: NawyColors.bgPage,
              child: ListView.builder(
                itemCount: tree.length,
                itemBuilder: (context, i) {
                  final c = tree[i];
                  final active = i == _selected;
                  return InkWell(
                    key: ValueKey('rail-${c.str('slug')}'),
                    onTap: () => setState(() => _selected = i),
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
                      decoration: BoxDecoration(
                        color: active ? NawyColors.bgSurface : null,
                        border: BorderDirectional(start: BorderSide(color: active ? NawyColors.bgBrand : Colors.transparent, width: 3)),
                      ),
                      child: Column(children: [
                        ClipOval(child: SizedBox(width: 40, height: 40, child: NetImage(c.s('image'), icon: Icons.category_outlined))),
                        const SizedBox(height: 4),
                        Text(c.str('name'), textAlign: TextAlign.center, maxLines: 2, style: NawyText.caption.copyWith(fontWeight: active ? FontWeight.w700 : FontWeight.w500)),
                      ]),
                    ),
                  );
                },
              ),
            ),
            Expanded(
              child: Container(
                color: NawyColors.bgSurface,
                child: ListView(padding: const EdgeInsets.all(12), children: [
                  InkWell(
                    onTap: () => _open(root),
                    child: Row(children: [
                      Expanded(child: Text(root.str('name'), style: NawyText.h4)),
                      Text(context.t('categories.shopAll'), style: NawyText.label.copyWith(color: NawyColors.textLink)),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  if (groups.isEmpty) _grid(context, [root]),
                  for (final group in groups) ...[
                    InkWell(onTap: () => _open(group), child: Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Text(group.str('name'), style: NawyText.bodyMedium))),
                    _grid(context, group.l('children').isEmpty ? [group] : group.l('children')),
                    const Divider(height: 24),
                  ],
                  _TopBrands(category: root.str('slug')),
                ]),
              ),
            ),
          ]);
        },
      ),
    );
  }

  Widget _grid(BuildContext context, List<Json> items) => LayoutBuilder(builder: (context, c) {
        final w = (c.maxWidth - 16) / 3;
        return Wrap(spacing: 8, runSpacing: 10, children: [
          for (final item in items)
            SizedBox(
              width: w,
              child: InkWell(
                onTap: () => _open(item),
                child: Column(children: [
                  AspectRatio(aspectRatio: 1, child: NetImage(item.s('image'), radius: NawyRadius.card, icon: Icons.category_outlined)),
                  const SizedBox(height: 4),
                  Text(item.str('name'), textAlign: TextAlign.center, maxLines: 2, style: NawyText.caption),
                ]),
              ),
            ),
        ]);
      });
}

class _TopBrands extends StatelessWidget {
  const _TopBrands({required this.category});

  final String category;

  @override
  Widget build(BuildContext context) => OnceFuture<List<Json>>(
        key: ValueKey('brands-$category'),
        load: () => AppScope.read(context).api.getList('brands', query: {'category': category}).then((r) => r.data),
        builder: (context, snap) {
          final brands = (snap.data ?? const <Json>[]).take(9).toList();
          if (brands.isEmpty) return const SizedBox.shrink();
          return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(context.t('categories.topBrands'), style: NawyText.bodyMedium),
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final b in brands)
                ActionChip(
                  avatar: b.s('logo') == null ? null : NetImage(b.s('logo'), width: 20, height: 20, fit: BoxFit.contain),
                  label: Text(b.str('name')),
                  onPressed: () => Navigator.of(context).pushNamed(Routes.products, arguments: {'brand': b.str('slug'), 'title': b.str('name')}),
                ),
            ]),
          ]);
        },
      );
}
