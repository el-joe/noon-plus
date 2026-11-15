import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import 'app.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final store = await createDefaultStore('nawy_delivery');
  late final Session session;
  final api = ApiClient(baseUrl: AppConfig.apiUrl, transport: createTransport(), onUnauthenticated: () => session.signOut(revoke: false));
  session = Session(api: api, store: store);
  await session.load();
  runApp(DeliveryApp(session: session));
}
