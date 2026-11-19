import 'package:flutter/material.dart';
import 'package:nawy_core/device.dart' show AttachmentPicker, PickedMedia, UploadRules;

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/uploads.dart';
import '../../core/platform/platform_bridge.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/paged_list.dart';
import '../../ui/widgets/states.dart';

/// Support tickets (QC-007, lifecycle §9.3): `support/tickets`.
const supportStatuses = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];

/// Account → Help & support: the customer's tickets with a status filter.
class SupportListScreen extends StatefulWidget {
  const SupportListScreen({super.key});

  @override
  State<SupportListScreen> createState() => _SupportListScreenState();
}

class _SupportListScreenState extends State<SupportListScreen> {
  String? _status;
  int _gen = 0;

  Future<void> _new() async {
    final created = await Navigator.of(context).pushNamed(Routes.supportNew);
    if (created != null && mounted) setState(() => _gen++);
  }

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('support.title'))),
      floatingActionButton: FloatingActionButton.extended(
        key: const ValueKey('support-new'),
        onPressed: _new,
        icon: const Icon(Icons.add_rounded),
        label: Text(context.t('support.new')),
      ),
      body: Column(children: [
        SizedBox(
          height: 48,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6), children: [
            for (final status in [null, ...supportStatuses])
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: ChoiceChip(
                  key: ValueKey('support-filter-${status ?? 'all'}'),
                  selected: _status == status,
                  label: Text(status == null ? context.t('support.all') : context.t('support.status.$status')),
                  onSelected: (_) => setState(() {
                    _status = status;
                    _gen++;
                  }),
                ),
              ),
          ]),
        ),
        Expanded(
          child: PagedList(
            key: ValueKey('support-$_status-$_gen'),
            load: (page) => api.getList('support/tickets', query: {'status': _status, 'page': page}),
            padding: const EdgeInsets.only(top: 4, bottom: 88),
            empty: EmptyView(icon: Icons.support_agent_rounded, title: context.t('support.empty'), subtitle: context.t('support.emptyHint')),
            itemBuilder: (context, ticket) => _TicketTile(
              ticket: ticket,
              onTap: () async {
                await Navigator.of(context).pushNamed(Routes.supportTicket, arguments: {'number': ticket.str('number')});
                if (mounted) setState(() => _gen++);
              },
            ),
          ),
        ),
      ]),
    );
  }
}

class _TicketTile extends StatelessWidget {
  const _TicketTile({required this.ticket, required this.onTap});

  final Json ticket;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        key: ValueKey('ticket-${ticket.str('number')}'),
        onTap: onTap,
        child: SectionCard(
          margin: const EdgeInsets.fromLTRB(16, 0, 16, 10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(ticket.str('subject'), style: NawyText.bodyMedium, maxLines: 2, overflow: TextOverflow.ellipsis)),
              const SizedBox(width: 8),
              SupportStatusPill(ticket: ticket),
            ]),
            const SizedBox(height: 4),
            Text(
              [ticket.str('number'), ticket.s('category_label') ?? ticket.str('category'), shortDate(context, ticket.s('last_message_at') ?? ticket.s('created_at'))].where((e) => e.isNotEmpty).join(' · '),
              style: NawyText.caption.copyWith(color: NawyColors.textSecondary),
            ),
            if (ticket.s('status') == 'waiting_customer')
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(context.t('support.waitingYou'), style: NawyText.caption.copyWith(color: NawyColors.textBrand)),
              ),
          ]),
        ),
      );
}

/// The ticket status, using the API's translated label when sent.
class SupportStatusPill extends StatelessWidget {
  const SupportStatusPill({super.key, required this.ticket});

  final Json ticket;

  @override
  Widget build(BuildContext context) {
    final status = ticket.str('status');
    final (bg, fg) = switch (status) {
      'resolved' || 'closed' => (NawyColors.bgSuccessSubtle, NawyColors.textSuccess),
      'waiting_customer' => (NawyColors.bgWarningSubtle, NawyColors.textBrand),
      _ => (NawyColors.bgInfoSubtle, NawyColors.textLink),
    };
    final known = supportStatuses.contains(status);
    return Pill(known ? context.t('support.status.$status') : (ticket.s('status_label') ?? context.l10n.status(status)), background: bg, foreground: fg);
  }
}

/// New ticket: category (from `support/categories`), priority, subject,
/// message, the order / shipment it is about and up to 5 photos
/// (`attachments[]` as multipart; image_picker, owner-review D16). PDFs,
/// which the API also takes, need a document picker (not approved).
class SupportNewScreen extends StatefulWidget {
  const SupportNewScreen({super.key, this.order, this.shipment});

  final String? order;
  final String? shipment;

  @override
  State<SupportNewScreen> createState() => _SupportNewScreenState();
}

class _SupportNewScreenState extends State<SupportNewScreen> {
  final _subject = TextEditingController();
  final _message = TextEditingController();
  late final _order = TextEditingController(text: widget.order ?? '');
  String? _category;
  String _priority = 'normal';
  List<PickedMedia> _files = const [];
  Map<String, String> _errors = const {};

  @override
  void dispose() {
    _subject.dispose();
    _message.dispose();
    _order.dispose();
    super.dispose();
  }

  Map<String, String> _validate() {
    final errors = <String, String>{};
    if (_category == null) errors['category'] = context.t('support.pickCategory');
    final subject = _subject.text.trim().length;
    if (subject < 3 || subject > 160) errors['subject'] = context.t('support.subjectLength');
    final message = _message.text.trim().length;
    if (message < 10 || message > 5000) errors['message'] = context.t('support.messageLength');
    return errors;
  }

  Future<void> _submit() async {
    final errors = _validate();
    setState(() => _errors = errors);
    if (errors.isNotEmpty) return;
    final api = AppScope.read(context).api;
    try {
      final res = await postWithFiles(api, 'support/tickets', field: 'attachments', files: _files, {
        'category': _category,
        'priority': _priority,
        'subject': _subject.text.trim(),
        'message': _message.text.trim(),
        if (_order.text.trim().isNotEmpty) 'order': _order.text.trim(),
        'shipment': ?widget.shipment,
      });
      final ticket = asJson(res.data);
      if (!mounted) return;
      showToast(context, context.t('support.created', {'number': ticket.str('number')}));
      Navigator.of(context).pushReplacementNamed(Routes.supportTicket, arguments: {'number': ticket.str('number')}, result: ticket);
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.fieldErrors.isNotEmpty) {
        setState(() => _errors = e.fieldErrors);
      } else {
        showToast(context, errorMessage(context, e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('support.new'))),
      body: AsyncBody<Json>(
        load: () => api.getObject('support/categories'),
        builder: (context, data, _) {
          final categories = data.l('categories');
          final priorities = [
            for (final p in (data['priorities'] is List ? data['priorities'] as List : const ['low', 'normal', 'high']))
              p is Map ? asJson(p).str('value') : '$p',
          ];
          return ListView(padding: const EdgeInsets.all(16), children: [
            DropdownButtonFormField<String>(
              key: const ValueKey('support-category'),
              isExpanded: true,
              initialValue: _category,
              decoration: InputDecoration(labelText: context.t('support.category'), errorText: _errors['category']),
              items: [for (final c in categories) DropdownMenuItem(value: c.str('value'), child: Text(c.str('label', c.str('value'))))],
              onChanged: (v) => setState(() => _category = v),
            ),
            const SizedBox(height: 12),
            Text(context.t('support.priority'), style: NawyText.label),
            Wrap(spacing: 8, children: [
              for (final p in priorities)
                ChoiceChip(
                  key: ValueKey('support-priority-$p'),
                  selected: _priority == p,
                  label: Text(context.t('support.priority.$p')),
                  onSelected: (_) => setState(() => _priority = p),
                ),
            ]),
            const SizedBox(height: 12),
            TextField(
              key: const ValueKey('support-subject'),
              controller: _subject,
              maxLength: 160,
              decoration: InputDecoration(labelText: context.t('support.subject'), errorText: _errors['subject']),
            ),
            TextField(
              key: const ValueKey('support-message'),
              controller: _message,
              minLines: 4,
              maxLines: 10,
              maxLength: 5000,
              decoration: InputDecoration(labelText: context.t('support.message'), errorText: _errors['message']),
            ),
            TextField(
              key: const ValueKey('support-order'),
              controller: _order,
              decoration: InputDecoration(labelText: context.t('support.order'), errorText: _errors['order'] ?? _errors['shipment']),
            ),
            const SizedBox(height: 12),
            Text(context.t('support.attachPhotos'), style: NawyText.label),
            const SizedBox(height: 8),
            AttachmentPicker(
              key: const ValueKey('support-attachments'),
              files: _files,
              rules: UploadRules.supportAttachments,
              t: context.t,
              onChanged: (files) => setState(() => _files = files),
            ),
            if (_errors['attachments'] != null) Text(_errors['attachments']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
            const SizedBox(height: 16),
            BusyButton(key: const ValueKey('support-submit'), label: context.t('support.send'), onPressed: _submit),
          ]);
        },
      ),
    );
  }
}

/// Ticket detail: thread, reply, reopen (within the window) and rating.
class SupportTicketScreen extends StatefulWidget {
  const SupportTicketScreen({super.key, required this.number});

  final String number;

  @override
  State<SupportTicketScreen> createState() => _SupportTicketScreenState();
}

class _SupportTicketScreenState extends State<SupportTicketScreen> {
  final _reply = TextEditingController();
  List<PickedMedia> _replyFiles = const [];
  bool _attaching = false;
  int _gen = 0;
  String? _replyError;

  String get _path => 'support/tickets/${enc(widget.number)}';

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  void _reload() => setState(() => _gen++);

  /// Run a ticket action; 422 refusals (`not_allowed` / `transition_refused`)
  /// show the API message and reload the ticket.
  Future<bool> _act(Future<Object?> Function() action, {String? success}) async {
    try {
      await action();
      if (mounted && success != null) showToast(context, success);
      _reload();
      return true;
    } on ApiException catch (e) {
      if (!mounted) return false;
      showToast(context, errorMessage(context, e), error: true);
      if (e.status == 422 && e.fieldErrors.isEmpty) _reload();
      return false;
    }
  }

  Future<void> _send() async {
    final body = _reply.text.trim();
    if (body.length < 2) {
      setState(() => _replyError = context.t('support.replyTooShort'));
      return;
    }
    setState(() => _replyError = null);
    final api = AppScope.read(context).api;
    if (await _act(() => postWithFiles(api, '$_path/messages', {'body': body}, field: 'attachments', files: _replyFiles), success: context.t('support.replySent'))) {
      _reply.clear();
      setState(() {
        _replyFiles = const [];
        _attaching = false;
      });
    }
  }

  Future<void> _reopen() async {
    final message = await showDialog<String>(context: context, builder: (_) => const _ReopenDialog());
    if (message == null || !mounted) return;
    final api = AppScope.read(context).api;
    await _act(() => api.post('$_path/reopen', body: {if (message.isNotEmpty) 'message': message}), success: context.t('support.reopened'));
  }

  Future<void> _rate(int score, String comment) async {
    final api = AppScope.read(context).api;
    await _act(() => api.post('$_path/rating', body: {'rating': score, if (comment.isNotEmpty) 'comment': comment}), success: context.t('support.rated'));
  }

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    return Scaffold(
      appBar: AppBar(title: Text(widget.number)),
      body: AsyncBody<Json>(
        key: ValueKey(_gen),
        load: () => api.getObject(_path),
        builder: (context, ticket, reload) {
          final rating = ticket.m('rating');
          return Column(children: [
            Expanded(
              child: RefreshIndicator(
                onRefresh: reload,
                child: ListView(padding: const EdgeInsets.only(top: 12, bottom: 16), children: [
                  SectionCard(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Expanded(child: Text(ticket.str('subject'), style: NawyText.h4)),
                        SupportStatusPill(ticket: ticket),
                      ]),
                      const SizedBox(height: 6),
                      InfoRow(context.t('support.category'), ticket.s('category_label') ?? ticket.str('category')),
                      InfoRow(context.t('support.priority'), context.t('support.priority.${ticket.str('priority', 'normal')}')),
                      if (ticket.s('order') != null) InfoRow(context.t('support.order'), [ticket.s('order'), ticket.s('shipment')].whereType<String>().join(' · ')),
                      InfoRow(context.t('support.opened'), shortDate(context, ticket.s('created_at'), time: true)),
                      if (ticket.s('reply_expected_by') != null && ticket.s('first_responded_at') == null)
                        InfoRow(context.t('support.replyBy'), shortDate(context, ticket.s('reply_expected_by'), time: true)),
                    ]),
                  ),
                  for (final message in ticket.l('messages')) _Bubble(message: message),
                  if (ticket.s('status') == 'resolved' && !ticket.b('can_reply'))
                    _Note(text: context.t(ticket.b('can_reopen') ? 'support.resolvedHint' : 'support.resolvedNoReopen', {'date': shortDate(context, ticket.s('reopen_until'))})),
                  if (ticket.s('status') == 'closed') _Note(text: context.t('support.closedHint')),
                  if (ticket.b('can_reopen'))
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                      child: OutlinedButton.icon(key: const ValueKey('support-reopen'), onPressed: _reopen, icon: const Icon(Icons.replay_rounded), label: Text(context.t('support.reopen'))),
                    ),
                  if (ticket.b('can_rate')) _RateCard(onRate: _rate),
                  if (rating != null)
                    SectionCard(
                      key: const ValueKey('support-rating'),
                      title: context.t('support.yourRating'),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          for (var i = 1; i <= 5; i++) Icon(i <= (rating.i('score') ?? 0) ? Icons.star_rounded : Icons.star_border_rounded, color: NawyColors.bgBrand),
                        ]),
                        if (rating.s('comment') != null) Text(rating.str('comment'), style: NawyText.bodySm),
                      ]),
                    ),
                ]),
              ),
            ),
            if (ticket.b('can_reply'))
              SafeArea(
                top: false,
                child: Container(
                  padding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
                  decoration: const BoxDecoration(color: NawyColors.bgSurface, border: Border(top: BorderSide(color: NawyColors.borderDefault))),
                  child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  if (_attaching)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: AttachmentPicker(files: _replyFiles, rules: UploadRules.supportAttachments, t: context.t, onChanged: (files) => setState(() => _replyFiles = files)),
                    ),
                  Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                    IconButton(
                      key: const ValueKey('support-reply-attach'),
                      tooltip: context.t('support.attachPhotos'),
                      onPressed: () => setState(() => _attaching = !_attaching),
                      icon: Badge(isLabelVisible: _replyFiles.isNotEmpty, label: Text('${_replyFiles.length}'), child: const Icon(Icons.attach_file_rounded)),
                    ),
                    Expanded(
                      child: TextField(
                        key: const ValueKey('support-reply'),
                        controller: _reply,
                        minLines: 1,
                        maxLines: 5,
                        maxLength: 5000,
                        decoration: InputDecoration(isDense: true, hintText: context.t('support.replyHint'), errorText: _replyError, counterText: ''),
                      ),
                    ),
                    IconButton(key: const ValueKey('support-reply-send'), tooltip: context.t('support.send'), onPressed: _send, icon: const Icon(Icons.send_rounded)),
                  ]),
                  ]),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.message});

  final Json message;

  @override
  Widget build(BuildContext context) {
    final mine = message.b('mine') || message.s('from') == 'requester';
    return Align(
      alignment: mine ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
      child: Container(
        key: ValueKey('support-message-${message.str('id')}'),
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.8),
        margin: const EdgeInsets.fromLTRB(16, 0, 16, 10),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: mine ? NawyColors.bgBrandSubtle : NawyColors.bgSurface,
          borderRadius: BorderRadius.circular(NawyRadius.card),
          border: Border.all(color: NawyColors.borderDefault),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(context.t(mine ? 'support.you' : 'support.agent'), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
          const SizedBox(height: 2),
          Text(message.str('body'), style: NawyText.body),
          for (final file in message.l('attachments'))
            InkWell(
              onTap: () => PlatformBridge.openUrl(file.str('url')),
              child: Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  const Icon(Icons.attach_file_rounded, size: 16, color: NawyColors.textLink),
                  const SizedBox(width: 4),
                  Flexible(child: Text(file.str('name'), style: NawyText.caption.copyWith(color: NawyColors.textLink))),
                ]),
              ),
            ),
          const SizedBox(height: 4),
          Text(shortDate(context, message.s('created_at'), time: true), style: NawyText.caption.copyWith(color: NawyColors.textMuted)),
        ]),
      ),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.fromLTRB(16, 0, 16, 10),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: NawyColors.bgInfoSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
        child: Text(text, style: NawyText.bodySm),
      );
}

class _RateCard extends StatefulWidget {
  const _RateCard({required this.onRate});

  final Future<void> Function(int score, String comment) onRate;

  @override
  State<_RateCard> createState() => _RateCardState();
}

class _RateCardState extends State<_RateCard> {
  final _comment = TextEditingController();
  int _score = 0;

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SectionCard(
        key: const ValueKey('support-rate'),
        title: context.t('support.rateTitle'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            for (var i = 1; i <= 5; i++)
              IconButton(
                key: ValueKey('support-star-$i'),
                tooltip: '$i',
                onPressed: () => setState(() => _score = i),
                icon: Icon(i <= _score ? Icons.star_rounded : Icons.star_border_rounded, color: NawyColors.bgBrand, size: 30),
              ),
          ]),
          TextField(controller: _comment, maxLines: 2, decoration: InputDecoration(labelText: context.t('support.rateComment'))),
          const SizedBox(height: 8),
          BusyButton(
            key: const ValueKey('support-rate-send'),
            label: context.t('support.rateSend'),
            onPressed: _score == 0 ? null : () => widget.onRate(_score, _comment.text.trim()),
          ),
        ]),
      );
}

/// Reopen with an optional note; pops the note (empty for none) or null.
class _ReopenDialog extends StatefulWidget {
  const _ReopenDialog();

  @override
  State<_ReopenDialog> createState() => _ReopenDialogState();
}

class _ReopenDialogState extends State<_ReopenDialog> {
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(context.t('support.reopenTitle')),
        content: TextField(key: const ValueKey('support-reopen-note'), controller: _note, maxLines: 3, decoration: InputDecoration(labelText: context.t('support.reopenNote'))),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.t('common.cancel'))),
          TextButton(key: const ValueKey('support-reopen-confirm'), onPressed: () => Navigator.of(context).pop(_note.text.trim()), child: Text(context.t('support.reopen'))),
        ],
      );
}
