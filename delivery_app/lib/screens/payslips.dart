import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/files.dart';

/// The agent's payslips, newest first (`GET delivery/me/payslips`).
class PayslipsScreen extends StatelessWidget {
  const PayslipsScreen({super.key, this.files = const DeviceFileSaver()});

  final FileSaver files;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('payslips.title'))),
        body: AsyncBody<List<Json>>(
          load: () => DeliveryApi(context.api).payslips(),
          builder: (context, slips, _) => slips.isEmpty
              ? ListView(children: [EmptyView(icon: Icons.receipt_long_outlined, title: context.t('payslips.none'), subtitle: context.t('payslips.noneHint'))])
              : ListView.separated(
                  padding: const EdgeInsets.all(NawySpace.gutter),
                  itemCount: slips.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final p = slips[i];
                    return Card(
                      child: ListTile(
                        key: Key('payslip-${p.str('number')}'),
                        title: Text(payslipPeriod(p), style: NawyText.bodyMedium),
                        subtitle: Text('${p.str('number')} · ${context.t('payslips.counts', {'delivered': p.i('delivered') ?? 0, 'failed': p.i('failed') ?? 0})}'),
                        trailing: Text(money(context, p['total']), style: NawyText.bodyMedium),
                        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => PayslipScreen(number: p.str('number'), files: files))),
                      ),
                    );
                  },
                ),
        ),
      );
}

/// An API timestamp in local time: `2026-11-10 14:22` (or the date only).
String? localStamp(String? iso, {bool withTime = true}) {
  final t = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (t == null) return iso;
  String two(int v) => v.toString().padLeft(2, '0');
  final date = '${t.year}-${two(t.month)}-${two(t.day)}';
  return withTime ? '$date ${two(t.hour)}:${two(t.minute)}' : date;
}

/// "2026-11-01 – 2026-11-15".
String payslipPeriod(Json p) => '${p.str('period_start')} – ${p.str('period_end')}';

/// One payslip with its lines and the PDF download.
class PayslipScreen extends StatefulWidget {
  const PayslipScreen({super.key, required this.number, this.files = const DeviceFileSaver()});

  final String number;
  final FileSaver files;

  @override
  State<PayslipScreen> createState() => _PayslipScreenState();
}

class _PayslipScreenState extends State<PayslipScreen> {
  bool _downloading = false;
  SavedFile? _saved;

  Future<void> _download() async {
    setState(() => _downloading = true);
    final api = DeliveryApi(context.api);
    try {
      final file = await api.payslipPdf(widget.number);
      final name = file.filename ?? 'payslip-${widget.number}.pdf';
      final saved = await widget.files.save(name, file.bytes, mimeType: file.contentType ?? 'application/pdf');
      if (!mounted) return;
      setState(() => _saved = saved);
      final opened = await widget.files.open(saved);
      if (!mounted) return;
      if (!opened) showSnack(context, context.t('payslips.savedTo', {'path': saved.location}));
    } on ApiException catch (e) {
      if (mounted) showSnack(context, apiErrorMessage(context, e));
    } catch (_) {
      if (mounted) showSnack(context, context.t('payslips.saveFailed'));
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.number)),
        body: AsyncBody<Json>(
          load: () => DeliveryApi(context.api).payslip(widget.number),
          builder: (context, p, _) => ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
            Text(payslipPeriod(p), style: NawyText.h3),
            if (p.s('issued_at') != null) Text(context.t('payslips.issued', {'date': localStamp(p.s('issued_at'), withTime: false)}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            const SizedBox(height: 12),
            KpiGrid(tiles: [
              KpiTile(key: const Key('payslip-total'), value: money(context, p['total']), label: context.t('payslips.total'), highlight: true),
              KpiTile(value: '${p.i('delivered') ?? 0}', label: context.t('earnings.stops')),
              KpiTile(value: '${p.i('failed') ?? 0}', label: context.t('earnings.failed')),
            ]),
            const SizedBox(height: 8),
            if (p['per_stop'] != null) Text(context.t('earnings.rate', {'amount': money(context, p['per_stop'])}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            if (p['per_failed'] != null) Text(context.t('payslips.perFailed', {'amount': money(context, p['per_failed'])}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              key: const Key('payslip-pdf'),
              onPressed: _downloading ? null : _download,
              icon: _downloading ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.picture_as_pdf_outlined),
              label: Text(context.t('payslips.pdf')),
            ),
            if (_saved != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(context.t('payslips.savedTo', {'path': _saved!.location}), key: const Key('payslip-saved'), style: NawyText.bodySm.copyWith(color: NawyColors.textSuccess)),
              ),
            SectionTitle(context.t('payslips.lines')),
            if (p.l('lines').isEmpty) Text(context.t('payslips.noLines'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            for (final line in p.l('lines'))
              ListTile(
                key: Key('payslip-line-${line.str('assignment_number')}'),
                contentPadding: EdgeInsets.zero,
                title: Text(line.str('sub_order_number', line.str('assignment_number'))),
                subtitle: Text([line.str('assignment_number'), ?localStamp(line.s('finished_at'))].join(' · ')),
                trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Text(money(context, line['amount']), style: NawyText.bodyMedium),
                  StatusChip(line.str('outcome')),
                ]),
              ),
          ]),
        ),
      );
}
