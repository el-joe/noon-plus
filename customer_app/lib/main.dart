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
  late final AppState state;
  final api = ApiClient(
    baseUrl: AppConfig.apiUrl,
    transport: createTransport(),
    onUnauthenticated: () => state.signOut(revoke: false),
  );
  state = AppState(api: api, store: store);
  runApp(NawyApp(state: state));
}
