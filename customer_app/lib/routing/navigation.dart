import 'package:flutter/widgets.dart';

import '../core/platform/platform_bridge.dart';
import '../state/app_state.dart';
import 'deep_links.dart';
import 'guards.dart';

/// Open an editor/notification link: http(s) in the system browser, storefront
/// paths as in-app screens, shell tabs by switching the tab bar.
Future<void> openLink(BuildContext context, String? link) async {
  if (link == null || link.isEmpty) return;
  if (link.startsWith('http://') || link.startsWith('https://')) {
    await PlatformBridge.openUrl(link);
    return;
  }
  final target = resolveStorefrontLink(link);
  if (target == null || !context.mounted) return;
  openAppLink(context, target);
}

void openAppLink(BuildContext context, AppLink target) {
  final navigator = Navigator.of(context);
  if (target.route == Routes.shell) {
    final app = AppScope.read(context);
    app.tabRequest.value = target.args['tab'] as String? ?? 'home';
    navigator.popUntil((route) => route.settings.name == Routes.shell || route.isFirst);
    return;
  }
  navigator.pushNamed(target.route, arguments: target.args);
}
