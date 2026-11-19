<x-layouts.panel :title="__('Flash sales')" :subtitle="__(':country · times typed in :zone, Oman time shown under each field', ['country' => $country->name_en, 'zone' => $country->timezone])"
                 :breadcrumbs="[__('Growth'), __('Coupons & flash sales')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Event'), __('Starts (Oman)'), __('Ends (Oman)'), __('Offers'), __('Status')]" :rows="$events->isNotEmpty()" :empty="__('No flash sales yet.')">
            @foreach ($events as $event)
                <tr class="border-t border-line" data-event="{{ $event->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.flash-sales.show', $event->id) }}">{{ $event->name }}</a></td>
                    <td class="px-5 py-3 text-xs">{{ $clock->inAdminTime($event->starts_at)->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $clock->inAdminTime($event->ends_at)->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3">{{ $event->submissions_count }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$event->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $events->links() }}

    @can('flash_sales.manage')
        <x-ui.card :title="__('New flash sale')">
            <form method="POST" action="{{ route('admin.flash-sales.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.input :label="__('Name')" name="name" required />
                <x-ui.input :label="__('Display name (storefront)')" name="display_name" />
                <x-ui.datetime-input :label="__('Submissions open')" name="submission_starts_at" :country="$country" />
                <x-ui.datetime-input :label="__('Submissions close')" name="submission_ends_at" :country="$country" />
                <x-ui.datetime-input :label="__('Sale starts')" name="starts_at" :country="$country" required />
                <x-ui.datetime-input :label="__('Sale ends')" name="ends_at" :country="$country" required />
                <x-ui.input :label="__('Max units per customer')" name="max_qty_per_customer" type="number" min="1" :helper="__('Leave empty for the country default')" />
                <x-ui.select :label="__('Marketer bonus')" name="marketer_bonus_type" :placeholder="__('Country default (percent of every flash sale)')"
                             :options="['percent' => __('Percent of the sale (invited marketers who accept)'), 'fixed' => __('Fixed per unit (invited marketers who accept)')]" />
                <x-ui.input :label="__('Bonus value')" name="marketer_bonus_value" inputmode="decimal" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Create draft') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
