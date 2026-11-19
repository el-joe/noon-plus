import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/testing.dart';
import 'package:travel_agency_app/data/travel_api.dart';
import 'package:travel_agency_app/l10n/strings.dart';
import 'package:travel_agency_app/screens/bookings.dart';

import '../helpers.dart';

Map<String, dynamic> booking({int? adults, int? children, List<Map<String, dynamic>>? rooms}) => {
      'id': 31,
      'number': 'TB-OM-31',
      'status': 'requested',
      'package': 'Salalah Khareef 4 days',
      'departure_date': '2026-12-02',
      'travellers': (adults ?? 2) + (children ?? 0),
      'adults': ?adults,
      'children': ?children,
      'rooms': ?rooms,
      'total': moneyJson('500.000', 'OMR'),
      'can_confirm': false,
      'can_reject': false,
    };

void main() {
  test('party split and rooms are read when sent', () {
    expect(partyOf(booking(adults: 2, children: 1)), (adults: 2, children: 1));
    expect(partyOf(booking()), isNull);
    expect(roomsOf({'rooms': [{'room_type': 'double', 'travellers': 2}, {'travellers': 1}]}), hasLength(1));
  });

  testWidgets('a booking shows adults, children and its rooms (QA-037)', (tester) async {
    final transport = FakeTransport({
      'GET ops/travel/bookings': [
        booking(adults: 2, children: 1, rooms: [
          {'room_type': 'double', 'travellers': 2, 'unit_price': moneyJson('150.000', 'OMR'), 'line_total': moneyJson('300.000', 'OMR')},
          {'room_type': 'single', 'travellers': 1, 'unit_price': moneyJson('200.000', 'OMR'), 'line_total': moneyJson('200.000', 'OMR')},
        ]),
      ],
    });
    await pumpScreen(tester, const BookingsScreen(), transport);
    expect(find.textContaining('2 adults, 1 children'), findsOneWidget);
    expect(find.text('Double: 2 × OMR 150.000'), findsOneWidget);
    expect(find.text('OMR 300.000'), findsOneWidget);
    expect(find.byKey(const Key('room-31-1')), findsOneWidget);
  });

  testWidgets('without the split it keeps the traveller count', (tester) async {
    final transport = FakeTransport({'GET ops/travel/bookings': [booking()]});
    await pumpScreen(tester, const BookingsScreen(), transport);
    expect(find.textContaining('2 travellers'), findsOneWidget);
    expect(find.byKey(const Key('room-31-0')), findsNothing);
  });

  test('EN and AR tables have the same keys', () {
    expect(travelAr.keys.toSet(), travelEn.keys.toSet());
  });
}
