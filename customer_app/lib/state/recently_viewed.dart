import 'dart:async';

import '../core/api/api_client.dart';
import '../core/json.dart';
import '../core/storage/secure_store.dart';
import 'app_state.dart';

/// Recently viewed (QC-043): the device keeps a list for guests; once signed
/// in it is merged into the account (`POST me/recently-viewed`, newest view
/// wins, capped at 50) and the account's list is read back onto the device.
Future<void> syncRecentlyViewed(AppState app) async {
  if (!app.signedIn) return;
  final local = await app.readList(StoreKeys.recentlyViewed);
  final items = [
    for (final e in local)
      if (e.s('slug') != null) {'slug': e.s('slug'), if (e.s('viewed_at') != null) 'viewed_at': e.s('viewed_at')},
  ].take(50).toList();
  try {
    final res = items.isEmpty ? await app.api.get('me/recently-viewed') : await app.api.post('me/recently-viewed', body: {'items': items});
    final cards = asJsonList(res.data);
    await app.writeList(StoreKeys.recentlyViewed, cards.take(12).toList());
  } on ApiException {
    // Best effort: the device list stays as it was.
  }
}

/// The recently viewed cards to show: the account's list when signed in
/// (falls back to the device list offline), the device list otherwise.
Future<List<Json>> loadRecentlyViewed(AppState app) async {
  if (app.signedIn) {
    try {
      return (await app.api.getList('me/recently-viewed')).data;
    } on ApiException {
      // Offline: use the device list.
    }
  }
  return app.readList(StoreKeys.recentlyViewed);
}

/// Record a product view on the device and, when signed in, on the account.
Future<void> recordRecentlyViewed(AppState app, Json card) async {
  final entry = {...card, 'viewed_at': DateTime.now().toUtc().toIso8601String()};
  await app.pushRecent(StoreKeys.recentlyViewed, entry, idKey: 'slug');
  if (!app.signedIn || card.s('slug') == null) return;
  // Not awaited: the page does not wait for it; merged again at the next sign-in on failure.
  unawaited(app.api.post('me/recently-viewed', body: {
    'items': [
      {'slug': card.s('slug'), 'viewed_at': entry['viewed_at']},
    ],
  }).then((_) {}, onError: (_) {}));
}
