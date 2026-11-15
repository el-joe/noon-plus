import 'package:flutter/widgets.dart';

/// Hand-written EN/AR string tables (no `intl` / ARB codegen; project rule).
/// Keys are dotted (`login.title`). `{name}` placeholders are replaced from
/// params. A missing Arabic key falls back to English, then to the key. Each
/// app passes its own tables, merged over [coreEn] / [coreAr].
class L10n {
  L10n(this.locale, {Map<String, String> en = const {}, Map<String, String> ar = const {}})
      : en = {...coreEn, ...en},
        ar = {...coreAr, ...ar};

  final String locale;
  final Map<String, String> en;
  final Map<String, String> ar;

  bool get isAr => locale == 'ar';
  TextDirection get direction => isAr ? TextDirection.rtl : TextDirection.ltr;

  String t(String key, [Map<String, Object?>? params]) {
    var text = (isAr ? ar[key] : null) ?? en[key] ?? key;
    params?.forEach((name, value) => text = text.replaceAll('{$name}', '${value ?? ''}'));
    return text;
  }

  /// Status labels from the API (`out_for_delivery` → "Out for delivery").
  String status(String? raw) {
    if (raw == null || raw.isEmpty) return '';
    final key = 'status.$raw';
    final known = (isAr ? ar[key] : null) ?? en[key];
    if (known != null) return known;
    final words = raw.replaceAll(RegExp(r'[_.\-]'), ' ');
    return words[0].toUpperCase() + words.substring(1);
  }

  static L10n of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<L10nScope>()?.l10n ?? L10n('en');
}

class L10nScope extends InheritedWidget {
  const L10nScope({super.key, required this.l10n, required super.child});

  final L10n l10n;

  @override
  bool updateShouldNotify(L10nScope oldWidget) => oldWidget.l10n.locale != l10n.locale;
}

extension L10nContext on BuildContext {
  L10n get l10n => L10n.of(this);
  String t(String key, [Map<String, Object?>? params]) => L10n.of(this).t(key, params);
}

const Map<String, String> coreEn = {
  'common.cancel': 'Cancel',
  'common.confirm': 'Confirm',
  'common.retry': 'Try again',
  'common.save': 'Save',
  'common.saved': 'Saved',
  'common.submit': 'Submit',
  'common.reason': 'Reason',
  'common.reasonHint': 'Tell us why (at least 5 characters)',
  'common.loadMore': 'Load more',
  'common.all': 'All',
  'common.none': 'Nothing here yet',
  'common.refresh': 'Refresh',
  'login.email': 'Email',
  'login.password': 'Password',
  'login.market': 'Country',
  'login.signIn': 'Sign in',
  'login.required': 'Enter your email and password',
  'settings.title': 'Settings',
  'settings.language': 'Language',
  'settings.country': 'Country',
  'settings.signOut': 'Sign out',
  'settings.signedInAs': 'Signed in as {name}',
  'settings.version': 'Version {version}',
  'state.error': 'Something went wrong',
  'state.errorHint': 'Please try again in a moment.',
  'state.offline': 'You are offline',
  'state.offlineHint': 'Check your connection and try again.',
  'state.notFound': 'Not found',
  'market.uae': 'United Arab Emirates',
  'market.ksa': 'Saudi Arabia',
  'market.egy': 'Egypt',
  'market.kwt': 'Kuwait',
  'market.qat': 'Qatar',
  'market.bhr': 'Bahrain',
  'market.omn': 'Oman',
  'market.jor': 'Jordan',
};

const Map<String, String> coreAr = {
  'common.cancel': 'إلغاء',
  'common.confirm': 'تأكيد',
  'common.retry': 'حاول مرة أخرى',
  'common.save': 'حفظ',
  'common.saved': 'تم الحفظ',
  'common.submit': 'إرسال',
  'common.reason': 'السبب',
  'common.reasonHint': 'اذكر السبب (5 أحرف على الأقل)',
  'common.loadMore': 'عرض المزيد',
  'common.all': 'الكل',
  'common.none': 'لا يوجد شيء بعد',
  'common.refresh': 'تحديث',
  'login.email': 'البريد الإلكتروني',
  'login.password': 'كلمة المرور',
  'login.market': 'الدولة',
  'login.signIn': 'تسجيل الدخول',
  'login.required': 'أدخل بريدك الإلكتروني وكلمة المرور',
  'settings.title': 'الإعدادات',
  'settings.language': 'اللغة',
  'settings.country': 'الدولة',
  'settings.signOut': 'تسجيل الخروج',
  'settings.signedInAs': 'مسجّل الدخول باسم {name}',
  'settings.version': 'الإصدار {version}',
  'state.error': 'حدث خطأ ما',
  'state.errorHint': 'يرجى المحاولة مرة أخرى بعد قليل.',
  'state.offline': 'أنت غير متصل',
  'state.offlineHint': 'تحقق من الاتصال وحاول مرة أخرى.',
  'state.notFound': 'غير موجود',
  'market.uae': 'الإمارات العربية المتحدة',
  'market.ksa': 'السعودية',
  'market.egy': 'مصر',
  'market.kwt': 'الكويت',
  'market.qat': 'قطر',
  'market.bhr': 'البحرين',
  'market.omn': 'عُمان',
  'market.jor': 'الأردن',
};
