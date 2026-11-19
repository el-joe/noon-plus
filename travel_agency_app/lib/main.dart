import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import 'app.dart';
import 'screens/promotion_checkout.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Before the app's router: the promotion payment return deep link (D14).
  PromotionReturnLinks.install();
  final store = await createDefaultStore('nawy_travel');
  // Session signs out with a message on 401 (token expired after 30 days)
  // and 403 `wrong_token` / `no_workspace` / `account_suspended`.
  final api = ApiClient(baseUrl: AppConfig.apiUrl, transport: createTransport());
  final session = Session(api: api, store: store);
  await session.load();
  runApp(TravelApp(session: session));
}
