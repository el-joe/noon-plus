import 'package:flutter_test/flutter_test.dart';
import 'package:travel_agency_app/data/travel_api.dart';
import 'package:travel_agency_app/l10n/strings.dart';

void main() {
  test('capacity validation: whole number 0–1000, not below booked', () {
    expect(capacityError('40', 12), isNull);
    expect(capacityError('12', 12), isNull);
    expect(capacityError('0', 0), isNull);
    expect(capacityError('11', 12), 'capacity.belowBooked');
    expect(capacityError('1001', 0), 'capacity.range');
    expect(capacityError('-1', 0), 'capacity.range');
    expect(capacityError('4.5', 0), 'capacity.notNumber');
    expect(capacityError('', 0), 'capacity.notNumber');
  });

  test('seats maths', () {
    expect(seatsLeft(40, 12), 28);
    expect(seatsLeft(10, 12), 0);
    expect(bookedShare(40, 10), 0.25);
    expect(bookedShare(10, 12), 1);
    expect(bookedShare(0, 0), 0);
  });

  test('EN and AR tables have the same keys', () {
    expect(travelAr.keys.toSet(), travelEn.keys.toSet());
  });
}
