import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';

/// Trip photos (QC-060, Figma `69:6093`): `gallery[{url, alt}]`, else the
/// cover image. The cover always comes first.
List<Json> tripPhotos(Json package) {
  final cover = package.m('image')?.s('url') ?? package.s('cover_url');
  final photos = <Json>[
    if (cover != null) {'url': cover, 'alt': package.s('title')},
    for (final g in package.l('gallery'))
      if (g.s('url') != null && g.s('url') != cover) g,
  ];
  return photos;
}

/// "4.6 ★ · 1,240 travellers · 310 bookings · 12 trips by this agency" from
/// `stats{rating, travellers, bookings, agency_trips}`; null when empty.
String? tripStatsLine(BuildContext context, Json? stats) {
  if (stats == null) return null;
  final rating = stats.d('rating');
  final parts = [
    if (rating != null && rating > 0) '${rating.toStringAsFixed(1)} ★',
    if ((stats.i('travellers') ?? 0) > 0) context.t('travel.stats.travellers', {'n': compactCount(stats.i('travellers')!)}),
    if ((stats.i('bookings') ?? 0) > 0) context.t('travel.stats.bookings', {'n': compactCount(stats.i('bookings')!)}),
    if ((stats.i('agency_trips') ?? 0) > 0) context.t('travel.stats.agencyTrips', {'n': stats.i('agency_trips')}),
  ];
  return parts.isEmpty ? null : parts.join(' · ');
}

/// 1 + 2 gallery: a large photo and two small ones beside it ("+N" on the
/// last). Tapping opens [TripGalleryViewer].
class TripGallery extends StatelessWidget {
  const TripGallery({super.key, required this.package});

  final Json package;

  void _open(BuildContext context, List<Json> photos, int index) => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => TripGalleryViewer(photos: photos, initial: index)),
      );

  @override
  Widget build(BuildContext context) {
    final photos = tripPhotos(package);
    if (photos.length < 3) {
      return AspectRatio(
        aspectRatio: 16 / 9,
        child: InkWell(
          key: const ValueKey('trip-gallery-0'),
          onTap: photos.isEmpty ? null : () => _open(context, photos, 0),
          child: NetImage(photos.firstOrNull?.s('url'), icon: Icons.flight_takeoff_rounded),
        ),
      );
    }
    Widget tile(int i, {Widget? overlay}) => InkWell(
          key: ValueKey('trip-gallery-$i'),
          onTap: () => _open(context, photos, i),
          child: Semantics(
            image: true,
            label: photos[i].s('alt'),
            child: Stack(fit: StackFit.expand, children: [
              NetImage(photos[i].s('url'), icon: Icons.flight_takeoff_rounded),
              ?overlay,
            ]),
          ),
        );
    final more = photos.length - 3;
    return AspectRatio(
      aspectRatio: 16 / 9,
      child: Row(children: [
        Expanded(flex: 2, child: tile(0)),
        const SizedBox(width: 2),
        Expanded(
          child: Column(children: [
            Expanded(child: tile(1)),
            const SizedBox(height: 2),
            Expanded(
              child: tile(
                2,
                overlay: more > 0
                    ? Container(
                        color: Colors.black45,
                        alignment: Alignment.center,
                        child: Text('+$more', style: NawyText.h3.copyWith(color: NawyColors.textInverse)),
                      )
                    : null,
              ),
            ),
          ]),
        ),
      ]),
    );
  }
}

/// Full-screen swipeable photos with a "2 / 7" counter.
class TripGalleryViewer extends StatefulWidget {
  const TripGalleryViewer({super.key, required this.photos, this.initial = 0});

  final List<Json> photos;
  final int initial;

  @override
  State<TripGalleryViewer> createState() => _TripGalleryViewerState();
}

class _TripGalleryViewerState extends State<TripGalleryViewer> {
  late final _controller = PageController(initialPage: widget.initial);
  late int _index = widget.initial;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: NawyColors.bgInverse,
        appBar: AppBar(
          backgroundColor: NawyColors.bgInverse,
          foregroundColor: NawyColors.textInverse,
          title: Text(context.t('travel.photoOf', {'n': _index + 1, 'total': widget.photos.length}), key: const ValueKey('trip-gallery-counter')),
        ),
        body: PageView.builder(
          key: const ValueKey('trip-gallery-pager'),
          controller: _controller,
          itemCount: widget.photos.length,
          onPageChanged: (i) => setState(() => _index = i),
          itemBuilder: (_, i) => InteractiveViewer(
            child: Center(child: NetImage(widget.photos[i].s('url'), fit: BoxFit.contain, background: NawyColors.bgInverse, icon: Icons.flight_takeoff_rounded)),
          ),
        ),
      );
}
