import 'dart:convert';
import 'dart:io';

import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/core/storage/secure_store.dart';
import 'package:customer_app/core/uploads.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/testing.dart' show fakePhoto;

import '../helpers.dart';

class _Vault implements SecureVault {
  _Vault([Map<String, String>? initial]) : values = {...?initial};
  final Map<String, String> values;
  bool fail = false;
  int reads = 0;

  @override
  Future<String?> read(String key) async {
    if (fail) throw StateError('no keychain');
    reads++;
    return values[key];
  }

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);

  @override
  Future<Map<String, String>> readAll() async => {...values};
}

void main() {
  group('SecureStore migration (D16 / P1)', () {
    late Directory dir;
    setUp(() => dir = Directory.systemTemp.createTempSync('nawy_customer_store_'));
    tearDown(() => dir.deleteSync(recursive: true));

    test('moves the old JSON file into secure storage once and deletes it', () async {
      final legacy = File('${dir.path}/store.json')..writeAsStringSync(jsonEncode({StoreKeys.token: 'tok', StoreKeys.cartToken: 'cart', StoreKeys.market: 'uae-en'}));
      File('${legacy.path}.tmp').writeAsStringSync('{}');
      final vault = _Vault();
      final store = await createDefaultStore(vault: vault, legacyFile: () async => legacy);
      expect(store, isA<KeychainSecureStore>());
      expect(await store.readAll(), {StoreKeys.token: 'tok', StoreKeys.cartToken: 'cart', StoreKeys.market: 'uae-en'});
      expect(legacy.existsSync(), isFalse);
      expect(File('${legacy.path}.tmp').existsSync(), isFalse);

      final again = await createDefaultStore(vault: vault, legacyFile: () async => legacy);
      expect(await again.read(StoreKeys.token), 'tok');
    });

    test('values already in secure storage win', () async {
      final legacy = File('${dir.path}/store.json')..writeAsStringSync(jsonEncode({StoreKeys.token: 'old', StoreKeys.onboarded: '1'}));
      final store = KeychainSecureStore(_Vault({StoreKeys.token: 'new'}));
      expect(await migrateLegacyStore(legacy, store), 1);
      expect(await store.read(StoreKeys.token), 'new');
      expect(await store.read(StoreKeys.onboarded), '1');
    });

    test('a corrupt file is deleted; no file is a no-op', () async {
      final legacy = File('${dir.path}/store.json')..writeAsStringSync('{oops');
      expect(await migrateLegacyStore(legacy, MemorySecureStore()), 0);
      expect(legacy.existsSync(), isFalse);
      expect(await migrateLegacyStore(legacy, MemorySecureStore()), 0);
    });

    test('memory fallback when the platform has no secure storage', () async {
      final store = await createDefaultStore(vault: _Vault()..fail = true, legacyFile: () async => null);
      expect(store, isA<MemorySecureStore>());
    });

    test('Keychain store caches reads, deletes on null and refreshes on readAll', () async {
      final vault = _Vault({'a': '1'});
      final store = KeychainSecureStore(vault);
      expect(await store.read('a'), '1');
      expect(await store.read('a'), '1');
      expect(vault.reads, 1);
      await store.write('a', null);
      expect(vault.values, isEmpty);
      expect(await store.read('a'), isNull);
      vault.values['b'] = '2';
      expect(await store.readAll(), {'b': '2'});
      expect(await store.read('b'), '2');
    });
  });

  group('multipart uploads', () {
    test('formFields flattens nested bodies with bracket names', () {
      expect(
        formFields({
          'shipment': 'SH-1',
          'items': [
            {'order_item_id': 21, 'quantity': 1},
          ],
          'specs': {'color': 'red'},
          'negotiable': true,
          'comment': null,
        }),
        {'shipment': 'SH-1', 'items[0][order_item_id]': '21', 'items[0][quantity]': '1', 'specs[color]': 'red', 'negotiable': '1'},
      );
    });

    test('ApiClient.multipart sends the parts with bearer, cart token and language', () async {
      final transport = FakeTransport({'POST reviews': FakeTransport.ok({'id': 1})});
      final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport)
        ..market = 'uae-en'
        ..token = 'tok'
        ..cartToken = 'cart-1'
        ..locale = 'ar';
      await api.multipart('reviews', fields: {'rating': '5'}, files: [fakePhoto('a.jpg').part('photos[]')]);
      final r = transport.requests.single;
      expect(r.method, 'POST');
      expect(r.uri.toString(), 'https://api.test/api/v1/uae-en/reviews');
      expect(r.headers['Authorization'], 'Bearer tok');
      expect(r.headers['X-Cart-Token'], 'cart-1');
      expect(r.headers['Accept-Language'], 'ar');
      final body = MultipartBody.of(r);
      expect(body.fields, {'rating': '5'});
      expect(body.files.single, ('photos[]', 'a.jpg', 'image/jpeg', 68));
    });

    test('postWithFiles stays JSON without files', () async {
      final transport = FakeTransport({'POST returns': FakeTransport.ok({'number': 'RT-1'})});
      final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport)..market = 'uae-en';
      await postWithFiles(api, 'returns', {'reason': 'other'}, field: 'photos', files: const []);
      expect(transport.requests.single.headers['Content-Type'], 'application/json');
      expect(transport.bodyOf(transport.requests.single), {'reason': 'other'});
    });
  });
}
