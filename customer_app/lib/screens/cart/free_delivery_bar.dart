import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../ui/theme.dart';

/// "Spend X more for free delivery" (G-A5): cart `data.free_delivery`
/// `{threshold, remaining, progress_percent, qualified}`; null when the
/// market has no threshold (then nothing is shown).
class FreeDeliveryBar extends StatelessWidget {
  const FreeDeliveryBar({super.key, required this.data});

  final Json data;

  /// The bar's state from the API value, or null to hide it.
  static ({bool qualified, Money? remaining, double progress})? read(dynamic raw) {
    if (raw is! Map) return null;
    final json = asJson(raw);
    final remaining = Money.fromJson(json['remaining']);
    final qualified = json.b('qualified') || (remaining != null && !remaining.isNegative && remaining.isZero);
    final percent = (json.d('progress_percent') ?? (qualified ? 100 : 0)).clamp(0, 100).toDouble();
    return (qualified: qualified, remaining: remaining, progress: qualified ? 1.0 : percent / 100);
  }

  @override
  Widget build(BuildContext context) {
    final state = read(data);
    if (state == null) return const SizedBox.shrink();
    final locale = context.l10n.locale;
    final fg = state.qualified ? NawyColors.textSuccess : NawyColors.textBrand;
    return Container(
      key: const ValueKey('cart-free-delivery'),
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: state.qualified ? NawyColors.bgSuccessSubtle : NawyColors.bgBrandSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(Icons.local_shipping_outlined, color: fg, size: 18),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              state.qualified
                  ? context.t('cart.freeDeliveryQualified')
                  : context.t('cart.freeDeliverySpendMore', {'amount': state.remaining?.format(locale: locale) ?? ''}),
              style: NawyText.label.copyWith(color: fg),
            ),
          ),
        ]),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(NawyRadius.pill),
          child: LinearProgressIndicator(
            key: const ValueKey('cart-free-delivery-progress'),
            value: state.progress,
            minHeight: 6,
            backgroundColor: NawyColors.borderDefault,
            color: state.qualified ? NawyColors.bgSuccess : NawyColors.bgBrand,
          ),
        ),
      ]),
    );
  }
}
