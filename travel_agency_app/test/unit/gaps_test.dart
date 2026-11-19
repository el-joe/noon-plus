import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:travel_agency_app/data/travel_api.dart';
import 'package:travel_agency_app/l10n/strings.dart';

void main() {
  TravelApi apiWith(FakeTransport t) => TravelApi(ApiClient(baseUrl: 'http://x/api/v1', transport: t)..market = 'omn-en');

  test('capacity input is trimmed; boundaries 0 and 1000 are valid', () {
    expect(capacityError(' 25 ', 3), isNull);
    expect(capacityError('1000', 1000), isNull);
    expect(capacityError('0', 1), 'capacity.belowBooked');
    expect(capacityError('ten', 0), 'capacity.notNumber');
  });

  test('seats maths with no capacity', () {
    expect(bookedShare(0, 3), 1);
    expect(bookedShare(-1, 0), 0);
    expect(bookedShare(3, 1), closeTo(1 / 3, 1e-9));
    expect(seatsLeft(0, 0), 0);
  });

  test('party split reads numeric strings and defaults children to 0', () {
    expect(partyOf({'adults': '2'}), (adults: 2, children: 0));
    expect(partyOf({'adults': 1, 'children': '3'}), (adults: 1, children: 3));
    expect(partyOf({'travellers': 4}), isNull);
  });

  test('rooms: missing, not a list, or rows without a room type', () {
    expect(roomsOf({}), isEmpty);
    expect(roomsOf({'rooms': 'double'}), isEmpty);
    expect(roomsOf({'rooms': [{'room_type': ''}, 'x', {'room_type': 'triple', 'travellers': 3}]}).single['room_type'], 'triple');
  });

  test('bookings query drops a null status; reject sends the reason', () async {
    final t = FakeTransport({'POST ops/travel/bookings/31/reject': {'status': 'rejected'}});
    final api = apiWith(t);
    await api.bookings();
    await api.bookings(status: 'requested', page: 2);
    await api.reject(31, 'Fully booked');
    expect(t.requests[0].uri.query, 'page=1');
    expect(t.requests[1].uri.query, 'status=requested&page=2');
    expect(FakeTransport.bodyOf(t.requests[2]), {'reason': 'Fully booked'});
  });

  test('every booking filter has an EN and AR status label', () {
    for (final s in bookingFilters) {
      expect(travelEn['status.$s'], isNotNull, reason: s);
      expect(travelAr['status.$s'], isNotNull, reason: s);
    }
  });
}
