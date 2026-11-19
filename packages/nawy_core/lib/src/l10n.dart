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
  'auth.expired': 'Your session has expired. Please sign in again.',
  'auth.wrongToken': 'This sign-in is not valid for this app. Please sign in again.',
  'auth.suspended': 'Your account is suspended. Contact Nawy support.',
  'error.forbidden': 'Your role does not allow this. Ask the account owner for access.',
  'error.tooMany': 'Too many attempts. Try again in {seconds} seconds.',
  'error.tooManyNow': 'Too many attempts. Please wait a moment and try again.',
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
  'media.camera': 'Take photo',
  'media.gallery': 'Choose photos',
  'media.count': '{n} of {max}',
  'media.limits': 'Up to {max} · {size} each',
  'media.remove': 'Remove',
  'media.tooBig': '{name} is larger than {size}.',
  'media.wrongType': '{name} is not a supported file type.',
  'media.tooMany': 'You can add up to {max}.',
  'media.cameraUnavailable': 'The camera is not available on this device.',
  'media.call': 'Call {phone}',
  'media.photo': 'Choose photo',
  'media.file': 'Choose file (PDF)',
  'media.noFile': 'No file chosen',
  'media.documentLimits': 'PDF, JPG or PNG · up to {size}',
  // Ops-app documents (PartnerDocumentsScreen); the delivery app has the same keys.
  'documents.title': 'Documents',
  'documents.menuHint': 'Licences and IDs Nawy needs, with their review and expiry',
  'documents.mandatory': 'Required',
  'documents.optional': 'Optional',
  'documents.missing': 'Missing',
  'documents.none': 'No documents required',
  'documents.missingTitle': 'Required documents missing',
  'documents.missingBody': 'Upload these so your account stays approved: {names}',
  'documents.expiringTitle': 'Documents expiring soon',
  'documents.validUntil': 'Valid until {date}',
  'documents.expiredOn': 'Expired on {date} — upload the renewed document.',
  'documents.expiresIn': 'Expires in {days} days — upload the renewed document.',
  'documents.expiresToday': 'Expires today — upload the renewed document.',
  'documents.rejectedBecause': 'Rejected: {reason}',
  'documents.pendingHint': 'Nawy is reviewing this document.',
  'documents.upload': 'Upload',
  'documents.replace': 'Replace',
  'documents.reupload': 'Re-upload',
  'documents.view': 'Download',
  'documents.saved': 'Saved to {path}',
  'documents.limits': 'Photo or PDF · up to {size} MB',
  'documents.fileRequired': 'Choose a photo or a PDF of the document.',
  'documents.expiryRequired': 'Enter the date the document expires.',
  'documents.validUntilLabel': 'Valid until',
  'documents.pickDate': 'Choose a date',
  'documents.send': 'Send for review',
  'documents.uploaded': 'Document sent for review',
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
  'auth.expired': 'انتهت صلاحية جلستك. يرجى تسجيل الدخول مرة أخرى.',
  'auth.wrongToken': 'تسجيل الدخول هذا غير صالح لهذا التطبيق. يرجى تسجيل الدخول مرة أخرى.',
  'auth.suspended': 'حسابك موقوف. تواصل مع دعم ناوي.',
  'error.forbidden': 'دورك لا يسمح بهذا الإجراء. اطلب الصلاحية من مالك الحساب.',
  'error.tooMany': 'محاولات كثيرة. حاول مرة أخرى بعد {seconds} ثانية.',
  'error.tooManyNow': 'محاولات كثيرة. يرجى الانتظار قليلاً ثم المحاولة مرة أخرى.',
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
  'media.camera': 'التقط صورة',
  'media.gallery': 'اختر صوراً',
  'media.count': '{n} من {max}',
  'media.limits': 'حتى {max} · {size} لكل ملف',
  'media.remove': 'إزالة',
  'media.tooBig': '{name} أكبر من {size}.',
  'media.wrongType': 'نوع الملف {name} غير مدعوم.',
  'media.tooMany': 'يمكنك إضافة {max} كحد أقصى.',
  'media.cameraUnavailable': 'الكاميرا غير متاحة على هذا الجهاز.',
  'media.call': 'اتصل بـ {phone}',
  'media.photo': 'اختر صورة',
  'media.file': 'اختر ملفاً (PDF)',
  'media.noFile': 'لم يتم اختيار ملف',
  'media.documentLimits': 'PDF أو JPG أو PNG · حتى {size}',
  'documents.title': 'المستندات',
  'documents.menuHint': 'التراخيص والهويات التي تطلبها ناوي، مع حالة المراجعة والانتهاء',
  'documents.mandatory': 'مطلوب',
  'documents.optional': 'اختياري',
  'documents.missing': 'ناقص',
  'documents.none': 'لا توجد مستندات مطلوبة',
  'documents.missingTitle': 'مستندات مطلوبة ناقصة',
  'documents.missingBody': 'ارفع هذه المستندات ليبقى حسابك معتمداً: {names}',
  'documents.expiringTitle': 'مستندات تنتهي قريباً',
  'documents.validUntil': 'صالح حتى {date}',
  'documents.expiredOn': 'انتهى في {date} — ارفع المستند المجدد.',
  'documents.expiresIn': 'ينتهي خلال {days} يوماً — ارفع المستند المجدد.',
  'documents.expiresToday': 'ينتهي اليوم — ارفع المستند المجدد.',
  'documents.rejectedBecause': 'مرفوض: {reason}',
  'documents.pendingHint': 'ناوي تراجع هذا المستند.',
  'documents.upload': 'رفع',
  'documents.replace': 'استبدال',
  'documents.reupload': 'إعادة الرفع',
  'documents.view': 'تنزيل',
  'documents.saved': 'تم الحفظ في {path}',
  'documents.limits': 'صورة أو PDF · حتى {size} ميغابايت',
  'documents.fileRequired': 'اختر صورة أو ملف PDF للمستند.',
  'documents.expiryRequired': 'أدخل تاريخ انتهاء المستند.',
  'documents.validUntilLabel': 'صالح حتى',
  'documents.pickDate': 'اختر تاريخاً',
  'documents.send': 'إرسال للمراجعة',
  'documents.uploaded': 'تم إرسال المستند للمراجعة',
};
