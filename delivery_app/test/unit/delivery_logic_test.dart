import 'dart:convert';

import 'package:delivery_app/data/location.dart';
import 'package:delivery_app/data/models.dart';
import 'package:delivery_app/l10n/strings.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

void main() {
  group('stop actions (lifecycle §2.4)', () {
    test('assigned → accept / decline; accepted → start', () {
      expect(actionsFor(Assignment(assignmentJson(status: 'assigned'))), [StopAction.accept, StopAction.decline]);
      expect(actionsFor(Assignment(assignmentJson(status: 'accepted'))), [StopAction.start]);
    });

    test('picked up → navigate, arrive, deliver, fail; arrive only once', () {
      expect(actionsFor(Assignment(assignmentJson())), [StopAction.navigate, StopAction.arrive, StopAction.deliver, StopAction.fail]);
      expect(actionsFor(Assignment(assignmentJson(arrivedAt: '2026-11-15T10:00:00Z'))), isNot(contains(StopAction.arrive)));
    });

    test('finished stops have no actions', () {
      for (final s in ['delivered', 'failed', 'declined', 'reassigned']) {
        expect(actionsFor(Assignment(assignmentJson(status: s))), isEmpty, reason: s);
      }
    });
  });

  group('proof rules (W5)', () {
    test('needs a proof step only when required and nothing is attached', () {
      expect(needsProofStep(Assignment(assignmentJson(proofRequired: true))), isTrue);
      expect(needsProofStep(Assignment(assignmentJson())), isFalse);
    });

    test('proof type photo / signature / either', () {
      expect(proofSatisfied(proofType: 'either', hasPhoto: false, hasSignature: true), isTrue);
      expect(proofSatisfied(proofType: 'either', hasPhoto: false, hasSignature: false), isFalse);
      expect(proofSatisfied(proofType: 'photo', hasPhoto: false, hasSignature: true), isFalse);
      expect(proofSatisfied(proofType: 'signature', hasPhoto: true, hasSignature: false), isFalse);
      expect(proofSatisfied(proofType: 'both', hasPhoto: true, hasSignature: true), isTrue);
    });
  });

  group('confirm request (ADR 15)', () {
    test('sends codes_match and never the code', () {
      const r = ConfirmRequest(signatureDataUrl: 'data:image/png;base64,AAA');
      expect(r.isMultipart, isFalse);
      expect(r.json, {'codes_match': true, 'signature_data': 'data:image/png;base64,AAA'});
      expect(jsonEncode(r.json), isNot(contains('4817')));
    });

    test('a photo makes it multipart with the photo file', () {
      const r = ConfirmRequest(photo: [1, 2, 3]);
      expect(r.isMultipart, isTrue);
      expect(r.fields, {'codes_match': '1'});
      expect(r.files.single.field, 'photo');
    });
  });

  group('COD maths', () {
    test('change due and short payment', () {
      final due = Money.parse('312.00', 'AED');
      expect(CodMath.changeDue(due, Money.parse('400', 'AED')), Money.parse('88.00', 'AED'));
      expect(CodMath.changeDue(due, Money.parse('312', 'AED')), Money.zero('AED'));
      expect(CodMath.changeDue(due, Money.parse('300', 'AED')), isNull);
    });

    test('3-decimal currencies keep fils exact', () {
      final due = Money.parse('12.345', 'OMR');
      expect(CodMath.changeDue(due, Money.parse('20', 'OMR'))!.format(), 'OMR 7.655');
      expect(CodMath.afterCollecting(Money.parse('0.1', 'KWD'), Money.parse('0.2', 'KWD')).amount, '0.300');
    });

    test('totals skip other currencies and nulls', () {
      final total = CodMath.total([moneyJson('100.50'), moneyJson('0.25'), null, moneyJson('5', 'SAR')], 'AED');
      expect(total.amount, '100.75');
    });

    test('to collect counts open COD stops only', () {
      final stops = [
        Assignment(assignmentJson(cod: '312.00')),
        Assignment(assignmentJson(cod: '40.00', status: 'assigned')),
        Assignment(assignmentJson(cod: '99.00', status: 'delivered')),
        Assignment(assignmentJson()),
      ];
      expect(CodMath.toCollect(stops, 'AED').amount, '352.00');
    });
  });

  group('assignment model', () {
    test('address line and COD flag', () {
      final a = Assignment(assignmentJson(cod: '312.00'));
      expect(a.addressLine, 'JBR Sadaf 4, Apt 905, Dubai Marina, Dubai');
      expect(a.isCod, isTrue);
      expect(Assignment(assignmentJson(cod: '0.00')).isCod, isFalse);
    });
  });

  group('GPS stub (geolocator requested)', () {
    test('no location source sends nothing', () async {
      final transport = FakeTransport();
      final pinger = LocationPinger(ApiClient(baseUrl: 'http://x/api/v1', transport: transport)..market = 'uae-en', const NoLocationSource());
      expect(await pinger.pingOnce(), isFalse);
      pinger.start();
      expect(pinger.running, isFalse);
      expect(transport.requests, isEmpty);
    });

    test('a source with a fix posts lat/lng', () async {
      final transport = FakeTransport({'POST delivery/me/location': {'saved': true}});
      final pinger = LocationPinger(ApiClient(baseUrl: 'http://x/api/v1', transport: transport)..market = 'uae-en', _Fixed());
      expect(await pinger.pingOnce(), isTrue);
      expect(FakeTransport.bodyOf(transport.requests.single), {'lat': 25.07, 'lng': 55.13});
    });
  });

  test('EN and AR tables have the same keys', () {
    expect(deliveryAr.keys.toSet(), deliveryEn.keys.toSet());
  });
}

class _Fixed implements LocationSource {
  @override
  bool get available => true;

  @override
  Future<({double lat, double lng})?> current() async => (lat: 25.07, lng: 55.13);
}
