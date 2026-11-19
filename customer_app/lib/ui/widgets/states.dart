import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../l10n/l10n.dart';
import '../theme.dart';

/// Loading / empty / error states (Figma "54 System states").
class LoadingView extends StatelessWidget {
  const LoadingView({super.key, this.padding = const EdgeInsets.all(32)});

  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => Padding(
        padding: padding,
        child: const Center(child: SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 2.5, color: NawyColors.textPrimary))),
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
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 88,
              height: 88,
              decoration: const BoxDecoration(color: NawyColors.bgBrandSubtle, shape: BoxShape.circle),
              child: Icon(icon, size: 40, color: NawyColors.textBrand),
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
          ],
        ),
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
      subtitle: e is ApiException && !offline && !notFound ? errorMessage(context, e) : context.t(offline ? 'state.offlineHint' : 'state.errorHint'),
      actionLabel: onRetry == null ? null : context.t('common.retry'),
      onAction: onRetry,
    );
  }
}

/// Load a future once (and on [reload]); shows loading / error / content.
class AsyncBody<T> extends StatefulWidget {
  const AsyncBody({super.key, required this.load, required this.builder, this.isEmpty, this.empty, this.loading});

  final Future<T> Function() load;
  final Widget Function(BuildContext context, T data, Future<void> Function() reload) builder;
  final bool Function(T data)? isEmpty;
  final Widget? empty;
  final Widget? loading;

  @override
  State<AsyncBody<T>> createState() => _AsyncBodyState<T>();
}

class _AsyncBodyState<T> extends State<AsyncBody<T>> {
  late Future<T> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.load();
  }

  Future<void> _reload() async {
    final next = widget.load();
    setState(() {
      _future = next;
    });
    try {
      await next;
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<T>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return widget.loading ?? const LoadingView();
          if (snap.hasError) return ErrorView(error: snap.error!, onRetry: _reload);
          final data = snap.data as T;
          if (widget.isEmpty != null && widget.isEmpty!(data)) return widget.empty ?? EmptyView(title: context.t('state.empty'));
          return widget.builder(context, data, _reload);
        },
      );
}

void showToast(BuildContext context, String message, {bool error = false}) {
  final messenger = ScaffoldMessenger.maybeOf(context);
  messenger?.hideCurrentSnackBar();
  messenger?.showSnackBar(SnackBar(
    content: Row(children: [
      Icon(error ? Icons.error_rounded : Icons.check_circle_rounded, color: error ? NawyColors.bgDanger : NawyColors.bgSuccess, size: 18),
      const SizedBox(width: 8),
      Expanded(child: Text(message)),
    ]),
  ));
}

/// The text for an API error: offline, rate limited (with the wait from
/// `Retry-After`), otherwise the first field error or the API message, which
/// the API already translated (`Content-Language`).
String errorMessage(BuildContext context, Object error) {
  if (error is! ApiException) return context.t('state.error');
  if (error.isNetwork) return context.t('state.offline');
  if (error.isRateLimited) {
    final seconds = error.retryAfter?.inSeconds;
    return seconds != null && seconds > 0 ? context.t('state.tooMany', {'seconds': seconds}) : context.t('state.tooManyNow');
  }
  return error.fieldErrors.values.firstOrNull ?? error.message;
}

/// Run an async action from a button: shows the API error as a toast.
Future<T?> guarded<T>(BuildContext context, Future<T> Function() action, {String? success}) async {
  try {
    final result = await action();
    if (success != null && context.mounted) showToast(context, success);
    return result;
  } catch (e) {
    if (context.mounted) showToast(context, errorMessage(context, e), error: true);
    return null;
  }
}

/// Live countdown "02:14:05" to [endsAt] (mega deals, delivery cut-off, holds).
class Countdown extends StatefulWidget {
  const Countdown({super.key, required this.endsAt, this.style, this.onDone, this.compact = false});

  final DateTime endsAt;
  final TextStyle? style;
  final VoidCallback? onDone;
  final bool compact;

  static String format(Duration left, {bool compact = false}) {
    if (left.isNegative) left = Duration.zero;
    final days = left.inDays;
    final h = left.inHours.remainder(24).toString().padLeft(2, '0');
    final m = left.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = left.inSeconds.remainder(60).toString().padLeft(2, '0');
    if (compact) return days > 0 ? '${days}d ${h}h' : '${left.inHours}h ${m}m';
    return days > 0 ? '${days}d $h:$m:$s' : '$h:$m:$s';
  }

  @override
  State<Countdown> createState() => _CountdownState();
}

class _CountdownState extends State<Countdown> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(() {});
      if (DateTime.now().isAfter(widget.endsAt)) {
        _timer?.cancel();
        widget.onDone?.call();
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Text(
        Countdown.format(widget.endsAt.difference(DateTime.now()), compact: widget.compact),
        style: widget.style ?? NawyText.label.copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
        textDirection: TextDirection.ltr,
      );
}

/// FutureBuilder whose future is created once (not on every rebuild).
class OnceFuture<T> extends StatefulWidget {
  const OnceFuture({super.key, required this.load, required this.builder});

  final Future<T> Function() load;
  final AsyncWidgetBuilder<T> builder;

  @override
  State<OnceFuture<T>> createState() => _OnceFutureState<T>();
}

class _OnceFutureState<T> extends State<OnceFuture<T>> {
  late final Future<T> _future = widget.load();

  @override
  Widget build(BuildContext context) => FutureBuilder<T>(future: _future, builder: widget.builder);
}
