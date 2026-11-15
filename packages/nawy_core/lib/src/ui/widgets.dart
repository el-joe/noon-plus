import 'dart:async';

import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../l10n.dart';
import '../money.dart';
import 'theme.dart';

/// Loading / empty / error states (Figma "System states").
class LoadingView extends StatelessWidget {
  const LoadingView({super.key});

  @override
  Widget build(BuildContext context) => const Padding(
        padding: EdgeInsets.all(32),
        child: Center(child: SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 2.5, color: NawyColors.textPrimary))),
      );
}

class EmptyView extends StatelessWidget {
  const EmptyView({super.key, required this.title, this.subtitle, this.icon = Icons.inbox_outlined, this.actionLabel, this.onAction});

  final String title;
  final String? subtitle;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 40),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: 72,
            height: 72,
            decoration: const BoxDecoration(color: NawyColors.bgBrandSubtle, shape: BoxShape.circle),
            child: Icon(icon, size: 34, color: NawyColors.textBrand),
          ),
          const SizedBox(height: 16),
          Text(title, style: NawyText.h4, textAlign: TextAlign.center),
          if (subtitle != null) ...[
            const SizedBox(height: 6),
            Text(subtitle!, style: NawyText.body.copyWith(color: NawyColors.textSecondary), textAlign: TextAlign.center),
          ],
          if (actionLabel != null && onAction != null) ...[
            const SizedBox(height: 20),
            FilledButton(onPressed: onAction, child: Text(actionLabel!)),
          ],
        ]),
      );
}

class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.error, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final e = error;
    final offline = e is ApiException && e.isNetwork;
    final notFound = e is ApiException && e.isNotFound;
    return EmptyView(
      icon: offline ? Icons.wifi_off_rounded : (notFound ? Icons.search_off_rounded : Icons.error_outline_rounded),
      title: context.t(offline ? 'state.offline' : (notFound ? 'state.notFound' : 'state.error')),
      subtitle: e is ApiException && !offline && !notFound ? e.message : context.t(offline ? 'state.offlineHint' : 'state.errorHint'),
      actionLabel: onRetry == null ? null : context.t('common.retry'),
      onAction: onRetry,
    );
  }
}

/// Loads [load] once (and again on pull-to-refresh / [AsyncBodyState.reload]).
class AsyncBody<T> extends StatefulWidget {
  const AsyncBody({super.key, required this.load, required this.builder, this.refreshable = true});

  final Future<T> Function() load;
  final Widget Function(BuildContext context, T data, Future<void> Function() reload) builder;
  final bool refreshable;

  @override
  State<AsyncBody<T>> createState() => AsyncBodyState<T>();
}

class AsyncBodyState<T> extends State<AsyncBody<T>> {
  late Future<T> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.load();
  }

  Future<void> reload() async {
    final next = widget.load();
    setState(() {
      _future = next;
    });
    try {
      await next;
    } catch (_) {
      // Shown by the builder.
    }
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<T>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const LoadingView();
          if (snap.hasError) return Center(child: SingleChildScrollView(child: ErrorView(error: snap.error!, onRetry: reload)));
          final child = widget.builder(context, snap.data as T, reload);
          return widget.refreshable ? RefreshIndicator(onRefresh: reload, child: child) : child;
        },
      );
}

/// KPI tile (Figma "My day" / dashboards: big value, small label).
class KpiTile extends StatelessWidget {
  const KpiTile({super.key, required this.value, required this.label, this.highlight = false, this.onTap});

  final String value;
  final String label;
  final bool highlight;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Card(
        color: highlight ? NawyColors.bgBrandSubtle : null,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(NawyRadius.card),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: Text(value, style: NawyText.h3)),
              const SizedBox(height: 2),
              Text(label, style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary), maxLines: 2, overflow: TextOverflow.ellipsis),
            ]),
          ),
        ),
      );
}

/// A wrap of KPI tiles, 2 or 3 per row.
class KpiGrid extends StatelessWidget {
  const KpiGrid({super.key, required this.tiles, this.columns = 3});

  final List<Widget> tiles;
  final int columns;

  @override
  Widget build(BuildContext context) => LayoutBuilder(builder: (context, c) {
        const gap = 8.0;
        final cols = c.maxWidth < 330 ? 2 : columns;
        final width = (c.maxWidth - gap * (cols - 1)) / cols;
        return Wrap(spacing: gap, runSpacing: gap, children: [for (final t in tiles) SizedBox(width: width, child: t)]);
      });
}

/// Small coloured status pill.
class StatusChip extends StatelessWidget {
  const StatusChip(this.status, {super.key, this.label});

  final String status;
  final String? label;

  static (Color, Color) colors(String status) {
    switch (status) {
      case 'delivered' || 'confirmed' || 'active' || 'completed' || 'approved' || 'settled' || 'available' || 'packed' || 'shipped':
        return (NawyColors.bgSuccessSubtle, NawyColors.textSuccess);
      case 'failed' || 'rejected' || 'cancelled' || 'suspended' || 'declined' || 'refused' || 'reversed' || 'disputed':
        return (NawyColors.bgDangerSubtle, NawyColors.textDanger);
      case 'assigned' || 'pending' || 'placed' || 'submitted' || 'requested' || 'pending_documents' || 'rescheduled' || 'returning_to_origin':
        return (NawyColors.bgWarningSubtle, NawyColors.textBrand);
      default:
        return (NawyColors.bgInfoSubtle, NawyColors.textLink);
    }
  }

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = colors(status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(NawyRadius.pill)),
      child: Text(label ?? context.l10n.status(status), style: NawyText.caption.copyWith(color: fg)),
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key, this.trailing});

  final String text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(0, 16, 0, 8),
        child: Row(children: [Expanded(child: Text(text, style: NawyText.h4)), ?trailing]),
      );
}

/// Money from an API map (`{amount, currency}`) formatted for the locale.
String money(BuildContext context, dynamic json, {String empty = '—'}) => formatMoney(json, locale: context.l10n.locale, empty: empty);

void showSnack(BuildContext context, String message) {
  ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(message)));
}

/// Runs [action]; shows the API error message on failure. Returns the result or null.
Future<T?> runAction<T>(BuildContext context, Future<T> Function() action, {String? success}) async {
  try {
    final result = await action();
    if (success != null && context.mounted) showSnack(context, success);
    return result;
  } on ApiException catch (e) {
    if (context.mounted) showSnack(context, e.fieldErrors.values.firstOrNull ?? e.message);
  }
  return null;
}

/// Asks for a free-text reason (min [minLength] chars). Null when cancelled.
Future<String?> askReason(BuildContext context, {required String title, int minLength = 5, String? hint}) {
  final controller = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (context) => StatefulBuilder(builder: (context, setState) {
      final ok = controller.text.trim().length >= minLength;
      return AlertDialog(
        title: Text(title),
        content: TextField(
          key: const Key('reason-field'),
          controller: controller,
          autofocus: true,
          maxLines: 3,
          maxLength: 255,
          onChanged: (_) => setState(() {}),
          decoration: InputDecoration(hintText: hint ?? context.t('common.reasonHint')),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(context.t('common.cancel'))),
          FilledButton(onPressed: ok ? () => Navigator.pop(context, controller.text.trim()) : null, child: Text(context.t('common.submit'))),
        ],
      );
    }),
  );
}

/// Debounce helper for search fields.
class Debouncer {
  Debouncer([this.delay = const Duration(milliseconds: 350)]);
  final Duration delay;
  Timer? _timer;
  void call(VoidCallback action) {
    _timer?.cancel();
    _timer = Timer(delay, action);
  }

  void dispose() => _timer?.cancel();
}
