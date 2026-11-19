import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:nawy_core/nawy_core.dart';

/// Opens the camera scanner; returns the first code read, or null when the
/// agent closed it or the camera is not available (they type it instead).
typedef ParcelScanner = Future<String?> Function(BuildContext context);

Future<String?> scanParcelCode(BuildContext context) => Navigator.of(context).push<String>(MaterialPageRoute(builder: (_) => const BarcodeScanScreen()));

/// Full-screen `mobile_scanner` view (owner-review D16, P10). On a camera
/// error (permission refused, no camera) it says so and offers typing the
/// code.
class BarcodeScanScreen extends StatefulWidget {
  const BarcodeScanScreen({super.key});

  @override
  State<BarcodeScanScreen> createState() => _BarcodeScanScreenState();
}

class _BarcodeScanScreenState extends State<BarcodeScanScreen> {
  final _controller = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates);
  bool _done = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_done) return;
    final value = capture.barcodes.map((b) => b.rawValue).whereType<String>().where((v) => v.trim().isNotEmpty).firstOrNull;
    if (value == null) return;
    _done = true;
    Navigator.of(context).pop(value);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: NawyColors.bgInverse,
        appBar: AppBar(title: Text(context.t('scan.title'))),
        body: Stack(fit: StackFit.expand, children: [
          MobileScanner(
            controller: _controller,
            onDetect: _onDetect,
            errorBuilder: (context, error) => Center(
              child: Padding(
                padding: const EdgeInsets.all(NawySpace.gutter),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  const Icon(Icons.no_photography_outlined, size: 48, color: NawyColors.textInverse),
                  const SizedBox(height: 12),
                  Text(
                    context.t(error.errorCode == MobileScannerErrorCode.permissionDenied ? 'scan.permissionDenied' : 'scan.unavailable'),
                    textAlign: TextAlign.center,
                    style: NawyText.body.copyWith(color: NawyColors.textInverse),
                  ),
                  const SizedBox(height: 16),
                  FilledButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.t('scan.typeInstead'))),
                ]),
              ),
            ),
          ),
          Align(
            alignment: Alignment.bottomCenter,
            child: Padding(
              padding: const EdgeInsets.all(NawySpace.gutter),
              child: Text(context.t('scan.hint'), textAlign: TextAlign.center, style: NawyText.bodyMedium.copyWith(color: NawyColors.textInverse)),
            ),
          ),
        ]),
      );
}
