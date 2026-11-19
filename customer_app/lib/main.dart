import 'package:flutter/material.dart';

import 'app.dart';
import 'core/api/api_client.dart';
import 'core/api/transport.dart';
import 'core/config.dart';
import 'core/storage/secure_store.dart';
import 'state/app_state.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final store = await createDefaultStore();
  // AppState signs out on 401 / 403 `account_suspended` with a message.
  final api = ApiClient(baseUrl: AppConfig.apiUrl, transport: createTransport());
  final state = AppState(api: api, store: store);
  runApp(NawyApp(state: state));
}
