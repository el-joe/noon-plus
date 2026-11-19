import 'package:delivery_app/data/files.dart';
import 'package:delivery_app/screens/earnings.dart';
import 'package:delivery_app/screens/payslips.dart';
import 'package:delivery_app/screens/wallet.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

/// Payslips (list, detail, PDF) and the wallet (balances, withdrawal,
/// history, cancel).
void main() {
  Map<String, dynamic> withdrawal(String number, String status, {String amount = '150.00', String? reason}) => {
        'number': number,
        'amount': moneyJson(amount),
        'status': status,
        'reason': reason,
        'payout': {'account_holder': 'Rashid Ali', 'bank_name': 'Emirates NBD', 'iban_last4': '3456'},
        'requested_at': '2026-11-10T09:00:00+04:00',
        'approved_at': status == 'paid' || status == 'approved' ? '2026-11-11T09:00:00+04:00' : null,
        'paid_at': status == 'paid' ? '2026-11-12T09:00:00+04:00' : null,
        'reference': status == 'paid' ? 'TRX-77' : null,
      };

  Map<String, dynamic> wallet({String available = '420.00', bool canWithdraw = true, String paidBy = 'nawy', String? carrier, List<Map<String, dynamic>>? withdrawals, bool lastPayout = true}) => {
        'available': moneyJson(available),
        'reserved': moneyJson('150.00'),
        'paid_out': moneyJson('900.00'),
        'minimum': moneyJson('100.00'),
        'can_withdraw': canWithdraw,
        'paid_by': paidBy,
        'carrier': carrier,
        'last_payout': lastPayout ? {'account_holder': 'Rashid Ali', 'bank_name': 'Emirates NBD', 'iban_last4': '3456'} : null,
        'withdrawals': withdrawals ?? [withdrawal('WD-2', 'pending'), withdrawal('WD-1', 'paid', amount: '900.00'), withdrawal('WD-0', 'rejected', amount: '50.00', reason: 'IBAN does not match the name')],
      };

  group('withdrawal rules', () {
    test('IBAN: 15–34 letters and digits starting with two letters', () {
      expect(ibanLooksValid('AE07 0331 2345 6789 0123 456'), isTrue);
      expect(ibanLooksValid('ae070331234567890123456'), isTrue);
      expect(ibanLooksValid('NO9386011117947'), isTrue, reason: '15 characters');
      expect(ibanLooksValid('NO938601111794'), isFalse, reason: '14 characters');
      expect(ibanLooksValid('07AE0331234567890123456'), isFalse);
      expect(ibanLooksValid('AE07-0331-2345-6789-0123'), isFalse);
      expect(ibanLooksValid('AE${'1' * 33}'), isFalse, reason: '35 characters');
      expect(normaliseIban(' ae07 0331 '), 'AE070331');
    });

    test('amount: decimal, at least the minimum, at most the available', () {
      final available = Money.parse('420.00', 'AED');
      final minimum = Money.parse('100.00', 'AED');
      String? key(String raw) => withdrawalAmountError(raw, available: available, minimum: minimum)?.$1;
      expect(key(''), 'wallet.amountRequired');
      expect(key('abc'), 'wallet.amountInvalid');
      expect(key('1.234'), 'wallet.amountInvalid');
      expect(key('0'), 'wallet.amountInvalid');
      expect(key('99.99'), 'wallet.amountMin');
      expect(key('420.01'), 'wallet.amountMax');
      expect(key('100'), isNull);
      expect(key('420.00'), isNull);
      expect(withdrawalAmountError('1.234', available: Money.parse('5', 'OMR'))?.$1, isNull, reason: 'OMR has 3 decimals');
    });
  });

  group('wallet screen', () {
    testWidgets('balances, history with statuses, cancel a pending request', (tester) async {
      var cancelled = false;
      final transport = FakeTransport({
        'GET delivery/me/wallet': (HttpRequestData _) => FakeTransport.ok(wallet(withdrawals: [withdrawal('WD-2', cancelled ? 'cancelled' : 'pending'), withdrawal('WD-1', 'paid', amount: '900.00'), withdrawal('WD-0', 'rejected', amount: '50.00', reason: 'IBAN does not match the name')])),
        'POST delivery/me/withdrawals/WD-2/cancel': (HttpRequestData _) {
          cancelled = true;
          return FakeTransport.ok(withdrawal('WD-2', 'cancelled'));
        },
      });
      await pumpScreen(tester, const WalletScreen(), transport);

      expect(find.text('AED 420.00'), findsOneWidget);
      expect(find.text('AED 900.00'), findsWidgets);
      expect(find.text('Paid'), findsOneWidget);
      expect(find.text('Pending'), findsOneWidget);
      expect(find.text('Rejected'), findsOneWidget);
      expect(find.text('Reason: IBAN does not match the name'), findsOneWidget);
      expect(find.text('Reference TRX-77'), findsOneWidget);
      expect(find.byKey(const Key('withdrawal-cancel-WD-1')), findsNothing);
      expect(find.byKey(const Key('wallet-carrier')), findsNothing);

      await tester.tap(find.byKey(const Key('withdrawal-cancel-WD-2')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('withdrawal-cancel-confirm')));
      await tester.pumpAndSettle();
      expect(transport.sent('POST delivery/me/withdrawals/WD-2/cancel'), hasLength(1));
      expect(find.text('Withdrawal cancelled'), findsOneWidget);
      expect(find.byKey(const Key('withdrawal-cancel-WD-2')), findsNothing);
    });

    testWidgets('carrier agents are told the carrier approves and pays', (tester) async {
      await pumpScreen(tester, const WalletScreen(), FakeTransport({'GET delivery/me/wallet': wallet(paidBy: 'carrier', carrier: 'FastFleet LLC')}));
      expect(find.text('Withdrawals are approved and paid by FastFleet LLC.'), findsOneWidget);
    });

    testWidgets('below the minimum the withdraw button is off and says why', (tester) async {
      await pumpScreen(tester, const WalletScreen(), FakeTransport({'GET delivery/me/wallet': wallet(available: '60.00', canWithdraw: false)}));
      expect(tester.widget<FilledButton>(find.byKey(const Key('wallet-withdraw'))).onPressed, isNull);
      expect(find.text('You can withdraw once you have at least AED 100.00.'), findsOneWidget);
    });

    testWidgets('withdraw form: prefilled from the last payout, validated, then sent', (tester) async {
      var loads = 0;
      final transport = FakeTransport({
        'GET delivery/me/wallet': (HttpRequestData _) {
          loads++;
          return FakeTransport.ok(wallet());
        },
        'POST delivery/me/withdrawals': FakeTransport.ok(withdrawal('WD-3', 'pending', amount: '150.00'), status: 201),
      });
      await pumpScreen(tester, const WalletScreen(), transport);
      await tester.tap(find.byKey(const Key('wallet-withdraw')));
      await tester.pumpAndSettle();

      expect(tester.widget<TextFormField>(find.byKey(const Key('withdraw-holder'))).controller!.text, 'Rashid Ali');
      expect(tester.widget<TextFormField>(find.byKey(const Key('withdraw-bank'))).controller!.text, 'Emirates NBD');
      expect(tester.widget<TextFormField>(find.byKey(const Key('withdraw-iban'))).controller!.text, isEmpty);
      expect(find.text('Last used account ends in 3456. Type the full IBAN again.'), findsOneWidget);

      await tester.enterText(find.byKey(const Key('withdraw-amount')), '500');
      await tester.enterText(find.byKey(const Key('withdraw-iban')), 'AE07 0331');
      await tester.tap(find.byKey(const Key('withdraw-submit')));
      await tester.pumpAndSettle();
      expect(find.text('You have AED 420.00 available.'), findsOneWidget);
      expect(find.text('An IBAN starts with 2 letters and has 15 to 34 letters and digits.'), findsOneWidget);

      await tester.enterText(find.byKey(const Key('withdraw-amount')), '50');
      await tester.tap(find.byKey(const Key('withdraw-submit')));
      await tester.pumpAndSettle();
      expect(find.text('The minimum is AED 100.00.'), findsOneWidget);
      expect(transport.sent('POST delivery/me/withdrawals'), isEmpty);

      await tester.enterText(find.byKey(const Key('withdraw-amount')), '150');
      await tester.enterText(find.byKey(const Key('withdraw-iban')), 'ae07 0331 2345 6789 0123 456');
      await tester.tap(find.byKey(const Key('withdraw-submit')));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST delivery/me/withdrawals').single), {
        'amount': '150.00',
        'account_holder': 'Rashid Ali',
        'bank_name': 'Emirates NBD',
        'iban': 'AE070331234567890123456',
      });
      expect(find.text('Withdrawal requested'), findsOneWidget);
      expect(loads, 2);
    });

    testWidgets('an API refusal is shown (inline for fields, else as a message)', (tester) async {
      var attempt = 0;
      final transport = FakeTransport({
        'GET delivery/me/wallet': wallet(lastPayout: false),
        'POST delivery/me/withdrawals': (HttpRequestData _) => ++attempt == 1
            ? FakeTransport.error(422, 'validation_failed', 'Invalid', {'iban': ['This IBAN is not valid.']})
            : FakeTransport.error(422, 'withdrawal_pending', 'You already have a withdrawal waiting for approval.'),
      });
      await pumpScreen(tester, const WalletScreen(), transport);
      await tester.tap(find.byKey(const Key('wallet-withdraw')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('withdraw-amount')), '120.50');
      await tester.enterText(find.byKey(const Key('withdraw-holder')), 'Rashid Ali');
      await tester.enterText(find.byKey(const Key('withdraw-bank')), 'ADCB');
      await tester.enterText(find.byKey(const Key('withdraw-iban')), 'AE070331234567890123456');
      await tester.tap(find.byKey(const Key('withdraw-submit')));
      await tester.pumpAndSettle();
      expect(find.text('This IBAN is not valid.'), findsOneWidget);

      await tester.tap(find.byKey(const Key('withdraw-submit')));
      await tester.pumpAndSettle();
      expect(find.text('You already have a withdrawal waiting for approval.'), findsOneWidget);
      expect(find.byKey(const Key('withdraw-submit')), findsOneWidget, reason: 'stays on the form');
    });

    testWidgets('Arabic', (tester) async {
      await pumpScreen(tester, const WalletScreen(), FakeTransport({'GET delivery/me/wallet': wallet(paidBy: 'carrier', carrier: 'FastFleet')}), locale: 'ar');
      expect(find.text('420.00 AED'), findsOneWidget);
      expect(find.text('تعتمد FastFleet طلبات السحب وتصرفها.'), findsOneWidget);
    });
  });

  group('payslips', () {
    final slip = {
      'number': 'PS-2026-11-A',
      'period_start': '2026-11-01',
      'period_end': '2026-11-15',
      'delivered': 42,
      'failed': 3,
      'per_stop': moneyJson('10.00'),
      'per_failed': moneyJson('2.00'),
      'total': moneyJson('426.00'),
      'issued_at': '2026-11-16T08:00:00+04:00',
    };

    testWidgets('list → detail with lines → PDF saved and opened', (tester) async {
      final files = _Files();
      final transport = FakeTransport({
        'GET delivery/me/payslips': [slip],
        'GET delivery/me/payslips/PS-2026-11-A': {
          ...slip,
          'lines': [
            {'assignment_number': 'DA-1001', 'sub_order_number': 'NW-AE-1028810-1', 'outcome': 'delivered', 'finished_at': '2026-11-03T14:00:00+04:00', 'amount': moneyJson('10.00')},
            {'assignment_number': 'DA-1002', 'sub_order_number': 'NW-AE-1028811-1', 'outcome': 'failed', 'finished_at': '2026-11-04T15:00:00+04:00', 'amount': moneyJson('2.00')},
          ],
        },
        'GET delivery/me/payslips/PS-2026-11-A/pdf': (HttpRequestData _) =>
            HttpResponseData(200, '', {'content-type': 'application/pdf', 'content-disposition': 'attachment; filename="PS-2026-11-A.pdf"'}, fakePdf(10)),
      });
      await pumpScreen(tester, PayslipsScreen(files: files), transport);
      expect(find.text('2026-11-01 – 2026-11-15'), findsOneWidget);
      expect(find.text('AED 426.00'), findsOneWidget);
      expect(find.text('PS-2026-11-A · 42 delivered · 3 failed'), findsOneWidget);

      await tester.tap(find.byKey(const Key('payslip-PS-2026-11-A')));
      await tester.pumpAndSettle();
      expect(find.text('NW-AE-1028810-1'), findsOneWidget);
      expect(find.text('AED 2.00 per failed attempt'), findsOneWidget);
      expect(find.text('Not delivered'), findsOneWidget);

      await tester.tap(find.byKey(const Key('payslip-pdf')));
      await tester.pumpAndSettle();
      expect(files.saved.single.$1, 'PS-2026-11-A.pdf');
      expect(files.saved.single.$2, fakePdf(10));
      expect(files.opened, 1);
      expect(find.text('Saved to Download/Nawy/PS-2026-11-A.pdf'), findsOneWidget);
    });

    testWidgets('when nothing can open the PDF the agent is told where it is', (tester) async {
      final files = _Files(canOpen: false);
      final transport = FakeTransport({
        'GET delivery/me/payslips/PS-1': {...slip, 'number': 'PS-1', 'lines': const []},
        'GET delivery/me/payslips/PS-1/pdf': (HttpRequestData _) => HttpResponseData(200, '', {'content-type': 'application/pdf'}, fakePdf(4)),
      });
      await pumpScreen(tester, PayslipScreen(number: 'PS-1', files: files), transport);
      await tester.tap(find.byKey(const Key('payslip-pdf')));
      await tester.pumpAndSettle();
      expect(files.saved.single.$1, 'payslip-PS-1.pdf');
      expect(find.text('Saved to Download/Nawy/payslip-PS-1.pdf'), findsNWidgets(2));
      expect(find.text('No stops in this period.'), findsOneWidget);
    });

    testWidgets('a PDF error is shown', (tester) async {
      final transport = FakeTransport({
        'GET delivery/me/payslips/PS-1': {...slip, 'number': 'PS-1'},
        'GET delivery/me/payslips/PS-1/pdf': FakeTransport.error(404, 'not_found', 'This payslip has no PDF yet.'),
      });
      await pumpScreen(tester, PayslipScreen(number: 'PS-1', files: _Files()), transport);
      await tester.tap(find.byKey(const Key('payslip-pdf')));
      await tester.pumpAndSettle();
      expect(find.text('This payslip has no PDF yet.'), findsOneWidget);
    });

    testWidgets('empty list', (tester) async {
      await pumpScreen(tester, const PayslipsScreen(), FakeTransport({'GET delivery/me/payslips': const <Object>[]}));
      expect(find.text('No payslips yet'), findsOneWidget);
    });
  });

  testWidgets('Earnings links to the wallet and the payslips (no "coming soon")', (tester) async {
    final transport = FakeTransport({
      'GET delivery/me/earnings': {'total': moneyJson('120.00'), 'stops': 12, 'failed': 1, 'per_stop': moneyJson('10.00'), 'days': const []},
      'GET delivery/me/wallet': wallet(),
    });
    await pumpScreen(tester, const EarningsScreen(), transport);
    expect(find.textContaining('coming soon'), findsNothing);
    await tester.tap(find.byKey(const Key('open-wallet')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('wallet-available')), findsOneWidget);
  });
}

class _Files implements FileSaver {
  _Files({this.canOpen = true});

  final bool canOpen;
  final saved = <(String, List<int>)>[];
  int opened = 0;

  @override
  Future<SavedFile> save(String name, List<int> bytes, {String mimeType = 'application/pdf'}) async {
    saved.add((name, bytes));
    return SavedFile('Download/Nawy/$name', uri: 'content://downloads/1');
  }

  @override
  Future<bool> open(SavedFile file) async {
    if (!canOpen) return false;
    opened++;
    return true;
  }
}
