import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/marketer_api.dart';

/// Conversions: attributed sales (gross) and the marketer's commission (net).
class ConversionsScreen extends StatefulWidget {
  const ConversionsScreen({super.key});

  @override
  State<ConversionsScreen> createState() => _ConversionsScreenState();
}

class _ConversionsScreenState extends State<ConversionsScreen> {
  final List<Json> _rows = [];
  int _page = 0;
  bool _hasMore = true;
  bool _loading = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _more();
  }

  Future<void> _more({bool reset = false}) async {
    if (_loading) return;
    setState(() {
      _loading = true;
      _error = null;
      if (reset) {
        _rows.clear();
        _page = 0;
        _hasMore = true;
      }
    });
    try {
      final res = await MarketerApi(context.api).conversions(page: _page + 1);
      if (!mounted) return;
      setState(() {
        _rows.addAll(res.data);
        _page = res.page;
        _hasMore = res.hasMore;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final currency = _rows.isEmpty ? session.currency : (Money.fromJson(_rows.first['commission'])?.currency ?? session.currency);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('conversions.title'))),
      body: _error != null && _rows.isEmpty
          ? ErrorView(error: _error!, onRetry: () => _more(reset: true))
          : RefreshIndicator(
              onRefresh: () => _more(reset: true),
              child: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
                if (_rows.isNotEmpty)
                  Text(
                    context.t('conversions.loadedTotal', {'amount': commissionTotal(_rows, currency).format(locale: context.l10n.locale)}),
                    key: const Key('commission-total'),
                    style: NawyText.bodyMedium,
                  ),
                if (_rows.isEmpty && !_loading) EmptyView(icon: Icons.trending_up, title: context.t('conversions.empty')),
                for (final c in _rows)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(c.s('order_number') ?? '—'),
                    subtitle: Text([c.s('campaign'), _date(context, c.s('occurred_at'))].whereType<String>().join(' · ')),
                    trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(money(context, c['commission']), style: NawyText.bodyMedium),
                      Text(context.t('conversions.of', {'amount': money(context, c['amount'])}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                      StatusChip(c.str('status')),
                    ]),
                  ),
                if (_loading) const LoadingView(),
                if (_hasMore && !_loading && _rows.isNotEmpty)
                  OutlinedButton(key: const Key('load-more'), onPressed: _more, child: Text(context.t('common.loadMore'))),
              ]),
            ),
    );
  }

  static String? _date(BuildContext context, String? iso) {
    final d = iso == null ? null : DateTime.tryParse(iso);
    return d == null ? null : MaterialLocalizations.of(context).formatShortDate(d.toLocal());
  }
}
