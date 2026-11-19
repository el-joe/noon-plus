import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart' show LatLng;

import '../../core/json.dart';
import '../../core/money.dart';
import '../../core/platform/platform_bridge.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

/// Map tiles (`--dart-define`, owner-review D16: flutter_map + OSM).
///
/// The defaults are the OpenStreetMap standard tiles; their usage policy asks
/// for a real User-Agent ([userAgentPackageName]) and a visible attribution.
/// A production build should point [tileUrl] at a tile server Nawy may use
/// at its traffic (`MAP_TILE_URL`, `MAP_TILE_ATTRIBUTION`).
class MapConfig {
  const MapConfig._();

  static const String tileUrl = String.fromEnvironment('MAP_TILE_URL', defaultValue: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
  static const String attribution = String.fromEnvironment('MAP_TILE_ATTRIBUTION', defaultValue: '© OpenStreetMap contributors');
  static const String attributionUrl = String.fromEnvironment('MAP_TILE_ATTRIBUTION_URL', defaultValue: 'https://www.openstreetmap.org/copyright');
  static const String userAgentPackageName = String.fromEnvironment('MAP_USER_AGENT_PACKAGE', defaultValue: 'com.example.customer_app');

  /// Where the map opens when there is nothing to fit (Dubai).
  static const fallbackCenter = LatLng(25.2048, 55.2708);

  /// Tests set this so no tile is fetched from the network; null = the
  /// flutter_map network provider.
  static TileProvider Function()? tileProvider;
}

/// One pin: an ad with a location.
class MapListing {
  const MapListing({required this.number, required this.point, this.title = '', this.price, this.featured = false, this.image, this.place});

  final String number;
  final LatLng point;
  final String title;

  /// API money (`{amount, currency}`), null = price on request.
  final Json? price;
  final bool featured;
  final String? image;
  final String? place;
}

LatLng? _point(double? lat, double? lng) {
  if (lat == null || lng == null || lat.isNaN || lng.isNaN) return null;
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
  // 0,0 is a missing location saved as zeros, not a listing in the ocean.
  if (lat == 0 && lng == 0) return null;
  return LatLng(lat, lng);
}

double? _num(dynamic v) => v is num ? v.toDouble() : (v is String ? double.tryParse(v) : null);

/// Pins from `GET classifieds/map` (GeoJSON `FeatureCollection`; points are
/// `[lng, lat]`, `properties.price` is the amount with `currency` beside it).
List<MapListing> mapListingsFromGeoJson(dynamic json) {
  final features = json is Map ? asJsonList(json['features']) : const <Json>[];
  final out = <MapListing>[];
  final seen = <String>{};
  for (final f in features) {
    final geometry = f.m('geometry');
    final props = f.m('properties') ?? const <String, dynamic>{};
    final number = props.s('number');
    final coords = geometry?['coordinates'];
    if (geometry?.s('type') != 'Point' || number == null || coords is! List || coords.length < 2 || !seen.add(number)) continue;
    final point = _point(_num(coords[1]), _num(coords[0]));
    if (point == null) continue;
    final amount = props.s('price');
    out.add(
      MapListing(
        number: number,
        point: point,
        title: props.str('title'),
        price: amount == null || props.s('currency') == null ? null : {'amount': amount, 'currency': props.s('currency')},
        featured: props.b('featured'),
      ),
    );
  }
  return out;
}

/// Pins from ad cards (`ClassifiedPresenter::card`, `location: {lat, lng}`);
/// ads without a location are left out.
List<MapListing> mapListingsFromCards(List<Json> ads) {
  final out = <MapListing>[];
  for (final ad in ads) {
    final location = ad.m('location');
    final point = _point(location?.d('lat'), location?.d('lng'));
    if (ad.s('number') == null || point == null) continue;
    out.add(MapListing(
      number: ad.str('number'),
      point: point,
      title: ad.str('title'),
      price: ad.m('price'),
      featured: ad.b('is_featured'),
      image: ad.s('image'),
      place: [ad.s('area'), ad.s('city')].whereType<String>().where((s) => s.isNotEmpty).join(', '),
    ));
  }
  return out;
}

/// The box around every pin, null when there are none.
LatLngBounds? listingBounds(List<MapListing> listings) => listings.isEmpty ? null : LatLngBounds.fromPoints([for (final l in listings) l.point]);

/// `bounds` filter of `GET classifieds/map` / `classifieds/ads`:
/// `south,west,north,east`.
String boundsQuery(LatLngBounds b) => [b.south, b.west, b.north, b.east].map((v) => v.toStringAsFixed(6)).join(',');

/// Short price for a pin: "AED 1.2M", "AED 85K", "AED 950" (Arabic puts the
/// code after the amount, as [formatMoney] does). Empty when there is none.
String compactPrice(Json? price, {String locale = 'en'}) {
  final money = Money.fromJson(price);
  if (money == null) return '';
  final value = double.tryParse(money.amount) ?? 0;
  String trim(double v) => (v >= 100 || v == v.roundToDouble() ? v.round().toString() : v.toStringAsFixed(1)).replaceAll(RegExp(r'\.0$'), '');
  final short = value.abs() >= 1e6
      ? '${trim(value / 1e6)}M'
      : value.abs() >= 1e3
          ? '${trim(value / 1e3)}K'
          : value.round().toString();
  return locale == 'ar' ? '$short ${money.currency}' : '${money.currency} $short';
}

/// The map of [listings]: price pins, tap → summary card → ad detail, the
/// camera fitted to the pins, "Search this area" after the user moves it.
class ListingsMap extends StatefulWidget {
  const ListingsMap({super.key, required this.listings, this.onSearchArea});

  final List<MapListing> listings;

  /// Called with the visible box when the user asks to search it.
  final ValueChanged<LatLngBounds>? onSearchArea;

  @override
  State<ListingsMap> createState() => _ListingsMapState();
}

class _ListingsMapState extends State<ListingsMap> {
  final _controller = MapController();
  MapListing? _selected;
  bool _moved = false;

  @override
  void didUpdateWidget(ListingsMap old) {
    super.didUpdateWidget(old);
    if (!identical(old.listings, widget.listings)) {
      _selected = null;
      _moved = false;
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _open(MapListing l) => Navigator.of(context).pushNamed(Routes.souqAd, arguments: {'number': l.number});

  @override
  Widget build(BuildContext context) {
    final listings = widget.listings;
    final points = [for (final l in listings) l.point];
    final locale = context.l10n.locale;
    return Stack(
      children: [
        FlutterMap(
          key: const ValueKey('listings-map'),
          mapController: _controller,
          options: MapOptions(
            initialCenter: points.length == 1 ? points.single : MapConfig.fallbackCenter,
            initialZoom: points.length == 1 ? 15 : 10,
            initialCameraFit: points.length > 1 ? CameraFit.coordinates(coordinates: points, padding: const EdgeInsets.fromLTRB(56, 72, 56, 120), maxZoom: 16) : null,
            minZoom: 3,
            maxZoom: 18,
            interactionOptions: const InteractionOptions(flags: InteractiveFlag.all & ~InteractiveFlag.rotate),
            onTap: (_, _) => _selected == null ? null : setState(() => _selected = null),
            onPositionChanged: (_, hasGesture) {
              if (hasGesture && !_moved && widget.onSearchArea != null) setState(() => _moved = true);
            },
          ),
          children: [
            TileLayer(urlTemplate: MapConfig.tileUrl, userAgentPackageName: MapConfig.userAgentPackageName, tileProvider: MapConfig.tileProvider?.call(), maxNativeZoom: 19),
            MarkerLayer(
              rotate: false,
              markers: [
                for (final l in listings)
                  Marker(
                    key: ValueKey('map-pin-${l.number}'),
                    point: l.point,
                    width: 112,
                    height: 44,
                    alignment: Alignment.topCenter,
                    child: _PricePin(
                      label: compactPrice(l.price, locale: locale),
                      selected: identical(l, _selected),
                      featured: l.featured,
                      onTap: () => setState(() => _selected = l),
                      semantics: '${l.title} ${formatMoney(l.price, locale: locale, empty: context.t('souq.priceOnRequest'))}',
                    ),
                  ),
              ],
            ),
            const _Attribution(key: ValueKey('map-attribution')),
        ],
        ),
        PositionedDirectional(
          top: 12,
          start: 12,
          child: Pill(context.t('souq.mapCount', {'n': listings.length}), background: NawyColors.bgSurface, foreground: NawyColors.textPrimary, icon: Icons.place_outlined),
        ),
        if (_moved && widget.onSearchArea != null)
          Positioned(
            top: 8,
            left: 0,
            right: 0,
            child: Center(
              child: FilledButton.icon(
                key: const ValueKey('map-search-area'),
                onPressed: () {
                  setState(() => _moved = false);
                  widget.onSearchArea!(_controller.camera.visibleBounds);
                },
                icon: const Icon(Icons.refresh_rounded, size: 18),
                label: Text(context.t('souq.searchArea')),
              ),
            ),
          ),
        if (_selected case final selected?)
          // Above the attribution line, which must stay visible (OSM policy).
          PositionedDirectional(
            start: 12,
            end: 12,
            bottom: 28,
            child: _ListingSummary(listing: selected, onOpen: () => _open(selected), onClose: () => setState(() => _selected = null)),
          ),
      ],
    );
  }
}

/// The tile attribution (OSM policy: always visible), bottom end; taps
/// open the copyright page.
class _Attribution extends StatelessWidget {
  const _Attribution({super.key});

  @override
  Widget build(BuildContext context) => SafeArea(
        child: Align(
          alignment: AlignmentDirectional.bottomEnd,
          child: Material(
            color: NawyColors.bgSurface.withValues(alpha: 0.85),
            child: InkWell(
              onTap: () => PlatformBridge.openUrl(MapConfig.attributionUrl),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
                child: Text(MapConfig.attribution, textDirection: TextDirection.ltr, style: NawyText.caption.copyWith(fontSize: 11, color: NawyColors.textSecondary)),
              ),
            ),
          ),
        ),
      );
}

class _PricePin extends StatelessWidget {
  const _PricePin({required this.label, required this.selected, required this.featured, required this.onTap, required this.semantics});

  final String label;
  final bool selected;
  final bool featured;
  final VoidCallback onTap;
  final String semantics;

  @override
  Widget build(BuildContext context) {
    final bg = selected ? NawyColors.bgInverse : (featured ? NawyColors.bgBrand : NawyColors.bgSurface);
    final fg = selected ? NawyColors.textInverse : NawyColors.textPrimary;
    return Semantics(
      button: true,
      label: semantics,
      excludeSemantics: true,
      child: GestureDetector(
        onTap: onTap,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: bg,
                borderRadius: BorderRadius.circular(NawyRadius.pill),
                border: Border.all(color: selected ? NawyColors.bgInverse : NawyColors.borderDefault),
                boxShadow: const [BoxShadow(color: Color(0x33000000), blurRadius: 4, offset: Offset(0, 1))],
              ),
              child: label.isEmpty
                  ? Icon(Icons.home_work_outlined, size: 16, color: fg)
                  : Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      textDirection: TextDirection.ltr,
                      style: NawyText.label.copyWith(color: fg, fontSize: 12),
                    ),
            ),
            CustomPaint(size: const Size(10, 6), painter: _PinTail(bg)),
          ],
        ),
      ),
    );
  }
}

class _PinTail extends CustomPainter {
  const _PinTail(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final path = Path()
      ..moveTo(0, 0)
      ..lineTo(size.width, 0)
      ..lineTo(size.width / 2, size.height)
      ..close();
    canvas.drawPath(path, Paint()..color = color);
  }

  @override
  bool shouldRepaint(_PinTail old) => old.color != color;
}

class _ListingSummary extends StatelessWidget {
  const _ListingSummary({required this.listing, required this.onOpen, required this.onClose});

  final MapListing listing;
  final VoidCallback onOpen;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) => Material(
        key: const ValueKey('map-listing-card'),
        color: NawyColors.bgSurface,
        elevation: 6,
        borderRadius: BorderRadius.circular(NawyRadius.lg),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onOpen,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                if (listing.image != null) ...[
                  SizedBox(
                    width: 72,
                    height: 72,
                    child: NetImage(listing.image, radius: NawyRadius.md, icon: Icons.apartment_rounded),
                  ),
                  const SizedBox(width: 12),
                ],
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        formatMoney(listing.price, locale: context.l10n.locale, empty: context.t('souq.priceOnRequest')),
                        style: NawyText.h4,
                      ),
                      Text(listing.title, style: NawyText.bodyMedium, maxLines: 2, overflow: TextOverflow.ellipsis),
                      if (listing.place != null && listing.place!.isNotEmpty) Text(listing.place!, style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                      const SizedBox(height: 4),
                      Wrap(
                        spacing: 8,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          if (listing.featured) Pill(context.t('souq.featured')),
                          TextButton(key: const ValueKey('map-listing-open'), onPressed: onOpen, child: Text(context.t('souq.viewDetails'))),
                        ],
                      ),
                    ],
                  ),
                ),
                IconButton(key: const ValueKey('map-listing-close'), tooltip: context.t('common.close'), onPressed: onClose, icon: const Icon(Icons.close_rounded)),
              ],
            ),
          ),
        ),
      );
}

/// Map mode of the Souq / Property list: loads `GET classifieds/map` with
/// the list's filters (and the "search this area" box) and shows the pins.
class ListingsMapView extends StatefulWidget {
  const ListingsMapView({super.key, required this.query, this.onShowList});

  /// The list's filters (`q`, `category`, `vertical`, `attr[purpose]`…).
  final Map<String, dynamic> query;
  final VoidCallback? onShowList;

  @override
  State<ListingsMapView> createState() => _ListingsMapViewState();
}

class _ListingsMapViewState extends State<ListingsMapView> {
  String? _bounds;
  late Future<List<MapListing>> _future = _load();

  Future<List<MapListing>> _load() async {
    final res = await AppScope.read(context).api.getRaw('classifieds/map', query: {...widget.query, 'bounds': _bounds});
    return mapListingsFromGeoJson(res.data);
  }

  void _reload({String? bounds}) => setState(() {
        _bounds = bounds;
        _future = _load();
      });

  @override
  Widget build(BuildContext context) => FutureBuilder<List<MapListing>>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const LoadingView();
          if (snap.hasError) return ErrorView(error: snap.error!, onRetry: () => _reload(bounds: _bounds));
          final listings = snap.data ?? const <MapListing>[];
          if (listings.isEmpty) {
            return SingleChildScrollView(
              child: EmptyView(
                key: const ValueKey('map-empty'),
                icon: Icons.map_outlined,
                title: context.t(_bounds == null ? 'souq.mapEmpty' : 'souq.mapAreaEmpty'),
                subtitle: _bounds == null ? context.t('souq.mapEmptyHint') : null,
                actionLabel: context.t(_bounds == null ? 'souq.listView' : 'souq.mapShowAll'),
                onAction: _bounds == null ? widget.onShowList : () => _reload(),
              ),
            );
          }
          return ListingsMap(
            listings: listings,
            onSearchArea: (b) => _reload(bounds: boundsQuery(b)),
          );
        },
      );
}

/// Pick an ad's location: tap the map to drop the pin, then confirm. Pops
/// with the [LatLng] (`latitude` / `longitude` of `POST classifieds/ads`).
class LocationPickerScreen extends StatefulWidget {
  const LocationPickerScreen({super.key, this.initial});

  final LatLng? initial;

  @override
  State<LocationPickerScreen> createState() => _LocationPickerScreenState();
}

class _LocationPickerScreenState extends State<LocationPickerScreen> {
  late LatLng? _point = widget.initial;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('souq.location'))),
        body: Stack(children: [
          FlutterMap(
            key: const ValueKey('location-map'),
            options: MapOptions(
              initialCenter: widget.initial ?? MapConfig.fallbackCenter,
              initialZoom: widget.initial == null ? 11 : 15,
              minZoom: 3,
              maxZoom: 18,
              interactionOptions: const InteractionOptions(flags: InteractiveFlag.all & ~InteractiveFlag.rotate),
              onTap: (_, point) => setState(() => _point = point),
            ),
            children: [
              TileLayer(urlTemplate: MapConfig.tileUrl, userAgentPackageName: MapConfig.userAgentPackageName, tileProvider: MapConfig.tileProvider?.call(), maxNativeZoom: 19),
              if (_point case final point?)
                MarkerLayer(rotate: false, markers: [
                  Marker(point: point, width: 40, height: 40, alignment: Alignment.topCenter, child: const Icon(Icons.location_on_rounded, key: ValueKey('location-pin'), size: 40, color: NawyColors.bgDanger)),
                ]),
              const _Attribution(),
            ],
          ),
          PositionedDirectional(
            top: 12,
            start: 12,
            end: 12,
            child: Material(
              color: NawyColors.bgSurface,
              borderRadius: BorderRadius.circular(NawyRadius.md),
              elevation: 2,
              child: Padding(padding: const EdgeInsets.all(10), child: Text(context.t('souq.locationHint'), style: NawyText.bodySm)),
            ),
          ),
          PositionedDirectional(
            start: 16,
            end: 16,
            bottom: 32,
            child: FilledButton(
              key: const ValueKey('location-confirm'),
              onPressed: _point == null ? null : () => Navigator.of(context).pop(_point),
              child: Text(context.t('souq.locationUse')),
            ),
          ),
        ]),
      );
}
