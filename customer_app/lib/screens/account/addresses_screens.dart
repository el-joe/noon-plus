import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';
import '../checkout/checkout_screen.dart';

/// Figma "39 Addresses": address book (`GET/POST/PATCH/DELETE addresses`,
/// default). With `pick`, tapping an address returns it (checkout).
class AddressesScreen extends StatefulWidget {
  const AddressesScreen({super.key, this.pick = false});

  final bool pick;

  @override
  State<AddressesScreen> createState() => _AddressesScreenState();
}

class _AddressesScreenState extends State<AddressesScreen> {
  int _gen = 0;

  Future<void> _edit([Json? address]) async {
    final saved = await Navigator.of(context).pushNamed(Routes.addressForm, arguments: {'address': address});
    if (saved != null && mounted) setState(() => _gen++);
  }

  @override
  Widget build(BuildContext context) {
    final api = AppScope.read(context).api;
    return Scaffold(
      appBar: AppBar(title: Text(context.t(widget.pick ? 'address.pick' : 'address.title'))),
      floatingActionButton: FloatingActionButton.extended(key: const ValueKey('address-add'), onPressed: () => _edit(), icon: const Icon(Icons.add_location_alt_outlined), label: Text(context.t('address.add'))),
      body: AsyncBody<List<Json>>(
        key: ValueKey(_gen),
        load: () => api.getList('addresses').then((r) => r.data),
        isEmpty: (list) => list.isEmpty,
        empty: EmptyView(icon: Icons.location_off_outlined, title: context.t('address.empty'), actionLabel: context.t('address.add'), onAction: () => _edit()),
        builder: (context, list, _) => ListView(padding: const EdgeInsets.only(top: 12, bottom: 96), children: [
          for (final a in list)
            InkWell(
              onTap: widget.pick ? () => Navigator.of(context).pop(a) : () => _edit(a),
              child: SectionCard(
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Icon(a.b('is_default') ? Icons.home_rounded : Icons.location_on_outlined),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Text(addressLabel(context, a.s('label')), style: NawyText.bodyMedium),
                        if (a.b('is_default')) ...[const SizedBox(width: 6), Pill(context.t('address.default'))],
                      ]),
                      Text(a.str('name'), style: NawyText.bodySm),
                      Text(addressLine(a), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                      if (a.s('phone') != null) Text(a.str('phone'), textDirection: TextDirection.ltr, style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                    ]),
                  ),
                  if (!widget.pick)
                    PopupMenuButton<String>(
                      tooltip: context.t('common.more'),
                      onSelected: (action) async {
                        if (action == 'default') {
                          await guarded(context, () => api.post('addresses/${a.i('id')}/default'));
                        } else if (action == 'delete') {
                          await guarded(context, () => api.delete('addresses/${a.i('id')}'), success: context.t('address.deleted'));
                        }
                        if (mounted) setState(() => _gen++);
                      },
                      itemBuilder: (ctx) => [
                        if (!a.b('is_default')) PopupMenuItem(value: 'default', child: Text(ctx.t('address.makeDefault'))),
                        PopupMenuItem(value: 'delete', child: Text(ctx.t('common.delete'))),
                      ],
                    )
                  else
                    const Icon(Icons.chevron_right_rounded),
                ]),
              ),
            ),
        ]),
      ),
    );
  }
}

/// Add / edit an address; city and area from `GET delivery-areas` when the
/// country has zones, free text otherwise. Phone in E.164 with the country code.
class AddressFormScreen extends StatefulWidget {
  const AddressFormScreen({super.key, this.address});

  final Json? address;

  @override
  State<AddressFormScreen> createState() => _AddressFormScreenState();
}

class _AddressFormScreenState extends State<AddressFormScreen> {
  late final Map<String, TextEditingController> _c = {
    for (final k in const ['name', 'phone', 'city', 'area', 'street', 'building', 'apartment', 'landmark']) k: TextEditingController(text: widget.address?.s(k) ?? ''),
  };
  late bool _default = widget.address?.b('is_default') ?? false;
  late String _label = const {'home', 'work', 'other'}.contains(widget.address?.s('label')) ? widget.address!.str('label') : 'home';
  Map<String, String> _errors = {};
  List<Json> _areas = const [];

  @override
  void initState() {
    super.initState();
    final app = AppScope.read(context);
    if (_c['name']!.text.isEmpty) _c['name']!.text = app.user?.s('name') ?? '';
    if (_c['phone']!.text.isEmpty) _c['phone']!.text = app.phoneCode;
    app.api.getList('delivery-areas').then((r) {
      if (mounted) setState(() => _areas = r.data);
    }).catchError((_) {});
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final body = {for (final e in _c.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(), 'label': _label, 'is_default': _default};
    final api = AppScope.read(context).api;
    try {
      final id = widget.address?.i('id');
      final res = id == null ? await api.post('addresses', body: body) : await api.patch('addresses/$id', body: body);
      if (mounted) Navigator.of(context).pop(asJson(res.data));
    } on ApiException catch (e) {
      setState(() => _errors = {...e.fieldErrors, if (e.fieldErrors.isEmpty) 'form': e.message});
    }
  }

  Widget _field(String key, String label, {TextInputType? type, bool required = false}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(
          key: ValueKey('address-$key'),
          controller: _c[key],
          keyboardType: type,
          textDirection: type == TextInputType.phone ? TextDirection.ltr : null,
          decoration: InputDecoration(labelText: required ? '$label *' : label, errorText: _errors[key]),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final city = _areas.firstWhere((a) => a.s('city') == _c['city']!.text, orElse: () => {});
    return Scaffold(
      appBar: AppBar(title: Text(context.t(widget.address == null ? 'address.add' : 'address.edit'))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(context.t('address.label'), style: NawyText.bodyMedium),
        const SizedBox(height: 6),
        Wrap(spacing: 8, children: [
          for (final l in const ['home', 'work', 'other'])
            ChoiceChip(key: ValueKey('address-label-$l'), label: Text(context.t('address.kind.$l')), selected: _label == l, onSelected: (_) => setState(() => _label = l)),
        ]),
        const SizedBox(height: 12),
        _field('name', context.t('profile.name'), required: true),
        _field('phone', context.t('address.phone'), type: TextInputType.phone, required: true),
        if (_areas.isNotEmpty) ...[
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: DropdownButtonFormField<String>(
              initialValue: city.isEmpty ? null : _c['city']!.text,
              isExpanded: true,
              decoration: InputDecoration(labelText: '${context.t('address.city')} *', errorText: _errors['city']),
              items: [for (final a in _areas) DropdownMenuItem(value: a.str('city'), child: Text(a.str('city')))],
              onChanged: (v) => setState(() {
                _c['city']!.text = v ?? '';
                _c['area']!.text = '';
              }),
            ),
          ),
          if (city.l('areas').isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: DropdownButtonFormField<String>(
                initialValue: city.l('areas').any((a) => a.s('name') == _c['area']!.text) ? _c['area']!.text : null,
                isExpanded: true,
                decoration: InputDecoration(labelText: '${context.t('address.area')} *', errorText: _errors['area']),
                items: [for (final a in city.l('areas')) DropdownMenuItem(value: a.str('name'), child: Text(a.str('name')))],
                onChanged: (v) => setState(() => _c['area']!.text = v ?? ''),
              ),
            )
          else
            _field('area', context.t('address.area'), required: true),
        ] else ...[
          _field('city', context.t('address.city'), required: true),
          _field('area', context.t('address.area'), required: true),
        ],
        _field('street', context.t('address.street'), required: true),
        _field('building', context.t('address.building')),
        _field('apartment', context.t('address.apartment')),
        _field('landmark', context.t('address.landmark')),
        SwitchListTile(value: _default, onChanged: (v) => setState(() => _default = v), title: Text(context.t('address.makeDefault')), contentPadding: EdgeInsets.zero),
        if (_errors['form'] != null) Text(_errors['form']!, style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
        const SizedBox(height: 12),
        BusyButton(key: const ValueKey('address-save'), label: context.t('common.save'), onPressed: _save),
      ]),
    );
  }
}

/// `home` / `work` / `other` → translated label.
String addressLabel(BuildContext context, String? label) =>
    const {'home', 'work', 'other'}.contains(label) ? context.t('address.kind.$label') : (label ?? context.t('address.kind.home'));
