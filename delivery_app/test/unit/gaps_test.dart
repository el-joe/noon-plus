import 'package:delivery_app/data/delivery_api.dart';
import 'package:delivery_app/data/models.dart';
import 'package:delivery_app/l10n/strings.dart';
import 'package:delivery_app/ui/signature_pad.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

void main() {
  group('assignment model edge cases', () {
    test('defaults when optional keys are missing', () {
      final a = Assignment({'number': 'DA-9', 'status': 'assigned'});
      expect(a.attempt, 1);
      expect(a.proofType, 'either');
      expect(a.proofRequired, isFalse);
      expect(a.codAmount, isNull);
      expect(a.isCod, isFalse);
      expect(a.items, isEmpty);
      expect(a.customerName, isNull);
      expect(a.addressLine, '');
      expect(a.slaDueAt, isNull);
      expect(a.isOpen, isTrue);
    });

    test('address line: known order first, extra keys after, no duplicates or meta keys', () {
      final a = Assignment({
        'customer': {
          'address': {
            'city': 'Muscat',
            'building': ' Villa 12 ',
            'area': 'Al Khuwair',
            'district': 'Al Khuwair',
            'block': 'Block 3',
            'country': 'OM',
            'label': 'Home',
            'landmark': 'Near the mosque',
            'apartment': '',
          },
        },
      });
      expect(a.addressLine, 'Villa 12, Al Khuwair, Muscat, Block 3');
      expect(a.landmark, 'Near the mosque');
      expect(Assignment({'customer': {'address': {'notes': 'Gate B'}}}).landmark, 'Gate B');
    });

    test('failure reasons from a list or a comma string; dates in local time', () {
      expect(Assignment({'failure_reasons': 'not_home, refused'}).failureReasons, ['not_home', 'refused']);
      final a = Assignment(assignmentJson(arrivedAt: '2026-11-15T10:00:00Z'));
      expect(a.arrivedAt, DateTime.utc(2026, 11, 15, 10).toLocal());
      expect(a.slaDueAt, DateTime.utc(2026, 11, 15, 10, 40).toLocal());
      expect(Assignment({'arrived_at': 'soon'}).arrivedAt, isNull);
    });

    test('a 3-decimal COD amount is exact', () {
      final a = Assignment({'status': 'picked_up', 'cod_amount': moneyJson('12.345', 'OMR')});
      expect(a.isCod, isTrue);
      expect(a.codAmount!.format(), 'OMR 12.345');
      expect(CodMath.afterCollecting(Money.parse('100.005', 'OMR'), a.codAmount!).amount, '112.350');
      expect(CodMath.changeDue(a.codAmount!, Money.parse('12.345', 'OMR'))!.isZero, isTrue);
      expect(CodMath.toCollect([a, Assignment({'status': 'delivered', 'cod_amount': moneyJson('5', 'OMR')})], 'OMR').amount, '12.345');
    });

    test('proof step is skipped when a photo or a signature is already attached', () {
      Assignment withProof(Map<String, dynamic> proof) => Assignment({'proof': {'required': true, ...proof}});
      expect(needsProofStep(withProof({'has_photo': true})), isFalse);
      expect(needsProofStep(withProof({'has_signature': '1'})), isFalse);
      expect(needsProofStep(withProof({})), isTrue);
      expect(proofSatisfied(proofType: 'both', hasPhoto: true, hasSignature: false), isFalse);
      expect(proofSatisfied(proofType: 'unknown', hasPhoto: true, hasSignature: false), isTrue);
    });
  });

  test('cancelledAmong ignores stops that are gone (404) or still open', () async {
    final transport = FakeTransport({
      'GET delivery/assignments/DA-1': assignmentJson(status: 'cancelled'),
      'GET delivery/assignments/DA-2': FakeTransport.error(404, 'not_found', 'Gone'),
      'GET delivery/assignments/DA-3': assignmentJson(status: 'delivered'),
    });
    final api = DeliveryApi(ApiClient(baseUrl: 'http://x/api/v1', transport: transport)..market = 'uae-en');
    final found = await api.cancelledAmong({'DA-1', 'DA-2', 'DA-3'});
    expect(found, hasLength(1));
    expect(found.single.isCancelled, isTrue);
  });

  test('every assignment status the app shows has an EN and AR label', () {
    final en = L10n('en', en: deliveryEn, ar: deliveryAr);
    final ar = L10n('ar', en: deliveryEn, ar: deliveryAr);
    for (final s in ['assigned', 'accepted', 'picked_up', 'delivered', 'failed', 'declined', 'cancelled']) {
      expect(deliveryEn['status.$s'], isNotNull, reason: s);
      expect(deliveryAr['status.$s'], isNotNull, reason: s);
      expect(ar.status(s), isNot(en.status(s)), reason: s);
    }
  });

  test('signature controller: strokes, empty state and clear', () {
    final c = SignatureController();
    var notified = 0;
    c.addListener(() => notified++);
    expect(c.isEmpty, isTrue);
    c.extend(const Offset(1, 1));
    expect(c.strokes, hasLength(1));
    c.begin(const Offset(5, 5));
    c.extend(const Offset(6, 6));
    expect(c.strokes.last, [const Offset(5, 5), const Offset(6, 6)]);
    expect(c.isEmpty, isFalse);
    c.clear();
    expect(c.isEmpty, isTrue);
    expect(notified, 4);
  });
}
