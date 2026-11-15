<x-layouts.panel :title="__('Contracts')" :subtitle="__(':country templates and legacy (global) templates — another country\'s template is never used', ['country' => $country->name_en])" :breadcrumbs="[__('Operations'), __('Contracts')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Template'), __('Kind'), __('Country'), __('Mode'), __('In force'), __('Versions')]" :rows="$templates->isNotEmpty()" :empty="__('No contract templates yet.')">
            @foreach ($templates as $template)
                <tr class="border-t border-line" data-template="{{ $template->id }}">
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('admin.contracts.show', $template->id) }}">{{ $template->name }}</a></td>
                    <td class="px-5 py-3 text-xs">{{ $template->kind->label() }}</td>
                    <td class="px-5 py-3 text-xs">{{ $template->country_id === null ? __('Legacy / global') : $country->name_en }}</td>
                    <td class="px-5 py-3 text-xs">{{ $template->mode }} · {{ str_replace('_', '-', $template->consent_mode) }}</td>
                    <td class="px-5 py-3">{{ $template->publishedVersion ? 'v'.$template->publishedVersion->version : '—' }}</td>
                    <td class="px-5 py-3">{{ $template->versions_count }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @can('contract_templates.manage')
        <x-ui.card :title="__('New template')" :subtitle="__('Kind and country cannot be changed later')">
            <form method="POST" action="{{ route('admin.contracts.store') }}" class="grid gap-4 sm:grid-cols-3">
                @csrf
                <x-ui.input :label="__('Name')" name="name" required />
                <x-ui.select :label="__('Kind')" name="kind" :options="collect($kinds)->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all()" />
                <x-ui.select :label="__('Mode')" name="mode" :options="['editor' => __('Editor (Arabic + English text)'), 'file' => __('Uploaded file (PDF/DOC/DOCX)')]" />
                <x-ui.select :label="__('Consent')" name="consent_mode" :options="['click_wrap' => __('Click-wrap'), 'notice' => __('Notice (Seller Agreement only)')]" />
                <x-ui.checkbox :label="__('Country default for its kind')" name="is_default" />
                <x-ui.checkbox :label="__('Required for customers (marketer contract)')" name="required_for_customers" />
                <x-ui.checkbox :label="__('Open-call collaboration')" name="open_call" />
                <x-ui.checkbox :label="__('Legacy / global (super admin)')" name="global" />
                <div><x-ui.button type="submit">{{ __('Create') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
