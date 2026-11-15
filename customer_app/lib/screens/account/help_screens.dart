import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../features/page_builder/blocks.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

/// Help centre (`help/help_center/collections`, `/search`, `/articles/{slug}`).
class HelpScreen extends StatefulWidget {
  const HelpScreen({super.key});

  @override
  State<HelpScreen> createState() => _HelpScreenState();
}

class _HelpScreenState extends State<HelpScreen> {
  final _q = TextEditingController();
  String _term = '';

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('help.title'))),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: TextField(
            controller: _q,
            textInputAction: TextInputAction.search,
            onSubmitted: (v) => setState(() => _term = v.trim()),
            decoration: InputDecoration(hintText: context.t('help.search'), prefixIcon: const Icon(Icons.search_rounded)),
          ),
        ),
        Expanded(
          child: _term.isNotEmpty
              ? AsyncBody<List<Json>>(
                  key: ValueKey(_term),
                  load: () => api.getList('help/help_center/search', query: {'q': _term}).then((r) => r.data),
                  isEmpty: (l) => l.isEmpty,
                  empty: EmptyView(icon: Icons.search_off_rounded, title: context.t('help.noResults')),
                  builder: (context, articles, _) => ListView(children: [for (final a in articles) _articleTile(context, a)]),
                )
              : AsyncBody<List<Json>>(
                  load: () => api.getList('help/help_center/collections', query: {'audience': 'customer'}).then((r) => r.data),
                  isEmpty: (l) => l.isEmpty,
                  empty: EmptyView(icon: Icons.help_outline_rounded, title: context.t('help.empty')),
                  builder: (context, collections, _) => ListView(children: [
                    for (final c in collections)
                      ExpansionTile(
                        title: Text(c.str('title'), style: NawyText.bodyMedium),
                        subtitle: c.s('description') == null ? null : Text(c.str('description'), maxLines: 2),
                        children: [for (final a in c.l('articles')) _articleTile(context, a)],
                      ),
                  ]),
                ),
        ),
      ]),
    );
  }

  Widget _articleTile(BuildContext context, Json a) => ListTile(
        leading: const Icon(Icons.article_outlined),
        title: Text(a.str('title')),
        trailing: const Icon(Icons.chevron_right_rounded),
        onTap: () => Navigator.of(context).pushNamed(Routes.helpArticle, arguments: {'slug': a.str('slug')}),
      );
}

class HelpArticleScreen extends StatefulWidget {
  const HelpArticleScreen({super.key, required this.slug});

  final String slug;

  @override
  State<HelpArticleScreen> createState() => _HelpArticleScreenState();
}

class _HelpArticleScreenState extends State<HelpArticleScreen> {
  Json? _helpful;
  bool? _myVote;

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    return Scaffold(
      appBar: AppBar(),
      body: AsyncBody<Json>(
        load: () => api.getObject('help/help_center/articles/${enc(widget.slug)}'),
        builder: (context, a, _) {
          final helpful = _helpful ?? a.m('helpful');
          return ListView(padding: const EdgeInsets.all(16), children: [
            if (a.m('collection_detail') != null) Text(a.m('collection_detail')!.str('title'), style: NawyText.caption.copyWith(color: NawyColors.textLink)),
            Text(a.str('title'), style: NawyText.h2),
            if (a.s('updated_at') != null) Text(shortDate(context, a.s('updated_at')), style: NawyText.caption.copyWith(color: NawyColors.textMuted)),
            const SizedBox(height: 12),
            Text(stripHtml(a.str('body')), style: NawyText.body),
            const Divider(height: 32),
            Text(context.t('help.wasHelpful'), style: NawyText.bodyMedium),
            const SizedBox(height: 8),
            Row(children: [
              for (final yes in const [true, false])
                Padding(
                  padding: const EdgeInsetsDirectional.only(end: 8),
                  child: ChoiceChip(
                    selected: _myVote == yes,
                    label: Text('${context.t(yes ? 'common.yes' : 'common.no')} (${helpful?.i(yes ? 'yes' : 'no') ?? 0})'),
                    onSelected: (_) async {
                      final res = await guarded(context, () => api.post('help/help_center/articles/${enc(widget.slug)}/votes', body: {'helpful': yes}));
                      if (res != null && mounted) {
                        final data = asJson(res.data);
                        setState(() {
                          _helpful = data.m('helpful');
                          _myVote = data['my_vote'] is bool ? data['my_vote'] as bool : yes;
                        });
                      }
                    },
                  ),
                ),
            ]),
          ]);
        },
      ),
    );
  }
}
