import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

void main() {
  const config = PusherConfig(key: 'app-key', host: 'ws.nawy.test', port: 8080, scheme: 'http');

  (PusherClient, FakePusherWire, List<Uri>) client() {
    final wire = FakePusherWire();
    final uris = <Uri>[];
    final c = PusherClient(config, connector: (uri) async {
      uris.add(uri);
      return wire;
    });
    return (c, wire, uris);
  }

  Future<void> established(FakePusherWire wire, [String id = '111.222']) async {
    await Future<void>.delayed(Duration.zero);
    wire.receive('pusher:connection_established', data: {'socket_id': id, 'activity_timeout': 30});
  }

  test('config: url, scheme and parsing', () {
    expect(config.uri.toString(), 'ws://ws.nawy.test:8080/app/app-key?protocol=7&client=nawy-dart&version=8.4.0&flash=false');
    final secure = PusherConfig.fromJson({'key': 'k', 'host': 'rt.nawy.ae', 'port': 443, 'scheme': 'https'});
    expect(secure.uri.scheme, 'wss');
    expect(secure.isValid, isTrue);
    expect(PusherConfig.fromJson(const {}).isValid, isFalse);
  });

  test('connect waits for connection_established and returns the socket id', () async {
    final (c, wire, uris) = client();
    final statuses = <PusherState>[];
    c.status.listen((s) => statuses.add(s.state));
    final connecting = c.connect();
    await established(wire);
    expect(await connecting, '111.222');
    expect(c.socketId, '111.222');
    expect(uris.single.path, '/app/app-key');
    expect(uris.single.queryParameters['protocol'], '7');
    expect(statuses, [PusherState.connecting, PusherState.connected]);
    await c.close();
  });

  test('private subscribe sends the auth from the callback; succeeded completes it', () async {
    final (c, wire, _) = client();
    final connecting = c.connect();
    await established(wire);
    await connecting;
    final authCalls = <(String, String)>[];
    final sub = c.subscribe('private-live.demo.peer.p1', authorize: (socketId, channel) async {
      authCalls.add((socketId, channel));
      return 'app-key:sig';
    });
    var done = false;
    unawaited(sub.then((_) => done = true));
    await Future<void>.delayed(Duration.zero);
    expect(authCalls.single, ('111.222', 'private-live.demo.peer.p1'));
    final frame = wire.sentEvents('pusher:subscribe').single;
    expect(frame['data'], {'channel': 'private-live.demo.peer.p1', 'auth': 'app-key:sig'});
    expect(done, isFalse);
    wire.receive('pusher_internal:subscription_succeeded', channel: 'private-live.demo.peer.p1');
    await sub;
    expect(done, isTrue);
    await c.close();
  });

  test('channel events are forwarded with decoded JSON data; ping gets a pong', () async {
    final (c, wire, _) = client();
    final connecting = c.connect();
    await established(wire);
    await connecting;
    final got = <PusherMessage>[];
    c.messages.listen(got.add);
    wire.receive('signal', channel: 'private-live.demo.peer.p1', data: {'type': 'offer', 'sdp': 'v=0'});
    wire.receive('pusher:ping');
    await Future<void>.delayed(Duration.zero);
    expect(got.single.event, 'signal');
    expect(got.single.json['type'], 'offer');
    expect(wire.sentEvents('pusher:pong'), hasLength(1));
    await c.close();
  });

  test('subscription_error and a failed auth throw PusherException', () async {
    final (c, wire, _) = client();
    final connecting = c.connect();
    await established(wire);
    await connecting;
    final refused = c.subscribe('private-x', authorize: (_, _) async => 'k:s');
    await Future<void>.delayed(Duration.zero);
    wire.receive('pusher:subscription_error', channel: 'private-x', data: {'type': 'AuthError', 'error': 'Forbidden', 'status': 403});
    await expectLater(refused, throwsA(isA<PusherException>().having((e) => e.code, 'code', 403)));
    await expectLater(c.subscribe('private-y', authorize: (_, _) async => throw StateError('403')), throwsA(isA<PusherException>()));
    await c.close();
  });

  test('pusher:error is reported; an unexpected close is signalled', () async {
    final (c, wire, _) = client();
    final statuses = <PusherStatus>[];
    c.status.listen(statuses.add);
    final connecting = c.connect();
    await established(wire);
    await connecting;
    final sub = c.subscribe('public-chan');
    await Future<void>.delayed(Duration.zero);
    wire.receive('pusher_internal:subscription_succeeded', channel: 'public-chan');
    await sub;

    wire.receive('pusher:error', data: {'message': 'Over capacity', 'code': 4100});
    await Future<void>.delayed(Duration.zero);
    expect(statuses.last.error, 'Over capacity');
    expect(statuses.last.code, 4100);

    wire.serverClose(1006);
    await Future<void>.delayed(Duration.zero);
    expect(c.state, PusherState.closed);
    expect(statuses.last.state, PusherState.closed);
    expect(statuses.last.error, isNotNull);
    await c.close();
  });

  test('reconnect re-subscribes known channels', () async {
    var wire = FakePusherWire();
    final c = PusherClient(config, connector: (_) async => wire);
    var connecting = c.connect();
    await established(wire);
    await connecting;
    final sub = c.subscribe('public-chan');
    await Future<void>.delayed(Duration.zero);
    wire.receive('pusher_internal:subscription_succeeded', channel: 'public-chan');
    await sub;
    wire.serverClose();
    await Future<void>.delayed(Duration.zero);
    wire = FakePusherWire();
    connecting = c.connect();
    await established(wire, '9.9');
    expect(await connecting, '9.9');
    await Future<void>.delayed(Duration.zero);
    expect(wire.sentEvents('pusher:subscribe').single['data'], {'channel': 'public-chan'});
    await c.close();
  });

  test('a connect failure completes with an error and reports closed', () async {
    final c = PusherClient(config, connector: (_) async => throw StateError('refused'));
    final statuses = <PusherState>[];
    c.status.listen((s) => statuses.add(s.state));
    await expectLater(c.connect(), throwsA(isA<PusherException>()));
    expect(statuses.last, PusherState.closed);
    await c.close();
  });

  testWidgets('an idle socket sends its own ping after activity_timeout', (tester) async {
    final wire = FakePusherWire();
    final c = PusherClient(config, connector: (_) async => wire);
    final connecting = c.connect();
    await tester.pump();
    wire.receive('pusher:connection_established', data: {'socket_id': '1.2', 'activity_timeout': 5});
    await tester.pump();
    await connecting;
    await tester.pump(const Duration(seconds: 6));
    expect(wire.sentEvents('pusher:ping'), hasLength(1));
    wire.receive('pusher:pong');
    await tester.pump();
    await tester.pump(const Duration(seconds: 31));
    expect(c.state, PusherState.connected);
    await c.close();
  });

  test('FakePusherSocket: authorizer runs, events and drops reach listeners', () async {
    final fake = FakePusherSocket();
    await fake.connect();
    await fake.subscribe('private-a', authorize: (id, ch) async => 'auth-$id');
    expect(fake.subscriptions['private-a'], 'auth-123.456');
    final got = <PusherMessage>[];
    final states = <PusherState>[];
    fake.messages.listen(got.add);
    fake.status.listen((s) => states.add(s.state));
    fake.emit('private-a', 'signal', '{"type":"ended"}');
    fake.drop();
    expect(got.single.json['type'], 'ended');
    expect(states, [PusherState.closed]);
    await fake.close();
  });
}
