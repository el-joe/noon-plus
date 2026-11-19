import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import 'app.dart';
import 'data/location.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final store = await createDefaultStore('nawy_delivery');
  // Session signs out with a message on 401 (token expired after 30 days)
  // and 403 `wrong_token` / `no_workspace` / `account_suspended`.
  final api = ApiClient(baseUrl: AppConfig.apiUrl, transport: createTransport());
  final session = Session(api: api, store: store);
  await session.load();
  runApp(DeliveryApp(session: session, location: const GeolocatorLocationSource()));
}
