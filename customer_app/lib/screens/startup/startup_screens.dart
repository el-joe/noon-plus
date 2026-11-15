import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/states.dart';

/// Figma "01 Splash" (80:92): brand yellow, logo, wordmark.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key, this.minimum = const Duration(milliseconds: 900)});

  final Duration minimum;

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    unawaited(_start());
  }

  Future<void> _start() async {
    final app = AppScope.read(context);
    await Future.wait([if (!app.ready) app.init(), Future<void>.delayed(widget.minimum)]);
    if (!mounted) return;
    Navigator.of(context).pushReplacementNamed(app.hasMarket ? Routes.shell : Routes.market);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: NawyColors.bgBrand,
        body: Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 96,
              height: 96,
              decoration: BoxDecoration(color: NawyColors.bgInverse, borderRadius: BorderRadius.circular(22)),
              padding: const EdgeInsets.all(12),
              child: Image.asset('assets/images/nawy_logo.png', semanticLabel: 'Nawy'),
            ),
            const SizedBox(height: 16),
            Text('nawy', style: NawyText.h1.copyWith(fontSize: 36, fontWeight: FontWeight.w800)),
          ]),
        ),
      );
}

/// Figma "02 Country" / "42 Country & language": the 8 countries × EN/AR.
class MarketScreen extends StatefulWidget {
  const MarketScreen({super.key, this.fromSettings = false});

  final bool fromSettings;

  @override
  State<MarketScreen> createState() => _MarketScreenState();
}

class _MarketScreenState extends State<MarketScreen> {
  String? _country;
  String? _language;

  @override
  void initState() {
    super.initState();
    final app = AppScope.read(context);
    _country = app.countrySlug;
    _language = app.hasMarket ? app.locale : null;
  }

  Future<void> _continue(List<Json> countries) async {
    final app = AppScope.read(context);
    final country = countries.firstWhere((c) => c.str('code') == _country, orElse: () => countries.first);
    final markets = country.strings('markets');
    final lang = _language ?? country.str('default_locale', 'en');
    final market = markets.firstWhere((m) => m.endsWith('-$lang'), orElse: () => markets.isNotEmpty ? markets.first : '${country.str('code')}-$lang');
    await app.setMarket(market);
    if (!mounted) return;
    if (widget.fromSettings) {
      Navigator.of(context).pop();
    } else {
      Navigator.of(context).pushNamedAndRemoveUntil(Routes.shell, (_) => false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: widget.fromSettings ? AppBar(title: Text(context.t('market.title'))) : null,
      body: SafeArea(
        child: AsyncBody<List<Json>>(
          load: app.loadCountries,
          isEmpty: (c) => c.isEmpty,
          builder: (context, countries, _) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Expanded(
              child: ListView(padding: const EdgeInsets.all(16), children: [
                if (!widget.fromSettings) ...[
                  Text(context.t('market.welcome'), style: NawyText.h2),
                  const SizedBox(height: 4),
                  Text(context.t('market.subtitle'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
                  const SizedBox(height: 16),
                ],
                Text(context.t('market.country'), style: NawyText.h4),
                const SizedBox(height: 8),
                for (final c in countries)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: _Option(
                      key: ValueKey('country-${c.str('code')}'),
                      selected: _country == c.str('code'),
                      title: c.str('name'),
                      subtitle: '${c.m('currency')?.str('code') ?? ''} · ${c.str('phone_code')}',
                      leading: Text(_flag(c.str('iso2')), style: const TextStyle(fontSize: 22)),
                      onTap: () => setState(() {
                        _country = c.str('code');
                        _language ??= c.str('default_locale', 'en');
                      }),
                    ),
                  ),
                const SizedBox(height: 12),
                Text(context.t('market.language'), style: NawyText.h4),
                const SizedBox(height: 8),
                Row(children: [
                  Expanded(child: _Option(key: const ValueKey('lang-en'), selected: _language == 'en', title: 'English', onTap: () => setState(() => _language = 'en'))),
                  const SizedBox(width: 8),
                  Expanded(child: _Option(key: const ValueKey('lang-ar'), selected: _language == 'ar', title: 'العربية', onTap: () => setState(() => _language = 'ar'))),
                ]),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: FilledButton(
                key: const ValueKey('market-continue'),
                onPressed: _country == null ? null : () => _continue(countries),
                child: Text(context.t('common.continue')),
              ),
            ),
          ]),
        ),
      ),
    );
  }

  static String _flag(String iso2) {
    if (iso2.length != 2) return '🌐';
    return String.fromCharCodes(iso2.toUpperCase().codeUnits.map((c) => 0x1F1E6 + c - 65));
  }
}

class _Option extends StatelessWidget {
  const _Option({super.key, required this.selected, required this.title, this.subtitle, this.leading, required this.onTap});

  final bool selected;
  final String title;
  final String? subtitle;
  final Widget? leading;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: selected ? NawyColors.bgBrandSubtle : NawyColors.bgSurface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(NawyRadius.card),
          side: BorderSide(color: selected ? NawyColors.bgBrand : NawyColors.borderDefault, width: selected ? 1.5 : 1),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(NawyRadius.card),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            child: Row(children: [
              if (leading != null) ...[leading!, const SizedBox(width: 12)],
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(title, style: NawyText.bodyMedium),
                  if (subtitle != null) Text(subtitle!, style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                ]),
              ),
              if (selected) const Icon(Icons.check_circle_rounded, color: NawyColors.textPrimary, size: 20),
            ]),
          ),
        ),
      );
}
