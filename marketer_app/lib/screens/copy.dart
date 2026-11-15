import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:nawy_core/nawy_core.dart';

/// Copy-to-clipboard button for referral links and codes.
class CopyButton extends StatelessWidget {
  const CopyButton({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => IconButton(
        tooltip: context.t('links.copy'),
        icon: const Icon(Icons.copy_rounded, size: 20),
        onPressed: () async {
          await Clipboard.setData(ClipboardData(text: text));
          if (context.mounted) showSnack(context, context.t('links.copied'));
        },
      );
}
