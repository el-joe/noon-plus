import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';
import '../auth/sign_in_screen.dart';

/// Figma "37 Wishlist" (`GET/POST/DELETE wishlist`).
class WishlistScreen extends StatefulWidget {
  const WishlistScreen({super.key, this.asTab = false});

  final bool asTab;

  @override
  State<WishlistScreen> createState() => _WishlistScreenState();
}

class _WishlistScreenState extends State<WishlistScreen> {
  int _gen = 0;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('wishlist.title')), automaticallyImplyLeading: !widget.asTab),
      body: !app.signedIn
          ? const SignInPrompt()
          : AsyncBody<List<Json>>(
              key: ValueKey(_gen),
              load: () => app.api.getList('wishlist', query: {'per_page': 100}).then((r) => r.data),
              isEmpty: (l) => l.isEmpty,
              empty: EmptyView(icon: Icons.favorite_border_rounded, title: context.t('wishlist.empty'), subtitle: context.t('wishlist.emptyHint')),
              builder: (context, items, reload) => RefreshIndicator(
                onRefresh: reload,
                child: ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: items.length,
                  separatorBuilder: (_, _) => const Divider(height: 24),
                  itemBuilder: (context, i) {
                    final card = items[i];
                    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      ProductCard(card: card, variant: ProductCardVariant.list),
                      Row(children: [
                        if (card['in_stock'] == false) Pill(context.t('product.outOfStock'), background: NawyColors.bgDangerSubtle, foreground: NawyColors.textDanger),
                        const Spacer(),
                        TextButton.icon(
                          onPressed: () async {
                            await guarded(context, () => app.api.delete('wishlist/${enc(card.str('slug'))}'), success: context.t('wishlist.removed'));
                            if (mounted) setState(() => _gen++);
                          },
                          icon: const Icon(Icons.delete_outline_rounded, size: 18),
                          label: Text(context.t('common.remove')),
                        ),
                        if (card.i('listing_id') != null && card['in_stock'] != false)
                          FilledButton(
                            onPressed: () => guarded(context, () => app.cart.add(card.i('listing_id')!), success: context.t('cart.added')),
                            child: Text(context.t('wishlist.moveToCart')),
                          ),
                      ]),
                    ]);
                  },
                ),
              ),
            ),
    );
  }
}
