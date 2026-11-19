import 'dart:async';
import 'dart:convert';

import 'package:customer_app/screens/souq/listing_map.dart';
import 'package:customer_app/ui/widgets/common.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_map/flutter_map.dart';

/// Map tiles in tests: a transparent 1×1 PNG, so no tile is fetched.
class BlankTileProvider extends TileProvider {
  static final _png = base64Decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) => MemoryImage(_png);
}

/// Runs before every test file: no disk image cache in tests (the cache
/// manager needs sqflite / path_provider platform channels) and no network
/// map tiles.
Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  NetImage.useCache = false;
  MapConfig.tileProvider = BlankTileProvider.new;
  await testMain();
}
