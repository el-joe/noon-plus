import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../home/home_tab.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/states.dart';

/// `static-pages/{slug}`: terms, privacy, returns, shipping … (Admin →
/// Content → Static pages). While `placeholder` is true the text is the
/// neutral "not published yet" copy, so a banner says so (QA-032). A slug
/// that is not a static page falls back to the page-builder page.
class StaticPageScreen extends StatelessWidget {
  const StaticPageScreen({super.key, required this.slug, this.title});

  final String slug;
  final String? title;

  /// Footer legal pages (QA-032) listed under Help.
  static const legalSlugs = ['terms-and-conditions', 'privacy-policy', 'return-policy', 'shipping-policy', 'refund-policy', 'cookie-policy', 'contact-us'];

  Future<Json?> _load(BuildContext context) async {
    try {
      return await AppScope.read(context).api.getObject('static-pages/${enc(slug)}');
    } on ApiException catch (e) {
      if (e.isNotFound) return null;
      rethrow;
    }
  }

  @override
  Widget build(BuildContext context) => AsyncBody<Json?>(
        load: () => _load(context),
        loading: Scaffold(appBar: AppBar(title: Text(title ?? '')), body: const LoadingView()),
        builder: (context, page, _) {
          if (page == null) return Scaffold(appBar: AppBar(title: Text(title ?? '')), body: PageBuilderView(slug: slug));
          return Scaffold(
            appBar: AppBar(title: Text(page.s('title') ?? title ?? '')),
            body: ListView(padding: const EdgeInsets.all(16), children: [
              if (page.b('placeholder'))
                Container(
                  key: const ValueKey('static-page-placeholder'),
                  margin: const EdgeInsets.only(bottom: 16),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: NawyColors.bgWarningSubtle, borderRadius: BorderRadius.circular(NawyRadius.md), border: Border.all(color: NawyColors.bgBrand)),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Icon(Icons.info_outline_rounded, size: 18, color: NawyColors.textBrand),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(context.t('staticPage.placeholderTitle'), style: NawyText.bodyMedium),
                        Text(context.t('staticPage.placeholderBody'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                      ]),
                    ),
                  ]),
                ),
              Text(page.str('title'), style: NawyText.h2),
              const SizedBox(height: 12),
              SelectableText(plainText(page.s('body') ?? page.s('content') ?? ''), style: NawyText.body),
            ]),
          );
        },
      );
}

/// The page body as plain text: the API sends simple HTML (no HTML
/// renderer package), so tags become line breaks and entities are decoded.
String plainText(String html) {
  var text = html
      .replaceAll(RegExp(r'<\s*br\s*/?>', caseSensitive: false), '\n')
      .replaceAll(RegExp(r'</\s*(p|div|h[1-6]|li|tr)\s*>', caseSensitive: false), '\n\n')
      .replaceAll(RegExp(r'<\s*li[^>]*>', caseSensitive: false), '• ')
      .replaceAll(RegExp(r'<[^>]+>'), '');
  // `&amp;` last, so an escaped entity ("&amp;lt;") is decoded only once.
  const entities = {'&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'", '&nbsp;': ' ', '&amp;': '&'};
  entities.forEach((k, v) => text = text.replaceAll(k, v));
  return text.replaceAll(RegExp(r'\n{3,}'), '\n\n').trim();
}
