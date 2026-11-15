# Nawy customer app (Flutter)

The customer app in the Noon app structure (MASTER_PLAN §14c, ADR 16). It uses the Flutter SDK only: the project rule forbids adding pub packages without the owner's approval. The packages requested are listed in `docs/project/owner-review.md` → WP10.

## Run

```bash
export PATH=/home/joe/flutter/bin:$PATH
flutter run -d linux --dart-define=API_URL=http://localhost:8000/api/v1
```

- On the Android emulator, use `--dart-define=API_URL=http://10.0.2.2:8000/api/v1`.
- The web build uses `fetch` (`lib/core/api/transport_web.dart`). Session storage is in memory there.

## Check

```bash
flutter analyze
flutter test
flutter build linux
dart run tool/api_smoke.dart http://localhost:8000/api/v1 uae-en
```

`tool/api_smoke.dart` calls the live backend with the app's API client.

## Layout

| Path | Contents |
|---|---|
| `lib/core/` | API client (`{data, meta}` / `{error}` envelope, bearer and `X-Cart-Token`, dart:io and web transports), `Money` (no floats; 3 decimals for OMR/KWD/BHD/JOD), `SecureStore` (file stop-gap until `flutter_secure_storage` is approved), native bridge `nawy/platform` |
| `lib/state/` | `AppState` (market, session, app contexts, badges) and `CartStore` / `CartMath` |
| `lib/routing/` | Named routes, guards (`guardRedirect`), storefront-link resolver |
| `lib/l10n/` | Hand-written EN/AR tables; Arabic is RTL |
| `lib/ui/` | Figma tokens (`theme.dart`), product card, states, paged list |
| `lib/features/page_builder/` | Renderers for the 51 page-builder block types; unknown types are skipped |
| `lib/screens/` | Startup, auth, shell, home, catalogue, cart/checkout, account, travel, Souq/property, live |
