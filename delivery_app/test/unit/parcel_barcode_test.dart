import 'package:delivery_app/data/models.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

/// Reference Code 39 mod 43, written independently of the app's version.
String referenceCheck(String value) {
  const values = {
    '0': 0, '1': 1, '2': 2, '3': 3, '4': 4, '5': 5, '6': 6, '7': 7, '8': 8, '9': 9, //
    'A': 10, 'B': 11, 'C': 12, 'D': 13, 'E': 14, 'F': 15, 'G': 16, 'H': 17, 'I': 18, 'J': 19,
    'K': 20, 'L': 21, 'M': 22, 'N': 23, 'O': 24, 'P': 25, 'Q': 26, 'R': 27, 'S': 28, 'T': 29,
    'U': 30, 'V': 31, 'W': 32, 'X': 33, 'Y': 34, 'Z': 35, '-': 36, '.': 37, ' ': 38, r'$': 39,
    '/': 40, '+': 41, '%': 42,
  };
  final sum = value.split('').fold<int>(0, (s, c) => s + values[c]!);
  return values.entries.firstWhere((e) => e.value == sum % 43).key;
}

void main() {
  group('Code 39 mod 43 check character', () {
    test('fixed vector: SH-NW-AE-1000123-1 → I', () {
      // S=28 H=17 -=36 N=23 W=32 -=36 A=10 E=14 -=36 1=1 0=0 0=0 0=0 1=1 2=2 3=3 -=36 1=1
      // 28+17+36+23+32+36+10+14+36 = 232; +1+0+0+0+1+2+3 = 239; +36+1 = 276
      // 276 mod 43 = 276 - 258 (6 × 43) = 18 → 'I' (A=10 … I=18)
      expect(parcelCheckChar('SH-NW-AE-1000123-1'), 'I');
      expect(referenceCheck('SH-NW-AE-1000123-1'), 'I');
    });

    test('matches the reference algorithm on other shipment numbers', () {
      for (final n in ['NW-AE-1028810-1', 'SH-NW-OM-2000001-12', 'SH-NW-SA-9999999-3', 'A', 'Z9', r'$/+%. ']) {
        expect(parcelCheckChar(n), referenceCheck(n), reason: n);
      }
    });

    test('characters Code 39 cannot encode give no check', () {
      expect(parcelCheckChar('sh-nw'), isNull);
      expect(parcelCheckChar('SH_NW'), isNull);
    });
  });

  group('parseParcelBarcode', () {
    test('trims, upper-cases and drops the start / stop asterisks', () {
      final p = parseParcelBarcode('  *sh-nw-ae-1000123-1.i*  ');
      expect(p.code, 'SH-NW-AE-1000123-1.I');
      expect(p.base, 'SH-NW-AE-1000123-1');
      expect(p.labelled, isTrue);
      expect(p.checkValid, isTrue);
    });

    test('a wrong check character is a bad read', () {
      final p = parseParcelBarcode('SH-NW-AE-1000123-1.J');
      expect(p.labelled, isTrue);
      expect(p.checkValid, isFalse);
      expect(parseParcelBarcode('SH-NW-AE-1000123-1.').checkValid, isFalse);
      expect(parseParcelBarcode('SH-NW-AE-1000123-1.II').checkValid, isFalse);
      expect(parseParcelBarcode('.I').checkValid, isFalse);
    });

    test('splits at the last dot; the check may itself be "." or a trimmed space', () {
      // A=10, R=27: 37 → '.'; A=10, S=28: 38 → ' '.
      expect(parcelCheckChar('AR'), '.');
      expect(parcelCheckChar('AS'), ' ');
      final dot = parseParcelBarcode('AR..');
      expect((dot.base, dot.checkValid), ('AR', true));
      final space = parseParcelBarcode('*AS. *');
      expect((space.base, space.checkValid), ('AS', true));
    });

    test('a code without a dot was typed by hand', () {
      final p = parseParcelBarcode(' nw-ae-1028810-1 ');
      expect(p.labelled, isFalse);
      expect(p.base, 'NW-AE-1028810-1');
    });
  });

  group('matchParcelCode', () {
    final a = Assignment(assignmentJson());
    final label = 'NW-AE-1028810-1.${parcelCheckChar('NW-AE-1028810-1')}';

    test('the label of this shipment matches', () {
      expect(matchParcelCode(a, label), ParcelMatch.match);
      expect(matchParcelCode(a, '*${label.toLowerCase()}*'), ParcelMatch.match);
      expect(pickupCodeMatches(a, label), isTrue);
    });

    test('bad check, other parcel, typed numbers', () {
      final other = 'NW-AE-9999999-1.${parcelCheckChar('NW-AE-9999999-1')}';
      expect(label, 'NW-AE-1028810-1.-'); // 208 mod 43 = 36 → '-'
      expect(matchParcelCode(a, 'NW-AE-1028810-1.Q'), ParcelMatch.badLabel);
      expect(matchParcelCode(a, other), ParcelMatch.otherParcel);
      expect(matchParcelCode(a, 'NW-AE-1028810-1'), ParcelMatch.match);
      expect(matchParcelCode(a, 'DA-1001'), ParcelMatch.match);
      expect(matchParcelCode(a, 'NW-AE-1028810'), ParcelMatch.match);
      expect(matchParcelCode(a, 'NW-AE-1028810-2'), ParcelMatch.noMatch);
      expect(matchParcelCode(a, ' * '), ParcelMatch.empty);
    });

    test('a label carrying the order or assignment number is not this parcel', () {
      expect(matchParcelCode(a, 'DA-1001.${parcelCheckChar('DA-1001')}'), ParcelMatch.otherParcel);
    });

    test('the barcode field of the assignment', () {
      expect(Assignment({...assignmentJson(), 'barcode': label}).barcode, label);
      expect(Assignment(assignmentJson()).barcode, isNull);
    });

    test('with the API barcode, the label must carry its AWB (SH-…), which may differ from the shown number', () {
      final awb = 'SH-${a.shipmentNumber}';
      final withBarcode = Assignment({...assignmentJson(), 'barcode': '$awb.${parcelCheckChar(awb)}'});
      expect(matchParcelCode(withBarcode, '*$awb.${parcelCheckChar(awb)}*'), ParcelMatch.match);
      expect(matchParcelCode(withBarcode, '${a.shipmentNumber}.${parcelCheckChar(a.shipmentNumber)}'), ParcelMatch.otherParcel);
      expect(matchParcelCode(withBarcode, awb), ParcelMatch.match);
      expect(matchParcelCode(withBarcode, a.shipmentNumber), ParcelMatch.match);
    });
  });
}
