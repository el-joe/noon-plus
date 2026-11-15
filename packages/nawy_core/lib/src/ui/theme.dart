import 'package:flutter/material.dart';

/// Design tokens mirrored from Figma file d2MhBJmRddMrMSHfjJY8Y8
/// (variable collections Color / Spacing / Radius, text styles `en/*`, `ar/*`;
/// page "App · Customer v2 (Noon structure)").
class NawyColors {
  const NawyColors._();

  static const textPrimary = Color(0xFF0D1526);
  static const textSecondary = Color(0xFF565A64);
  static const textMuted = Color(0xFFA1A4AD);
  static const textInverse = Color(0xFFFFFFFF);
  static const textBrand = Color(0xFF9C5F09);
  static const textLink = Color(0xFF175CD3);
  static const textSuccess = Color(0xFF027A48);
  static const textDanger = Color(0xFFB42318);

  static const bgPage = Color(0xFFF7F7F8);
  static const bgSurface = Color(0xFFFFFFFF);
  static const bgBrand = Color(0xFFF9BB25);
  static const bgBrandSubtle = Color(0xFFFFF8E6);
  static const bgInverse = Color(0xFF0D1526);
  static const bgSuccess = Color(0xFF12B76A);
  static const bgSuccessSubtle = Color(0xFFECFDF3);
  static const bgDanger = Color(0xFFF04438);
  static const bgDangerSubtle = Color(0xFFFEF3F2);
  static const bgWarningSubtle = Color(0xFFFFF6ED);
  static const bgInfoSubtle = Color(0xFFEFF6FF);
  static const borderDefault = Color(0xFFE2E3E7);
}

class NawySpace {
  const NawySpace._();
  static const xxs = 2.0;
  static const xs = 4.0;
  static const sm = 8.0;
  static const md = 12.0;
  static const lg = 16.0;
  static const xl = 20.0;
  static const xxl = 24.0;
  static const xxxl = 32.0;

  /// Screen gutter (375pt frames use 16).
  static const gutter = 16.0;
}

class NawyRadius {
  const NawyRadius._();
  static const xs = 3.0;
  static const sm = 4.0;
  static const md = 8.0;
  static const card = 10.0;
  static const lg = 14.0;
  static const pill = 999.0;
}

/// Text styles: `en/*` (Inter) and `ar/*` (Cairo). The fonts are not bundled
/// (no font assets in the repo); the platform falls back to its sans font.
class NawyText {
  const NawyText._();

  static const _fallback = ['Inter', 'Cairo', 'Roboto', 'Noto Sans Arabic'];

  static TextStyle _t(double size, double height, FontWeight weight) =>
      TextStyle(fontSize: size, height: height / size, fontWeight: weight, color: NawyColors.textPrimary, fontFamilyFallback: _fallback);

  static final h1 = _t(28, 36, FontWeight.w700);
  static final h2 = _t(22, 30, FontWeight.w600);
  static final h3 = _t(18, 26, FontWeight.w600);
  static final h4 = _t(16, 24, FontWeight.w600);
  static final body = _t(14, 20, FontWeight.w400);
  static final bodyMedium = _t(14, 20, FontWeight.w500);
  static final bodySm = _t(12, 16, FontWeight.w400);
  static final label = _t(13, 16, FontWeight.w500);
  static final caption = _t(11, 14, FontWeight.w500);
  static final display = _t(44, 52, FontWeight.w700);
}

ThemeData buildNawyTheme() {
  final scheme = ColorScheme.fromSeed(
    seedColor: NawyColors.bgBrand,
    primary: NawyColors.bgBrand,
    onPrimary: NawyColors.textPrimary,
    secondary: NawyColors.bgInverse,
    onSecondary: NawyColors.textInverse,
    error: NawyColors.bgDanger,
    surface: NawyColors.bgSurface,
    onSurface: NawyColors.textPrimary,
  );
  final textTheme = TextTheme(
    headlineLarge: NawyText.h1,
    headlineMedium: NawyText.h2,
    headlineSmall: NawyText.h3,
    titleLarge: NawyText.h3,
    titleMedium: NawyText.h4,
    titleSmall: NawyText.bodyMedium,
    bodyLarge: NawyText.body,
    bodyMedium: NawyText.body,
    bodySmall: NawyText.bodySm,
    labelLarge: NawyText.label,
    labelMedium: NawyText.label,
    labelSmall: NawyText.caption,
  );
  final roundedSm = RoundedRectangleBorder(borderRadius: BorderRadius.circular(NawyRadius.md));
  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: NawyColors.bgPage,
    textTheme: textTheme,
    dividerColor: NawyColors.borderDefault,
    appBarTheme: AppBarTheme(
      backgroundColor: NawyColors.bgSurface,
      foregroundColor: NawyColors.textPrimary,
      elevation: 0,
      scrolledUnderElevation: 0.5,
      centerTitle: false,
      titleTextStyle: NawyText.h4,
    ),
    cardTheme: CardThemeData(
      color: NawyColors.bgSurface,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(NawyRadius.card), side: const BorderSide(color: NawyColors.borderDefault)),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: NawyColors.bgBrand,
        foregroundColor: NawyColors.textPrimary,
        disabledBackgroundColor: NawyColors.borderDefault,
        minimumSize: const Size(48, 48),
        textStyle: NawyText.h4,
        shape: roundedSm,
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: NawyColors.textPrimary,
        minimumSize: const Size(44, 44),
        side: const BorderSide(color: NawyColors.borderDefault),
        textStyle: NawyText.bodyMedium,
        shape: roundedSm,
      ),
    ),
    textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(foregroundColor: NawyColors.textLink, textStyle: NawyText.bodyMedium)),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: NawyColors.bgSurface,
      isDense: true,
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      hintStyle: NawyText.body.copyWith(color: NawyColors.textMuted),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(NawyRadius.md), borderSide: const BorderSide(color: NawyColors.borderDefault)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(NawyRadius.md), borderSide: const BorderSide(color: NawyColors.borderDefault)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(NawyRadius.md), borderSide: const BorderSide(color: NawyColors.textPrimary, width: 1.5)),
      errorBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(NawyRadius.md), borderSide: const BorderSide(color: NawyColors.bgDanger)),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: NawyColors.bgSurface,
      selectedColor: NawyColors.bgInverse,
      labelStyle: NawyText.label,
      side: const BorderSide(color: NawyColors.borderDefault),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(NawyRadius.pill)),
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: NawyColors.bgSurface,
      showDragHandle: true,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(NawyRadius.lg))),
    ),
    snackBarTheme: SnackBarThemeData(behavior: SnackBarBehavior.floating, backgroundColor: NawyColors.bgInverse, contentTextStyle: NawyText.body.copyWith(color: NawyColors.textInverse)),
    dividerTheme: const DividerThemeData(color: NawyColors.borderDefault, space: 1, thickness: 1),
    listTileTheme: ListTileThemeData(titleTextStyle: NawyText.body, subtitleTextStyle: NawyText.bodySm.copyWith(color: NawyColors.textSecondary), iconColor: NawyColors.textPrimary),
  );
}

/// Parse "#RRGGBB" from app-context themes (ADR 16).
Color? hexColor(String? hex) {
  if (hex == null) return null;
  final match = RegExp(r'^#?([0-9a-fA-F]{6})$').firstMatch(hex.trim());
  if (match == null) return null;
  return Color(0xFF000000 | int.parse(match.group(1)!, radix: 16));
}
