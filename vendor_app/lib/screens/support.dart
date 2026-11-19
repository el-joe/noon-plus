import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/vendor_api.dart';

/// "dd/MM/yyyy HH:mm" in the user's locale, or null.
String? _when(BuildContext context, DateTime? at) {
  if (at == null) return null;
  final ml = MaterialLocalizations.of(context);
  return '${ml.formatShortDate(at)} ${ml.formatTimeOfDay(TimeOfDay.fromDateTime(at))}';
}

/// Runs a ticket action; on failure shows the API message (422
/// `transition_refused` says why) and, when refused, calls [onRefused] so
/// the ticket reloads. Returns the result or null.
Future<T?> _ticketAction<T>(BuildContext context, Future<T> Function() action, {String? success, VoidCallback? onRefused}) async {
  try {
    final result = await action();
    if (success != null && context.mounted) showSnack(context, success);
    return result;
  } on ApiException catch (e) {
    if (!context.mounted) return null;
    showSnack(context, apiErrorMessage(context, e));
    if (e.isTransitionRefused) onRefused?.call();
  }
  return null;
}

/// Support (QC-007): the store team's tickets with a status filter, newest
/// activity first, paged by `meta.last_page`. Reachable from Settings and
/// from the Seller Agreement screen (works before the agreement is signed).
class SupportListScreen extends StatefulWidget {
  const SupportListScreen({super.key});

  @override
  State<SupportListScreen> createState() => _SupportListScreenState();
}

class _SupportListScreenState extends State<SupportListScreen> {
  String? _status;
  final List<SupportTicket> _rows = [];
  int _page = 0;
  bool _hasMore = true;
  bool _loading = false;
  Object? _error;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _more();
  }

  Future<void> _more({bool reset = false}) async {
    if (_loading && !reset) return;
    final generation = reset ? ++_generation : _generation;
    setState(() {
      _loading = true;
      _error = null;
      if (reset) {
        _rows.clear();
        _page = 0;
        _hasMore = true;
      }
    });
    try {
      final res = await VendorApi(context.api).supportTickets(status: _status, page: _page + 1);
      if (!mounted || generation != _generation) return;
      setState(() {
        _rows.addAll(res.data.map(SupportTicket.new));
        _page = res.page;
        _hasMore = res.hasMore;
      });
    } catch (e) {
      if (mounted && generation == _generation) setState(() => _error = e);
    } finally {
      if (mounted && generation == _generation) setState(() => _loading = false);
    }
  }

  void _filter(String? status) {
    if (status == _status) return;
    _status = status;
    _more(reset: true);
  }

  Future<void> _open(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    if (mounted) _more(reset: true);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: Text(context.t('support.title')),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(48),
            child: SizedBox(
              height: 48,
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                child: Row(children: [
                  for (final s in supportFilters)
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: 6),
                      child: ChoiceChip(
                        key: Key('support-filter-${s ?? 'all'}'),
                        label: Text(s == null ? context.t('common.all') : context.l10n.status(s)),
                        selected: _status == s,
                        labelStyle: NawyText.label.copyWith(color: _status == s ? NawyColors.textInverse : NawyColors.textPrimary),
                        showCheckmark: false,
                        onSelected: (_) => _filter(s),
                      ),
                    ),
                ]),
              ),
            ),
          ),
        ),
        floatingActionButton: FloatingActionButton.extended(
          key: const Key('support-new'),
          onPressed: () => _open(const NewTicketScreen()),
          icon: const Icon(Icons.add),
          label: Text(context.t('support.new')),
        ),
        body: _error != null && _rows.isEmpty
            ? Center(child: SingleChildScrollView(child: ErrorView(error: _error!, onRetry: () => _more(reset: true))))
            : RefreshIndicator(
                onRefresh: () => _more(reset: true),
                child: ListView(padding: const EdgeInsets.fromLTRB(NawySpace.gutter, NawySpace.gutter, NawySpace.gutter, 96), children: [
                  if (_rows.isEmpty && !_loading)
                    EmptyView(icon: Icons.support_agent_outlined, title: context.t('support.empty'), subtitle: context.t('support.emptyHint')),
                  for (final t in _rows)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Card(
                        child: ListTile(
                          key: Key('ticket-${t.number}'),
                          title: Text(t.subject, style: NawyText.bodyMedium, maxLines: 2, overflow: TextOverflow.ellipsis),
                          subtitle: Text(
                            [t.number, t.categoryLabel, if (_when(context, t.lastActivity) case final w?) context.t('support.updated', {'date': w})].join(' · '),
                            style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
                          ),
                          trailing: StatusChip(t.status),
                          onTap: () => _open(TicketDetailScreen(number: t.number)),
                        ),
                      ),
                    ),
                  if (_loading) const LoadingView(),
                  if (_hasMore && !_loading && _rows.isNotEmpty)
                    OutlinedButton(key: const Key('support-more'), onPressed: _more, child: Text(context.t('common.loadMore'))),
                ]),
              ),
      );
}

/// New ticket: category and priority from `support/categories`, subject and
/// message, plus photos (camera / gallery through image_picker, owner-review
/// D16) within the API's attachment limits.
class NewTicketScreen extends StatefulWidget {
  const NewTicketScreen({super.key});

  @override
  State<NewTicketScreen> createState() => _NewTicketScreenState();
}

class _NewTicketScreenState extends State<NewTicketScreen> {
  final _subject = TextEditingController();
  final _message = TextEditingController();
  String? _category;
  String _priority = 'normal';
  List<PickedMedia> _files = const [];
  bool _busy = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void dispose() {
    _subject.dispose();
    _message.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final invalid = TicketDraft.validate(category: _category, subject: _subject.text, message: _message.text);
    if (invalid != null) {
      setState(() => _error = context.t(invalid));
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final ticket = await VendorApi(context.api).openTicket(TicketDraft(category: _category!, subject: _subject.text, message: _message.text, priority: _priority, attachments: _files));
      if (!mounted) return;
      showSnack(context, context.t('support.created', {'number': ticket.number}));
      await Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => TicketDetailScreen(number: ticket.number)));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _fieldErrors = e.fieldErrors;
        _error = apiErrorMessage(context, e);
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _priorityLabel(SupportChoice p) => context.t('priority.${p.value}') == 'priority.${p.value}' ? (p.label.isEmpty ? p.value : p.label) : context.t('priority.${p.value}');

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('support.new'))),
        body: AsyncBody<SupportOptions>(
          refreshable: false,
          load: () => VendorApi(context.api).supportOptions(),
          builder: (context, options, _) {
            final priorities = options.priorities;
            if (!priorities.any((p) => p.value == _priority)) _priority = priorities.first.value;
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Text(context.t('support.category'), style: NawyText.label),
              const SizedBox(height: 6),
              DropdownButtonFormField<String>(
                key: const Key('ticket-category'),
                initialValue: _category,
                isExpanded: true,
                items: [for (final c in options.categories) DropdownMenuItem(value: c.value, child: Text(c.label))],
                onChanged: (v) => setState(() => _category = v),
                decoration: InputDecoration(errorText: _fieldErrors['category']),
              ),
              const SizedBox(height: 14),
              Text(context.t('support.priority'), style: NawyText.label),
              const SizedBox(height: 6),
              Wrap(spacing: 6, children: [
                for (final p in priorities)
                  ChoiceChip(
                    key: Key('ticket-priority-${p.value}'),
                    label: Text(_priorityLabel(p)),
                    selected: _priority == p.value,
                    labelStyle: NawyText.label.copyWith(color: _priority == p.value ? NawyColors.textInverse : NawyColors.textPrimary),
                    showCheckmark: false,
                    onSelected: (_) => setState(() => _priority = p.value),
                  ),
              ]),
              const SizedBox(height: 14),
              Text(context.t('support.subject'), style: NawyText.label),
              const SizedBox(height: 6),
              TextField(
                key: const Key('ticket-subject'),
                controller: _subject,
                maxLength: 160,
                decoration: InputDecoration(hintText: context.t('support.subjectHint'), errorText: _fieldErrors['subject']),
              ),
              const SizedBox(height: 6),
              Text(context.t('support.message'), style: NawyText.label),
              const SizedBox(height: 6),
              TextField(
                key: const Key('ticket-message'),
                controller: _message,
                minLines: 4,
                maxLines: 8,
                maxLength: 5000,
                decoration: InputDecoration(hintText: context.t('support.messageHint'), errorText: _fieldErrors['message']),
              ),
              Text(context.t('support.attachments'), style: NawyText.label),
              const SizedBox(height: 6),
              AttachmentPicker(
                key: const Key('ticket-attachments'),
                files: _files,
                rules: options.attachmentRules,
                enabled: !_busy,
                onChanged: (files) => setState(() => _files = files),
              ),
              const SizedBox(height: 12),
              if (_error != null) ...[
                Text(_error!, key: const Key('ticket-error'), style: NawyText.body.copyWith(color: NawyColors.textDanger)),
                const SizedBox(height: 8),
              ],
              FilledButton(
                key: const Key('ticket-submit'),
                onPressed: _busy ? null : _submit,
                child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(context.t('support.submit')),
              ),
            ]);
          },
        ),
      );
}

/// Ticket detail: the thread, a reply box while `can_reply`, Reopen while
/// `can_reopen` and a 1–5 star rating while `can_rate`.
class TicketDetailScreen extends StatefulWidget {
  const TicketDetailScreen({super.key, required this.number});

  final String number;

  @override
  State<TicketDetailScreen> createState() => _TicketDetailScreenState();
}

class _TicketDetailScreenState extends State<TicketDetailScreen> {
  final _key = GlobalKey<AsyncBodyState<SupportTicket>>();
  final _reply = TextEditingController();
  final _comment = TextEditingController();
  final _reopenNote = TextEditingController();
  int _stars = 0;
  bool _busy = false;
  List<PickedMedia> _files = const [];
  String? _replyError;

  @override
  void dispose() {
    _reply.dispose();
    _comment.dispose();
    _reopenNote.dispose();
    super.dispose();
  }

  void _reload() => _key.currentState?.reload();

  Future<void> _run(Future<SupportTicket> Function(VendorApi api) action, {required String success, VoidCallback? done}) async {
    setState(() => _busy = true);
    final api = VendorApi(context.api);
    final ok = await _ticketAction(context, () => action(api), success: success, onRefused: _reload);
    if (!mounted) return;
    setState(() => _busy = false);
    if (ok != null) {
      done?.call();
      _reload();
    }
  }

  Future<void> _send() async {
    final invalid = replyError(_reply.text);
    setState(() => _replyError = invalid == null ? null : context.t(invalid));
    if (invalid != null) return;
    final files = _files;
    await _run((api) => api.replyToTicket(widget.number, _reply.text, attachments: files), success: context.t('support.sent'), done: () {
      _reply.clear();
      setState(() => _files = const []);
    });
  }

  Future<void> _reopen() async {
    final note = _reopenNote..clear();
    final go = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(context.t('support.reopenTitle')),
        content: TextField(key: const Key('reopen-note'), controller: note, maxLines: 3, maxLength: 5000, decoration: InputDecoration(hintText: context.t('support.reopenHint'))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(context.t('common.cancel'))),
          FilledButton(key: const Key('reopen-confirm'), onPressed: () => Navigator.pop(context, true), child: Text(context.t('support.reopen'))),
        ],
      ),
    );
    final message = note.text;
    if (go != true || !mounted) return;
    await _run((api) => api.reopenTicket(widget.number, message: message), success: context.t('support.reopened'));
  }

  Future<void> _rate() => _run((api) => api.rateTicket(widget.number, _stars, comment: _comment.text), success: context.t('support.rated'), done: () {
        _stars = 0;
        _comment.clear();
      });

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.number)),
        body: AsyncBody<SupportTicket>(
          key: _key,
          load: () => VendorApi(context.api).supportTicket(widget.number),
          builder: (context, t, _) => ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(child: Text(t.subject, key: const Key('ticket-subject-text'), style: NawyText.h3)),
              const SizedBox(width: 8),
              StatusChip(t.status, key: const Key('ticket-status')),
            ]),
            const SizedBox(height: 4),
            Text(
              [t.categoryLabel, context.t('priority.${t.priority}'), if (t.order != null) context.t('support.order', {'number': t.order}), if (t.shipment != null) context.t('support.shipment', {'number': t.shipment})]
                  .join(' · '),
              style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
            ),
            if (const {'open', 'in_progress'}.contains(t.status) && _when(context, t.at('reply_expected_by')) != null)
              Text(context.t('support.replyBy', {'date': _when(context, t.at('reply_expected_by'))}), key: const Key('ticket-reply-by'), style: NawyText.bodySm),
            SectionTitle(context.t('support.thread')),
            for (final m in t.messages) _MessageBubble(message: m),
            const SizedBox(height: 8),
            if (t.canReply) ...[
              TextField(
                key: const Key('reply-body'),
                controller: _reply,
                minLines: 2,
                maxLines: 6,
                maxLength: 5000,
                decoration: InputDecoration(hintText: context.t('support.replyHint'), errorText: _replyError),
              ),
              AttachmentPicker(
                key: const Key('reply-attachments'),
                files: _files,
                rules: UploadRules.supportAttachments,
                enabled: !_busy,
                onChanged: (files) => setState(() => _files = files),
              ),
              const SizedBox(height: 8),
              FilledButton(key: const Key('reply-send'), onPressed: _busy ? null : _send, child: Text(context.t('support.send'))),
            ] else if (t.status == 'resolved')
              Text(context.t('support.resolvedNote'), key: const Key('ticket-resolved-note'), style: NawyText.body.copyWith(color: NawyColors.textSecondary))
            else if (t.status == 'closed')
              Text(context.t('support.closedNote'), key: const Key('ticket-closed-note'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
            if (t.canReopen) ...[
              const SizedBox(height: 10),
              OutlinedButton.icon(key: const Key('ticket-reopen'), onPressed: _busy ? null : _reopen, icon: const Icon(Icons.replay), label: Text(context.t('support.reopen'))),
              if (_when(context, t.at('reopen_until')) case final until?)
                Padding(padding: const EdgeInsets.only(top: 4), child: Text(context.t('support.reopenUntil', {'date': until}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary))),
            ],
            if (t.ratingScore != null) ...[
              const SizedBox(height: 16),
              Text(context.t('support.yourRating', {'score': t.ratingScore}), key: const Key('ticket-rating'), style: NawyText.bodyMedium),
              if ((t.rating?.s('comment') ?? '').isNotEmpty) Text(t.rating!.str('comment'), style: NawyText.bodySm),
            ] else if (t.canRate) ...[
              SectionTitle(context.t('support.rateTitle')),
              Row(children: [
                for (var i = 1; i <= 5; i++)
                  IconButton(
                    key: Key('star-$i'),
                    tooltip: context.t('support.star', {'count': i}),
                    onPressed: _busy ? null : () => setState(() => _stars = i),
                    icon: Icon(i <= _stars ? Icons.star_rounded : Icons.star_border_rounded, color: NawyColors.bgBrand, size: 32),
                  ),
              ]),
              TextField(key: const Key('rating-comment'), controller: _comment, maxLength: 1000, decoration: InputDecoration(hintText: context.t('support.rateComment'))),
              FilledButton(key: const Key('rating-send'), onPressed: _stars == 0 || _busy ? null : _rate, child: Text(context.t('support.rate'))),
            ],
            if (t.history.isNotEmpty) ...[
              SectionTitle(context.t('support.history')),
              for (final h in t.history)
                Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Text(
                    [
                      context.t('support.historyRow', {'from': h.s('from') == null ? '—' : context.l10n.status(h.s('from')), 'to': context.l10n.status(h.s('to'))}),
                      ?_when(context, DateTime.tryParse(h.str('at'))?.toLocal()),
                    ].join(' · '),
                    style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
                  ),
                ),
            ],
          ]),
        ),
      );
}

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({required this.message});

  final Json message;

  @override
  Widget build(BuildContext context) {
    final mine = message.b('mine');
    final from = mine ? context.t('support.you') : (message.s('from') == 'support' ? context.t('support.team') : (message.s('author') ?? context.t('support.title')));
    final when = _when(context, DateTime.tryParse(message.str('created_at'))?.toLocal());
    return Align(
      alignment: mine ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
      child: Container(
        key: Key('message-${message.i('id')}'),
        constraints: const BoxConstraints(maxWidth: 520),
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: mine ? NawyColors.bgBrandSubtle : NawyColors.bgSurface,
          borderRadius: BorderRadius.circular(NawyRadius.lg),
          border: Border.all(color: NawyColors.borderDefault),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text([from, ?when].join(' · '), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
          const SizedBox(height: 4),
          SelectableText(message.str('body'), style: NawyText.body),
          for (final a in message.l('attachments'))
            TextButton.icon(
              style: TextButton.styleFrom(padding: EdgeInsets.zero, alignment: AlignmentDirectional.centerStart),
              onPressed: a.s('url') == null ? null : () => PlatformBridge.openUrl(a.str('url')),
              icon: const Icon(Icons.attach_file, size: 16),
              label: Text(a.str('name', 'file'), overflow: TextOverflow.ellipsis),
            ),
        ]),
      ),
    );
  }
}
