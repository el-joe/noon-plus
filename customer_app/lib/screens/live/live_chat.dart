import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';

/// Poll and heartbeat intervals (QC-040); tests may shorten them.
class LiveTimings {
  static Duration comments = const Duration(seconds: 5);
  static Duration presence = const Duration(seconds: 30);
}

/// A viewer id for this app session, so the heartbeat counts one viewer per
/// device (guests too) without any personal data.
class LiveViewer {
  static final String id = () {
    final random = Random.secure();
    return 'app-${List.generate(12, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0')).join()}';
  }();
}

/// "👁 1.2K" watching now.
class ViewerCount extends StatelessWidget {
  const ViewerCount({super.key, required this.count});

  final int count;

  @override
  Widget build(BuildContext context) => Semantics(
        label: context.t('live.viewers', {'n': count}),
        child: ExcludeSemantics(
          child: Pill(compactCount(count), background: Colors.black54, foreground: NawyColors.textInverse, icon: Icons.visibility_rounded),
        ),
      );
}

/// Live comments (QC-040): `GET comments?after_id=` (oldest first) polled
/// every 5 s while live, and a box to post (sign-in, ≤ 300 characters).
/// A comment caught by the review filter comes back `held`: only its author
/// sees it here, marked as waiting for review.
class LiveChat extends StatefulWidget {
  const LiveChat({super.key, required this.slug, required this.live, this.onMeta});

  final String slug;
  final bool live;

  /// `meta.viewers` / `meta.likes` of each poll.
  final void Function(int? viewers, int? likes)? onMeta;

  @override
  State<LiveChat> createState() => LiveChatState();
}

class LiveChatState extends State<LiveChat> {
  static const maxLength = 300;

  final List<Json> comments = [];
  final List<Json> held = [];
  final _body = TextEditingController();
  Timer? _timer;
  int? _lastId;
  bool _loaded = false;
  bool _sending = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
    if (widget.live) _timer = Timer.periodic(LiveTimings.comments, (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _body.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final res = await AppScope.read(context).api.get('live-streams/${enc(widget.slug)}/comments', query: {'after_id': _lastId});
      if (!mounted) return;
      final data = asJsonList(res.data);
      setState(() {
        final seen = comments.map((c) => c['id']).toSet();
        comments.addAll(data.where((c) => !seen.contains(c['id'])));
        if (comments.length > 200) comments.removeRange(0, comments.length - 200);
        if (data.isNotEmpty) _lastId = data.last.i('id') ?? _lastId;
        _loaded = true;
      });
      widget.onMeta?.call(res.meta.i('viewers'), res.meta.i('likes'));
    } catch (_) {
      // Keep the thread; the next poll retries.
      if (mounted && !_loaded) setState(() => _loaded = true);
    }
  }

  Future<void> _send() async {
    final text = _body.text.trim();
    if (text.isEmpty || _sending) return;
    if (!await requireSignIn(context) || !mounted) return;
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final res = await AppScope.read(context).api.post('live-streams/${enc(widget.slug)}/comments', body: {'body': text});
      final comment = asJson(res.data);
      if (!mounted) return;
      setState(() {
        _body.clear();
        if (comment.s('status') == 'held') {
          held.add(comment);
        } else if (!comments.any((c) => c['id'] == comment['id'])) {
          comments.add(comment);
        }
      });
    } on ApiException catch (e) {
      // 422 `not_allowed` once the stream is no longer live.
      if (mounted) setState(() => _error = errorMessage(context, e));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Widget _row(BuildContext context, Json c, {bool isHeld = false}) => Padding(
        key: ValueKey('${isHeld ? 'held' : 'comment'}-${c['id']}'),
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text.rich(TextSpan(children: [
            TextSpan(text: '${c.s('author') ?? c.m('author')?.s('name') ?? context.t('live.someone')}  ', style: NawyText.label.copyWith(color: NawyColors.textMuted)),
            TextSpan(text: c.str('body'), style: NawyText.bodySm.copyWith(color: NawyColors.textInverse)),
          ])),
          if (isHeld) Text(context.t('live.commentHeld'), style: NawyText.caption.copyWith(color: NawyColors.bgBrand)),
        ]),
      );

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(context.t('live.comments'), style: NawyText.h4.copyWith(color: NawyColors.textInverse)),
          const SizedBox(height: 6),
          ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 240),
            child: _loaded && comments.isEmpty && held.isEmpty
                ? Text(context.t(widget.live ? 'live.noComments' : 'live.noCommentsEnded'), style: NawyText.bodySm.copyWith(color: NawyColors.textMuted))
                : ListView(
                    key: const ValueKey('live-comments'),
                    shrinkWrap: true,
                    reverse: true,
                    children: [
                      for (final c in held.reversed) _row(context, c, isHeld: true),
                      for (final c in comments.reversed) _row(context, c),
                    ],
                  ),
          ),
          if (widget.live) ...[
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: TextField(
                  key: const ValueKey('live-comment-input'),
                  controller: _body,
                  maxLength: maxLength,
                  minLines: 1,
                  maxLines: 3,
                  style: NawyText.body.copyWith(color: NawyColors.textInverse),
                  decoration: InputDecoration(
                    hintText: context.t('live.commentHint'),
                    counterText: '',
                    filled: true,
                    fillColor: Colors.white12,
                    hintStyle: NawyText.body.copyWith(color: NawyColors.textMuted),
                  ),
                  onSubmitted: (_) => _send(),
                ),
              ),
              IconButton(
                key: const ValueKey('live-comment-send'),
                tooltip: context.t('live.send'),
                onPressed: _sending ? null : _send,
                icon: const Icon(Icons.send_rounded, color: NawyColors.bgBrand),
              ),
            ]),
          ],
          if (_error != null) Text(_error!, key: const ValueKey('live-comment-error'), style: NawyText.bodySm.copyWith(color: NawyColors.bgDanger)),
        ]),
      );
}
