import 'package:flutter/material.dart';
import 'package:nawy_core/device.dart' show AttachmentPicker, PickedMedia, UploadRules;

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/uploads.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

/// Ratings summary with the 5→1 histogram.
class RatingSummary extends StatelessWidget {
  const RatingSummary({super.key, required this.summary, this.onStar});

  final Json summary;
  final ValueChanged<int>? onStar;

  @override
  Widget build(BuildContext context) {
    final count = summary.i('count') ?? 0;
    final histogram = summary['histogram'];
    int bucket(int star) {
      if (histogram is Map) return int.tryParse('${histogram['$star']}') ?? 0;
      if (histogram is List && histogram.length >= star) return int.tryParse('${histogram[star - 1]}') ?? 0;
      return 0;
    }

    return Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
      Column(children: [
        Text(summary.s('average') == null ? '—' : double.parse(summary.str('average')).toStringAsFixed(1), style: NawyText.display),
        Text(context.t('product.ratingsCount', {'count': count}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
      ]),
      const SizedBox(width: 20),
      Expanded(
        child: Column(children: [
          for (final star in const [5, 4, 3, 2, 1])
            InkWell(
              onTap: onStar == null ? null : () => onStar!(star),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(children: [
                  SizedBox(width: 24, child: Text('$star★', style: NawyText.caption)),
                  Expanded(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(3),
                      child: LinearProgressIndicator(
                        value: count == 0 ? 0 : bucket(star) / count,
                        minHeight: 6,
                        backgroundColor: NawyColors.borderDefault,
                        color: NawyColors.bgSuccess,
                      ),
                    ),
                  ),
                  SizedBox(width: 40, child: Text(count == 0 ? '0%' : '${(bucket(star) * 100 / count).round()}%', textAlign: TextAlign.end, style: NawyText.caption)),
                ]),
              ),
            ),
        ]),
      ),
    ]);
  }
}

class ReviewTile extends StatefulWidget {
  const ReviewTile({super.key, required this.review, this.mine = false});

  final Json review;

  /// The author's own list (`GET me/reviews`): status, no vote / report.
  final bool mine;

  @override
  State<ReviewTile> createState() => _ReviewTileState();
}

class _ReviewTileState extends State<ReviewTile> {
  late bool _voted = widget.review.b('voted_helpful');
  late int _count = widget.review.i('helpful_count') ?? 0;

  Future<void> _vote() async {
    if (!await requireSignIn(context) || !mounted) return;
    final api = AppScope.read(context).api;
    final id = widget.review.i('id');
    final res = await guarded(context, () => _voted ? api.delete('reviews/$id/helpful') : api.post('reviews/$id/helpful'));
    if (res != null && mounted) {
      final data = asJson(res.data);
      setState(() {
        _voted = data.b('voted_helpful', !_voted);
        _count = data.i('helpful_count') ?? _count;
      });
    }
  }

  /// `POST reviews/{id}/report` (QC-012): the review stays public until a
  /// moderator decides.
  Future<void> _report() async {
    if (!await requireSignIn(context) || !mounted) return;
    final app = AppScope.read(context);
    final report = await showNawySheet<Json>(context, title: context.t('reviews.reportTitle'), builder: (_) => const ReportReviewSheet());
    if (report == null || !mounted) return;
    await guarded(context, () => app.api.post('reviews/${widget.review.i('id')}/report', body: report), success: context.t('reviews.reported'));
  }

  @override
  Widget build(BuildContext context) {
    final r = widget.review;
    final reply = r.m('seller_reply');
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Text('★' * (r.i('rating') ?? 0), style: NawyText.label.copyWith(color: NawyColors.bgBrand)),
          Text('☆' * (5 - (r.i('rating') ?? 0)), style: NawyText.label.copyWith(color: NawyColors.borderDefault)),
          const SizedBox(width: 8),
          if (r.s('title') != null) Expanded(child: Text(r.str('title'), style: NawyText.bodyMedium, maxLines: 1, overflow: TextOverflow.ellipsis)) else const Spacer(),
          if (widget.mine) Pill.status(context, r.s('status')),
        ]),
        Text([r.s('author'), shortDate(context, r.s('created_at')), if (r.b('verified_purchase')) context.t('reviews.verified')].whereType<String>().join(' · '),
            style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
        if (r.s('body') != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(r.str('body'), style: NawyText.body)),
        if (r.l('photos').isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Wrap(spacing: 6, children: [for (final p in r.l('photos')) SizedBox(width: 56, height: 56, child: NetImage(p.s('url'), radius: NawyRadius.sm))]),
          ),
        if (reply != null && reply.s('body') != null)
          Container(
            key: const ValueKey('review-seller-reply'),
            margin: const EdgeInsetsDirectional.only(top: 8, start: 12),
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(color: NawyColors.bgPage, borderRadius: BorderRadius.circular(NawyRadius.md), border: const BorderDirectional(start: BorderSide(color: NawyColors.bgBrand, width: 3))),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text([context.t('reviews.sellerReply'), shortDate(context, reply.s('created_at'))].where((e) => e.isNotEmpty).join(' · '), style: NawyText.label),
              const SizedBox(height: 2),
              Text(reply.str('body'), style: NawyText.bodySm),
            ]),
          ),
        if (!widget.mine)
          Wrap(spacing: 4, children: [
            TextButton.icon(
              onPressed: _vote,
              icon: Icon(_voted ? Icons.thumb_up_rounded : Icons.thumb_up_outlined, size: 16),
              label: Text(context.t('reviews.helpful', {'count': _count})),
            ),
            TextButton.icon(
              key: ValueKey('review-report-${r.i('id')}'),
              onPressed: _report,
              style: TextButton.styleFrom(foregroundColor: NawyColors.textSecondary),
              icon: const Icon(Icons.flag_outlined, size: 16),
              label: Text(context.t('reviews.report')),
            ),
          ]),
      ]),
    );
  }
}

/// Reasons accepted by `POST reviews/{id}/report`.
const reviewReportReasons = ['spam', 'offensive', 'fake', 'irrelevant', 'personal_data', 'other'];

/// Pick a reason and an optional note; pops `{reason, note}`.
class ReportReviewSheet extends StatefulWidget {
  const ReportReviewSheet({super.key});

  @override
  State<ReportReviewSheet> createState() => _ReportReviewSheetState();
}

class _ReportReviewSheetState extends State<ReportReviewSheet> {
  String? _reason;
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(16, 0, 16, 16 + MediaQuery.viewInsetsOf(context).bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          RadioGroup<String>(
            groupValue: _reason,
            onChanged: (v) => setState(() => _reason = v),
            child: Column(children: [
              for (final reason in reviewReportReasons)
                RadioListTile<String>(key: ValueKey('report-$reason'), contentPadding: EdgeInsets.zero, value: reason, title: Text(context.t('reviews.reason.$reason'))),
            ]),
          ),
          TextField(controller: _note, maxLines: 2, maxLength: 500, decoration: InputDecoration(labelText: context.t('reviews.reportNote'))),
          const SizedBox(height: 8),
          FilledButton(
            key: const ValueKey('report-submit'),
            onPressed: _reason == null ? null : () => Navigator.of(context).pop(<String, dynamic>{'reason': _reason, if (_note.text.trim().isNotEmpty) 'note': _note.text.trim()}),
            child: Text(context.t('reviews.report')),
          ),
        ]),
      );
}

/// The customer's own reviews (`GET me/reviews`): `pending`, `published`,
/// `hidden` or `rejected`, with the store's reply once published.
class MyReviewsScreen extends StatelessWidget {
  const MyReviewsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: Text(context.t('reviews.mine')),
          actions: [IconButton(tooltip: context.t('reviews.write'), onPressed: () => Navigator.of(context).pushNamed(Routes.writeReview), icon: const Icon(Icons.rate_review_outlined))],
        ),
        body: AsyncBody<List<Json>>(
          load: () => AppScope.read(context).api.getList('me/reviews', query: {'per_page': 50}).then((r) => r.data),
          isEmpty: (l) => l.isEmpty,
          builder: (context, reviews, _) => ListView.separated(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            itemCount: reviews.length,
            separatorBuilder: (_, _) => const Divider(),
            itemBuilder: (context, i) {
              final r = reviews[i];
              return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                if ((r.m('product')?.s('title') ?? r.s('product_title')) != null)
                  Padding(padding: const EdgeInsets.only(top: 10), child: Text(r.m('product')?.s('title') ?? r.str('product_title'), style: NawyText.label)),
                ReviewTile(key: ValueKey('my-review-${r.i('id')}'), review: r, mine: true),
              ]);
            },
          ),
        ),
      );
}

/// PDP section: summary + top reviews + "See all".
class ReviewsPreview extends StatelessWidget {
  const ReviewsPreview({super.key, required this.slug, required this.title});

  final String slug;
  final String title;

  @override
  Widget build(BuildContext context) => OnceFuture<Json>(
        load: () => AppScope.read(context).api.getObject('products/${enc(slug)}/reviews', query: {'per_page': 3, 'sort': 'helpful'}),
        builder: (context, snap) {
          final page = snap.data;
          if (page == null) return const SizedBox.shrink();
          final reviews = page.l('reviews');
          return SectionCard(
            margin: const EdgeInsets.fromLTRB(0, 12, 0, 0),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(context.t('reviews.title'), style: NawyText.h4)),
                TextButton(onPressed: () => Navigator.of(context).pushNamed(Routes.reviews, arguments: {'slug': slug, 'title': title}), child: Text(context.t('common.viewAll'))),
              ]),
              RatingSummary(summary: page.m('summary') ?? const {}),
              if (reviews.isEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(context.t('reviews.none'), style: NawyText.bodySm.copyWith(color: NawyColors.textMuted))),
              for (final r in reviews) ReviewTile(review: r),
            ]),
          );
        },
      );
}

/// Figma "17 Reviews": sort, star filter, photos filter, helpful votes.
class ReviewsScreen extends StatefulWidget {
  const ReviewsScreen({super.key, required this.slug, this.title});

  final String slug;
  final String? title;

  @override
  State<ReviewsScreen> createState() => _ReviewsScreenState();
}

class _ReviewsScreenState extends State<ReviewsScreen> {
  String _sort = 'recent';
  int? _rating;
  bool _photos = false;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('reviews.title'))),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => Navigator.of(context).pushNamed(Routes.writeReview),
          icon: const Icon(Icons.rate_review_outlined),
          label: Text(context.t('reviews.write')),
        ),
        body: AsyncBody<Json>(
          key: ValueKey('$_sort-$_rating-$_photos'),
          load: () => AppScope.read(context).api.getObject('products/${enc(widget.slug)}/reviews', query: {'sort': _sort, 'rating': _rating, 'with_photos': _photos ? 1 : null, 'per_page': 50}),
          builder: (context, page, _) {
            final reviews = page.l('reviews');
            return ListView(padding: const EdgeInsets.all(16), children: [
              if (widget.title != null) Text(widget.title!, style: NawyText.bodyMedium, maxLines: 2),
              const SizedBox(height: 12),
              RatingSummary(summary: page.m('summary') ?? const {}, onStar: (s) => setState(() => _rating = _rating == s ? null : s)),
              const SizedBox(height: 12),
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final s in const ['recent', 'helpful', 'rating_desc', 'rating_asc'])
                  ChoiceChip(label: Text(context.t('reviews.sort.$s')), selected: _sort == s, onSelected: (_) => setState(() => _sort = s)),
                FilterChip(label: Text(context.t('reviews.withPhotos')), selected: _photos, onSelected: (v) => setState(() => _photos = v)),
                if (_rating != null) InputChip(label: Text('$_rating★'), onDeleted: () => setState(() => _rating = null)),
              ]),
              if (reviews.isEmpty) EmptyView(icon: Icons.reviews_outlined, title: context.t('reviews.none')),
              for (final r in reviews) ...[ReviewTile(review: r), const Divider()],
              const SizedBox(height: 72),
            ]);
          },
        ),
      );
}

/// Figma "19 Q&A".
class QuestionsScreen extends StatefulWidget {
  const QuestionsScreen({super.key, required this.slug});

  final String slug;

  @override
  State<QuestionsScreen> createState() => _QuestionsScreenState();
}

class _QuestionsScreenState extends State<QuestionsScreen> {
  final _body = TextEditingController();
  int _gen = 0;

  @override
  void dispose() {
    _body.dispose();
    super.dispose();
  }

  Future<void> _ask() async {
    final text = _body.text.trim();
    if (text.length < 5) {
      showToast(context, context.t('qa.tooShort'), error: true);
      return;
    }
    if (!await requireSignIn(context) || !mounted) return;
    final ok = await guarded(context, () => AppScope.read(context).api.post('products/${enc(widget.slug)}/questions', body: {'body': text}), success: context.t('qa.sent'));
    if (ok != null && mounted) {
      _body.clear();
      setState(() => _gen++);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('qa.title'))),
        body: Column(children: [
          Expanded(
            child: AsyncBody<List<Json>>(
              key: ValueKey(_gen),
              load: () => AppScope.read(context).api.getList('products/${enc(widget.slug)}/questions').then((r) => r.data),
              isEmpty: (q) => q.isEmpty,
              empty: EmptyView(icon: Icons.forum_outlined, title: context.t('qa.none'), subtitle: context.t('qa.noneHint')),
              builder: (context, questions, _) => ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: questions.length,
                separatorBuilder: (_, _) => const Divider(height: 24),
                itemBuilder: (context, i) {
                  final q = questions[i];
                  return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('Q: ${q.str('body')}', style: NawyText.bodyMedium),
                    Text([q.s('author'), shortDate(context, q.s('created_at'))].whereType<String>().join(' · '), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                    if (q.s('status') == 'pending') Pill(context.l10n.status('pending')),
                    for (final a in q.l('answers'))
                      Padding(
                        padding: const EdgeInsetsDirectional.only(top: 6, start: 12),
                        child: Text('A (${context.t('qa.by.${a.str('by', 'vendor')}')}): ${a.str('body')}', style: NawyText.body),
                      ),
                  ]);
                },
              ),
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(children: [
                Expanded(child: TextField(controller: _body, maxLength: 500, decoration: InputDecoration(hintText: context.t('qa.ask'), counterText: ''))),
                const SizedBox(width: 8),
                BusyButton(expand: false, label: context.t('qa.send'), onPressed: _ask),
              ]),
            ),
          ),
        ]),
      );
}

/// Figma "18 Write review": pick a delivered item (`me/reviewable-items`),
/// stars, title, text and up to 5 photos (`POST reviews`, `photos[]` as
/// multipart when photos are added; image_picker, owner-review D16).
class WriteReviewScreen extends StatefulWidget {
  const WriteReviewScreen({super.key, this.orderItemId});

  final int? orderItemId;

  @override
  State<WriteReviewScreen> createState() => _WriteReviewScreenState();
}

class _WriteReviewScreenState extends State<WriteReviewScreen> {
  late int? _item = widget.orderItemId;
  int _rating = 0;
  final _title = TextEditingController();
  final _body = TextEditingController();
  List<PickedMedia> _photos = const [];
  Map<String, String> _errors = {};

  @override
  void dispose() {
    _title.dispose();
    _body.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_item == null || _rating == 0) {
      setState(() => _errors = {'rating': context.t('reviews.pickStars')});
      return;
    }
    try {
      final body = {'order_item_id': _item, 'rating': _rating, 'title': _title.text.trim(), 'body': _body.text.trim()};
      final res = await postWithFiles(AppScope.read(context).api, 'reviews', body, field: 'photos', files: _photos);
      if (!mounted) return;
      showToast(context, context.t(asJson(res.data).s('status') == 'published' ? 'reviews.published' : 'reviews.submitted'));
      Navigator.of(context).pop();
    } on ApiException catch (e) {
      setState(() => _errors = {...e.fieldErrors, if (e.fieldErrors.isEmpty) 'form': errorMessage(context, e)});
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('reviews.write'))),
        body: AsyncBody<List<Json>>(
          load: () => AppScope.read(context).api.getList('me/reviewable-items').then((r) => r.data),
          isEmpty: (items) => items.isEmpty && widget.orderItemId == null,
          empty: EmptyView(icon: Icons.rate_review_outlined, title: context.t('reviews.nothingToReview')),
          builder: (context, items, _) => ListView(padding: const EdgeInsets.all(16), children: [
            Text(context.t('reviews.which'), style: NawyText.h4),
            for (final item in items)
              RadioMenuButtonTile(
                selected: _item == item.i('order_item_id'),
                onTap: () => setState(() => _item = item.i('order_item_id')),
                title: item.m('product')?.s('title') ?? item.str('title'),
                image: item.m('image')?.s('url'),
              ),
            const SizedBox(height: 16),
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              for (var s = 1; s <= 5; s++)
                IconButton(
                  key: ValueKey('star-$s'),
                  tooltip: '$s',
                  onPressed: () => setState(() => _rating = s),
                  icon: Icon(s <= _rating ? Icons.star_rounded : Icons.star_border_rounded, size: 36, color: NawyColors.bgBrand),
                ),
            ]),
            if (_errors['rating'] != null) Text(_errors['rating']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger), textAlign: TextAlign.center),
            const SizedBox(height: 12),
            TextField(controller: _title, decoration: InputDecoration(labelText: context.t('reviews.reviewTitle'), errorText: _errors['title'])),
            const SizedBox(height: 12),
            TextField(controller: _body, maxLines: 5, decoration: InputDecoration(labelText: context.t('reviews.body'), errorText: _errors['body'])),
            const SizedBox(height: 16),
            Text(context.t('reviews.photos'), style: NawyText.label),
            const SizedBox(height: 8),
            AttachmentPicker(files: _photos, rules: UploadRules.reviewPhotos, t: context.t, onChanged: (files) => setState(() => _photos = files)),
            if (_errors['photos'] != null) Text(_errors['photos']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
            if (_errors['form'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_errors['form']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
            const SizedBox(height: 16),
            BusyButton(label: context.t('reviews.submit'), onPressed: _submit),
          ]),
        ),
      );
}

/// Selectable row with a thumbnail (single choice lists).
class RadioMenuButtonTile extends StatelessWidget {
  const RadioMenuButtonTile({super.key, required this.selected, required this.onTap, required this.title, this.image, this.subtitle});

  final bool selected;
  final VoidCallback onTap;
  final String title;
  final String? subtitle;
  final String? image;

  @override
  Widget build(BuildContext context) => ListTile(
        onTap: onTap,
        contentPadding: EdgeInsets.zero,
        leading: image == null ? null : SizedBox(width: 44, height: 44, child: NetImage(image, radius: NawyRadius.sm)),
        title: Text(title, maxLines: 2),
        subtitle: subtitle == null ? null : Text(subtitle!),
        trailing: Icon(selected ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded, color: selected ? NawyColors.textPrimary : NawyColors.textMuted),
      );
}
