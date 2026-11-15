import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import 'states.dart';

/// Infinite list over a paged endpoint (`meta.page` / `meta.last_page`), with
/// pull-to-refresh and loading / empty / error states.
class PagedList extends StatefulWidget {
  const PagedList({super.key, required this.load, required this.itemBuilder, this.empty, this.header, this.padding = const EdgeInsets.only(top: 12, bottom: 24)});

  final Future<ApiResponse<List<Json>>> Function(int page) load;
  final Widget Function(BuildContext context, Json item) itemBuilder;
  final Widget? empty;
  final Widget? header;
  final EdgeInsets padding;

  @override
  State<PagedList> createState() => PagedListState();
}

class PagedListState extends State<PagedList> {
  final List<Json> items = [];
  final _scroll = ScrollController();
  int _page = 0;
  int _last = 1;
  bool _loading = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 400) _more();
    });
    _more();
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  Future<void> refresh() async {
    setState(() {
      items.clear();
      _page = 0;
      _last = 1;
      _error = null;
    });
    await _more();
  }

  Future<void> _more() async {
    if (_loading || _page >= _last) return;
    setState(() => _loading = true);
    try {
      final res = await widget.load(_page + 1);
      if (!mounted) return;
      setState(() {
        items.addAll(res.data);
        _page = res.page;
        // Some endpoints send only {total, page}: keep paging until a short/empty page.
        final known = res.meta.containsKey('last_page') || res.meta.containsKey('per_page');
        _last = res.data.isEmpty ? _page : (known ? res.lastPage : (res.total != null && items.length >= res.total! ? _page : _page + 1));
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty && _loading) return const LoadingView();
    if (items.isEmpty && _error != null) return ErrorView(error: _error!, onRetry: refresh);
    return RefreshIndicator(
      onRefresh: refresh,
      child: ListView.builder(
        controller: _scroll,
        physics: const AlwaysScrollableScrollPhysics(),
        padding: widget.padding,
        itemCount: items.length + 2,
        itemBuilder: (context, i) {
          if (i == 0) return widget.header ?? const SizedBox.shrink();
          if (i == items.length + 1) {
            if (items.isEmpty) return widget.empty ?? const SizedBox.shrink();
            return _loading ? const LoadingView(padding: EdgeInsets.all(16)) : const SizedBox(height: 16);
          }
          return widget.itemBuilder(context, items[i - 1]);
        },
      ),
    );
  }
}
