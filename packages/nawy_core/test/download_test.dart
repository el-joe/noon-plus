import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

/// `ApiClient.download` (payslip PDFs in the delivery app).
void main() {
  ApiClient client(FakeTransport t) => ApiClient(baseUrl: 'http://x/api/v1', transport: t)
    ..market = 'uae-en'
    ..token = 'tok';

  test('returns the raw bytes, type and file name of a file answer', () async {
    final pdf = [0x25, 0x50, 0x44, 0x46, 0xFF, 0x00, 0xC3];
    final transport = FakeTransport({
      'GET delivery/me/payslips/PS-1/pdf': (HttpRequestData _) =>
          HttpResponseData(200, '', {'content-type': 'application/pdf', 'content-disposition': 'attachment; filename="PS-1.pdf"'}, pdf),
    });
    final file = await client(transport).download('delivery/me/payslips/PS-1/pdf');
    expect(file.bytes, pdf);
    expect(file.contentType, 'application/pdf');
    expect(file.filename, 'PS-1.pdf');
    expect(transport.requests.single.headers['Authorization'], 'Bearer tok');
  });

  test('an error answer throws the API error', () async {
    final transport = FakeTransport({'GET delivery/me/payslips/PS-9/pdf': FakeTransport.error(404, 'not_found', 'No such payslip.')});
    await expectLater(client(transport).download('delivery/me/payslips/PS-9/pdf'), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'not_found')));
  });

  test('without raw bytes the text body is used', () async {
    final transport = FakeTransport({'GET f': (HttpRequestData _) => HttpResponseData(200, 'hello', {'content-type': 'text/plain; charset=utf-8'})});
    final file = await client(transport).download('f');
    expect(utf8.decode(file.bytes), 'hello');
    expect(file.contentType, 'text/plain');
    expect(file.filename, isNull);
  });

  test('Content-Disposition file names', () {
    expect(filenameFromDisposition('attachment; filename="a b.pdf"'), 'a b.pdf');
    expect(filenameFromDisposition('attachment; filename=plain.pdf'), 'plain.pdf');
    expect(filenameFromDisposition("attachment; filename*=UTF-8''pay%20slip.pdf"), 'pay slip.pdf');
    expect(filenameFromDisposition(null), isNull);
  });
}
