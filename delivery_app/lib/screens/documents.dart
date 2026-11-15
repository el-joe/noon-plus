import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';

/// Documents required for agents in this country and their review state.
/// Uploading needs a file / image picker (`image_picker`, owner review WP10);
/// agents upload from the Delivery web panel meanwhile.
class DocumentsScreen extends StatelessWidget {
  const DocumentsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('documents.title'))),
        body: AsyncBody<Json>(
          load: () => DeliveryApi(context.api).documents(),
          builder: (context, data, _) {
            final uploads = data.l('uploads');
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              for (final r in data.l('requirements'))
                Builder(builder: (context) {
                  final upload = uploads.where((u) => u.i('document_type_id') == r.i('id')).firstOrNull;
                  return Card(
                    child: ListTile(
                      title: Text(r.str('name')),
                      subtitle: Text(r.b('mandatory') ? context.t('documents.mandatory') : context.t('documents.optional')),
                      trailing: upload == null ? StatusChip('missing', label: context.t('documents.missing')) : StatusChip(upload.str('status')),
                    ),
                  );
                }),
              if (data.l('requirements').isEmpty) EmptyView(icon: Icons.description_outlined, title: context.t('documents.none')),
              const SizedBox(height: 12),
              Text(context.t('documents.uploadOnWeb'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            ]);
          },
        ),
      );
}
