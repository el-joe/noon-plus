import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../theme.dart';

/// Network image with a neutral placeholder and error icon, cached on disk
/// and faded in (`cached_network_image`, owner-review D16 / P2).
class NetImage extends StatelessWidget {
  const NetImage(this.url, {super.key, this.fit = BoxFit.cover, this.radius = 0, this.width, this.height, this.icon = Icons.image_outlined, this.background = NawyColors.bgPage});

  /// Widget tests turn the disk cache off (`test/flutter_test_config.dart`):
  /// the cache manager needs sqflite / path_provider platform channels.
  static bool useCache = true;

  final String? url;
  final BoxFit fit;
  final double radius;
  final double? width;
  final double? height;
  final IconData icon;
  final Color background;

  bool get _valid {
    final u = url;
    return u != null && (u.startsWith('http://') || u.startsWith('https://'));
  }

  @override
  Widget build(BuildContext context) {
    final placeholder = Container(
      width: width,
      height: height,
      color: background,
      alignment: Alignment.center,
      child: Icon(icon, color: NawyColors.textMuted, size: 28),
    );
    final Widget child;
    if (!_valid) {
      child = placeholder;
    } else if (useCache) {
      child = CachedNetworkImage(
        imageUrl: url!,
        fit: fit,
        width: width,
        height: height,
        fadeInDuration: const Duration(milliseconds: 200),
        placeholder: (_, _) => placeholder,
        errorWidget: (_, _, _) => placeholder,
      );
    } else {
      child = Image.network(
        url!,
        fit: fit,
        width: width,
        height: height,
        errorBuilder: (_, _, _) => placeholder,
        loadingBuilder: (context, child, progress) => progress == null ? child : placeholder,
      );
    }
    return radius > 0 ? ClipRRect(borderRadius: BorderRadius.circular(radius), child: child) : child;
  }
}

/// Image provider for avatars: cached like [NetImage] (plain network image
/// when [NetImage.useCache] is off in tests).
ImageProvider netImageProvider(String url) => NetImage.useCache ? CachedNetworkImageProvider(url) : NetworkImage(url);

/// "Ad" / "Sponsored" label — required on every sponsored unit (page-builder rule 1).
class AdLabel extends StatelessWidget {
  const AdLabel({super.key, this.text});

  final String? text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
        decoration: BoxDecoration(color: NawyColors.bgSurface.withValues(alpha: 0.92), borderRadius: BorderRadius.circular(NawyRadius.sm), border: Border.all(color: NawyColors.borderDefault)),
        child: Text(text?.isNotEmpty == true ? text! : context.t('common.ad'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
      );
}

class SectionHeader extends StatelessWidget {
  const SectionHeader({super.key, required this.title, this.subtitle, this.onViewAll, this.trailing, this.padding = const EdgeInsets.fromLTRB(16, 20, 16, 10)});

  final String title;
  final String? subtitle;
  final VoidCallback? onViewAll;
  final Widget? trailing;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => Padding(
        padding: padding,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(title, style: NawyText.h4),
                if (subtitle != null) Text(subtitle!, style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
              ]),
            ),
            ?trailing,
            if (onViewAll != null)
              InkWell(
                onTap: onViewAll,
                child: Padding(padding: const EdgeInsets.all(4), child: Text(context.t('common.viewAll'), style: NawyText.label.copyWith(color: NawyColors.textLink))),
              ),
          ],
        ),
      );
}

class RatingPill extends StatelessWidget {
  const RatingPill({super.key, required this.average, this.count});

  final String? average;
  final int? count;

  @override
  Widget build(BuildContext context) {
    final avg = double.tryParse(average ?? '');
    if (avg == null || avg <= 0) return const SizedBox.shrink();
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
        decoration: BoxDecoration(color: NawyColors.bgSuccess, borderRadius: BorderRadius.circular(NawyRadius.sm)),
        child: Text('${avg.toStringAsFixed(1)} ★', style: NawyText.caption.copyWith(color: NawyColors.textInverse)),
      ),
      if (count != null && count! > 0) ...[
        const SizedBox(width: 4),
        Text('(${compactCount(count!)})', style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
      ],
    ]);
  }
}

String compactCount(int n) {
  if (n >= 1000000) return '${(n / 1000000).toStringAsFixed(1)}M';
  if (n >= 1000) return '${(n / 1000).toStringAsFixed(1)}K';
  return '$n';
}

/// Price + struck was-price + green "% OFF" (Figma product card).
class PriceLine extends StatelessWidget {
  const PriceLine({super.key, required this.price, this.was, this.large = false});

  final Money? price;
  final Money? was;
  final bool large;

  @override
  Widget build(BuildContext context) {
    final p = price;
    if (p == null) return const SizedBox.shrink();
    final w = was;
    final off = w != null && w.currency == p.currency ? p.percentOffFrom(w) : 0;
    return Wrap(
      crossAxisAlignment: WrapCrossAlignment.end,
      spacing: 4,
      children: [
        Text(p.currency, style: (large ? NawyText.bodyMedium : NawyText.caption)),
        Text(p.grouped, style: large ? NawyText.h2 : NawyText.h4, textDirection: TextDirection.ltr),
        if (off > 0) ...[
          Text(w!.grouped, style: NawyText.caption.copyWith(color: NawyColors.textMuted, decoration: TextDecoration.lineThrough), textDirection: TextDirection.ltr),
          Text(context.t('product.off', {'percent': off}), style: NawyText.caption.copyWith(color: NawyColors.textSuccess)),
        ],
      ],
    );
  }
}

class MoneyText extends StatelessWidget {
  const MoneyText(this.json, {super.key, this.style});

  final dynamic json;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) => Text(formatMoney(json, locale: context.l10n.locale), style: style ?? NawyText.bodyMedium);
}

class Pill extends StatelessWidget {
  const Pill(this.text, {super.key, this.background = NawyColors.bgBrandSubtle, this.foreground = NawyColors.textBrand, this.icon});

  final String text;
  final Color background;
  final Color foreground;
  final IconData? icon;

  factory Pill.status(BuildContext context, String? status) {
    final s = status ?? '';
    final (bg, fg) = switch (s) {
      'delivered' || 'completed' || 'confirmed' || 'paid' || 'active' || 'approved' || 'published' || 'refunded' || 'succeeded' => (NawyColors.bgSuccessSubtle, NawyColors.textSuccess),
      'cancelled' || 'rejected' || 'failed' || 'hold_expired' || 'expired' || 'returned' || 'voided' || 'hidden' => (NawyColors.bgDangerSubtle, NawyColors.textDanger),
      'requested' || 'held' || 'pending' || 'pending_payment' || 'under_review' || 'scheduled' || 'disputed' => (NawyColors.bgWarningSubtle, NawyColors.textBrand),
      _ => (NawyColors.bgInfoSubtle, NawyColors.textLink),
    };
    return Pill(context.l10n.status(s), background: bg, foreground: fg);
  }

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(NawyRadius.pill)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: 12, color: foreground), const SizedBox(width: 4)],
          Text(text, style: NawyText.caption.copyWith(color: foreground)),
        ]),
      );
}

/// White rounded card section.
class SectionCard extends StatelessWidget {
  const SectionCard({super.key, required this.child, this.padding = const EdgeInsets.all(16), this.margin = const EdgeInsets.fromLTRB(16, 0, 16, 12), this.title, this.color});

  final Widget child;
  final EdgeInsets padding;
  final EdgeInsets margin;
  final String? title;
  final Color? color;

  @override
  Widget build(BuildContext context) => Padding(
        padding: margin,
        child: Material(
          // A Material (not a decorated box) so ListTiles inside keep their ink.
          color: color ?? NawyColors.bgSurface,
          clipBehavior: Clip.antiAlias,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(NawyRadius.card), side: const BorderSide(color: NawyColors.borderDefault)),
          child: SizedBox(
            width: double.infinity,
            child: Padding(
              padding: padding,
              child: title == null
                  ? child
                  : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(title!, style: NawyText.h4), const SizedBox(height: 10), child]),
            ),
          ),
        ),
      );
}

class InfoRow extends StatelessWidget {
  const InfoRow(this.label, this.value, {super.key, this.bold = false, this.valueColor});

  final String label;
  final String value;
  final bool bold;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(child: Text(label, style: (bold ? NawyText.h4 : NawyText.body).copyWith(color: bold ? null : NawyColors.textSecondary))),
          const SizedBox(width: 12),
          Flexible(
            child: Align(
              alignment: AlignmentDirectional.centerEnd,
              child: Text(value, textAlign: TextAlign.end, style: (bold ? NawyText.h4 : NawyText.bodyMedium).copyWith(color: valueColor)),
            ),
          ),
        ]),
      );
}

/// Primary action with a busy state.
class BusyButton extends StatefulWidget {
  const BusyButton({super.key, required this.label, required this.onPressed, this.expand = true, this.outlined = false});

  final String label;
  final Future<void> Function()? onPressed;
  final bool expand;
  final bool outlined;

  @override
  State<BusyButton> createState() => _BusyButtonState();
}

class _BusyButtonState extends State<BusyButton> {
  bool _busy = false;

  Future<void> _tap() async {
    if (_busy || widget.onPressed == null) return;
    setState(() => _busy = true);
    try {
      await widget.onPressed!();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final child = _busy
        ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: NawyColors.textPrimary))
        : Text(widget.label);
    final button = widget.outlined
        ? OutlinedButton(onPressed: widget.onPressed == null ? null : _tap, child: child)
        : FilledButton(onPressed: widget.onPressed == null ? null : _tap, child: child);
    return widget.expand ? SizedBox(width: double.infinity, child: button) : button;
  }
}

class QuantityStepper extends StatelessWidget {
  const QuantityStepper({super.key, required this.value, required this.onChanged, this.min = 0, this.max = 10});

  final int value;
  final ValueChanged<int> onChanged;
  final int min;
  final int max;

  @override
  Widget build(BuildContext context) => Container(
        decoration: BoxDecoration(border: Border.all(color: NawyColors.borderDefault), borderRadius: BorderRadius.circular(NawyRadius.md), color: NawyColors.bgSurface),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          _btn(value <= 1 && min == 0 ? Icons.delete_outline : Icons.remove, value > min ? () => onChanged(value - 1) : null, context.t('common.decrease')),
          SizedBox(width: 28, child: Text('$value', textAlign: TextAlign.center, style: NawyText.bodyMedium)),
          _btn(Icons.add, value < max ? () => onChanged(value + 1) : null, context.t('common.increase')),
        ]),
      );

  Widget _btn(IconData icon, VoidCallback? onTap, String label) => IconButton(
        tooltip: label,
        onPressed: onTap,
        icon: Icon(icon, size: 18),
        visualDensity: VisualDensity.compact,
        constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
      );
}

/// Bottom sheet with a title and scrollable content.
Future<T?> showNawySheet<T>(BuildContext context, {required String title, required Widget Function(BuildContext) builder, bool full = false}) {
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (ctx) => ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.of(ctx).size.height * (full ? 0.95 : 0.85)),
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 8), child: Text(title, style: NawyText.h3)),
          Flexible(child: builder(ctx)),
        ]),
      ),
    ),
  );
}

/// Short date "14 Nov 2026" from an ISO string, in the market language.
String shortDate(BuildContext context, String? iso, {bool time = false}) {
  if (iso == null) return '';
  final dt = DateTime.tryParse(iso)?.toLocal();
  if (dt == null) return iso;
  final months = context.l10n.isAr
      ? const ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']
      : const ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  final date = '${dt.day} ${months[dt.month - 1]} ${dt.year}';
  if (!time) return date;
  return '$date ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
}

/// Sign-in gate for actions (wishlist, write review, …): returns true when signed in.
Future<bool> requireSignIn(BuildContext context) async {
  final app = AppScope.read(context);
  if (app.signedIn) return true;
  await Navigator.of(context).pushNamed('/sign-in', arguments: {'pop': true});
  return app.signedIn;
}

Json? productImage(Json card) => card.m('image');

/// A yes / no dialog; true when [confirm] was tapped.
Future<bool?> confirmDialog(BuildContext context, {required String title, String? body, required String confirm}) => showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: body == null ? null : Text(body),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(ctx.t('common.cancel'))),
          TextButton(key: const ValueKey('confirm-yes'), onPressed: () => Navigator.of(ctx).pop(true), child: Text(confirm)),
        ],
      ),
    );
