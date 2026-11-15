import 'dart:convert';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

/// Strokes drawn by the customer (Figma "06 Proof of delivery").
class SignatureController extends ChangeNotifier {
  final List<List<Offset>> strokes = [];

  bool get isEmpty => strokes.every((s) => s.isEmpty);

  void begin(Offset point) {
    strokes.add([point]);
    notifyListeners();
  }

  void extend(Offset point) {
    if (strokes.isEmpty) strokes.add([]);
    strokes.last.add(point);
    notifyListeners();
  }

  void clear() {
    strokes.clear();
    notifyListeners();
  }

  /// Renders the strokes on white with `dart:ui` and returns PNG bytes
  /// (the backend accepts a PNG data URL as `signature_data`, W5).
  Future<Uint8List> toPng(Size size, {double pixelRatio = 2}) async {
    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);
    canvas.scale(pixelRatio);
    canvas.drawRect(Offset.zero & size, Paint()..color = Colors.white);
    SignaturePainter.paintStrokes(canvas, strokes);
    final picture = recorder.endRecording();
    final image = await picture.toImage((size.width * pixelRatio).ceil(), (size.height * pixelRatio).ceil());
    final data = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();
    picture.dispose();
    if (data == null) throw StateError('PNG export failed');
    return data.buffer.asUint8List();
  }

  Future<String> toDataUrl(Size size) async => 'data:image/png;base64,${base64Encode(await toPng(size))}';
}

class SignaturePainter extends CustomPainter {
  SignaturePainter(this.controller) : super(repaint: controller);

  final SignatureController controller;

  static void paintStrokes(Canvas canvas, List<List<Offset>> strokes) {
    final paint = Paint()
      ..color = NawyColors.textPrimary
      ..strokeWidth = 2.5
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;
    for (final stroke in strokes) {
      if (stroke.length == 1) {
        canvas.drawCircle(stroke.first, 1.4, paint..style = PaintingStyle.fill);
        paint.style = PaintingStyle.stroke;
        continue;
      }
      final path = Path();
      for (var i = 0; i < stroke.length; i++) {
        i == 0 ? path.moveTo(stroke[i].dx, stroke[i].dy) : path.lineTo(stroke[i].dx, stroke[i].dy);
      }
      canvas.drawPath(path, paint);
    }
  }

  @override
  void paint(Canvas canvas, Size size) => paintStrokes(canvas, controller.strokes);

  @override
  bool shouldRepaint(SignaturePainter oldDelegate) => true;
}

/// Dashed-border pad the customer signs on.
class SignaturePad extends StatelessWidget {
  const SignaturePad({super.key, required this.controller, this.height = 160});

  final SignatureController controller;
  final double height;

  @override
  Widget build(BuildContext context) => Container(
        height: height,
        decoration: BoxDecoration(
          color: NawyColors.bgSurface,
          borderRadius: BorderRadius.circular(NawyRadius.card),
          border: Border.all(color: NawyColors.borderDefault, width: 1.5),
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(NawyRadius.card),
          child: GestureDetector(
            behavior: HitTestBehavior.opaque,
            onPanStart: (d) => controller.begin(d.localPosition),
            onPanUpdate: (d) => controller.extend(d.localPosition),
            child: CustomPaint(painter: SignaturePainter(controller), size: Size.infinite),
          ),
        ),
      );
}
