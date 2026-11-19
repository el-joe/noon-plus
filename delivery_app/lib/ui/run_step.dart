import 'package:flutter/widgets.dart';
import 'package:nawy_core/nawy_core.dart';

/// Runs one assignment step (accept, start, arrive, confirm, fail…) like
/// [runAction]. When the API refuses the step (422 `transition_refused`
/// since wave 5a / QA-025, or the older `not_allowed`) the API message is
/// shown and [onRefused] runs so the screen reloads the assignment (or goes
/// back to the stop, which reloads it). Returns the result or null.
Future<T?> runStep<T>(BuildContext context, Future<T> Function() action, {String? success, VoidCallback? onRefused}) async {
  try {
    final result = await action();
    if (success != null && context.mounted) showSnack(context, success);
    return result;
  } on ApiException catch (e) {
    if (!context.mounted) return null;
    showSnack(context, apiErrorMessage(context, e));
    if (e.isTransitionRefused) onRefused?.call();
  }
  return null;
}
