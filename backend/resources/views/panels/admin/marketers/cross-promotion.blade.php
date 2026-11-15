<x-layouts.panel :title="__('Cross-promotion policies')" :subtitle="$enabled ? __('Enabled in this country') : __('Disabled in this country — enable it in Marketer settings')" :breadcrumbs="[__('Growth'), __('Marketers'), __('Cross-promotion')]">
    <x-ui.card :title="__('New policy')">
        <form method="POST" action="{{ route('admin.marketers.cross-promotion.store') }}" class="grid gap-4 sm:grid-cols-4">
            @csrf
            <x-ui.input :label="__('Name')" name="name" required />
            <x-ui.select :label="__('Type')" name="type" :options="['peer_promotion' => __('Peer promotion'), 'obligation' => __('Monthly obligation')]" />
            <x-ui.select :label="__('Activity')" name="activity" :options="['peer_promotion' => __('Peer promotions'), 'content_submission' => __('Approved content'), 'accepted_campaign' => __('Accepted campaigns')]" />
            <x-ui.input :label="__('Per month')" name="count" type="number" min="1" value="1" />
            <x-ui.input :label="__('Min account age (days)')" name="min_account_age_days" type="number" min="0" />
            <x-ui.input :label="__('Min profile completion %')" name="min_profile_completion" type="number" min="0" max="100" />
            <x-ui.input :label="__('Min own products')" name="min_products" type="number" min="0" />
            <x-ui.input :label="__('Promoter share %')" name="promoter_percent" type="number" step="0.01" min="0" />
            <x-ui.select :label="__('Penalty for a miss')" name="penalty" :options="['warning' => __('Warning'), 'suspend' => __('Suspend')]" />
            <x-ui.input :label="__('Exempt marketer ids (comma separated)')" name="exemptions" />
            <x-ui.checkbox :label="__('Verified only')" name="verified_only" />
            <div class="flex items-end"><x-ui.button type="submit">{{ __('Create') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Policy'), __('Obligation'), __('Met'), __('Missed'), __('Status'), '']" :rows="$policies->isNotEmpty()">
            @foreach ($policies as $policy)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $policy->name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $policy->obligation['count'] ?? 1 }} × {{ str_replace('_', ' ', $policy->obligation['activity'] ?? '') }} / {{ __('month') }}</td>
                    <td class="px-5 py-3">{{ $policy->met_count }}</td>
                    <td class="px-5 py-3">{{ $policy->missed_count }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$policy->is_active ? 'active' : 'paused'" /></td>
                    <td class="px-5 py-3"><form method="POST" action="{{ route('admin.marketers.cross-promotion.toggle', $policy->id) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ $policy->is_active ? __('Pause') : __('Activate') }}</x-ui.button></form></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
