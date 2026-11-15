@php($clock = app(\App\Domains\Countries\Support\CountryClock::class))
<x-layouts.panel :title="__('Applications')" :subtitle="__('Partner applications from the portal wizards · times in Oman time')" :breadcrumbs="[__('Users & vendors'), __('Applications')]">
    <x-slot:actions>
        @can('join_options.configure')<x-ui.button variant="outline" :href="route('admin.join-options')">{{ __('Join options') }}</x-ui.button>@endcan
        <x-ui.button variant="outline" :href="route('admin.applications', array_merge($filters, ['mine' => 1]))">{{ __('Assigned to me') }}</x-ui.button>
    </x-slot:actions>

    <nav class="flex flex-wrap gap-2 border-b border-line pb-2" aria-label="{{ __('Application types') }}">
        <a href="{{ route('admin.applications', ['status' => $filters['status']]) }}" @class(['rounded-lg px-3 py-1.5 text-sm', 'bg-brand-subtle font-semibold' => empty($filters['type'])])>{{ __('All') }} ({{ $counts->sum() }})</a>
        @foreach ($types as $type)
            <a href="{{ route('admin.applications', ['type' => $type->value, 'status' => $filters['status']]) }}" data-type-tab="{{ $type->value }}"
               @class(['rounded-lg px-3 py-1.5 text-sm', 'bg-brand-subtle font-semibold' => ($filters['type'] ?? null) === $type->value])>{{ $type->label() }} ({{ $counts->get($type->value, 0) }})</a>
        @endforeach
    </nav>

    <form method="GET" class="flex gap-2">
        @if (! empty($filters['type']))<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
        <x-ui.select name="status" :options="['review' => __('Waiting for review')] + collect($statuses)->mapWithKeys(fn ($s) => [$s->value => str_replace('_', ' ', ucfirst($s->value))])->all()" :selected="$filters['status']" onchange="this.form.submit()" />
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Application'), __('Applicant'), __('Submitted (Oman)'), __('Checks'), __('Reviewer'), __('Status')]" :rows="$applications->isNotEmpty()" :empty="__('No applications here.')">
            @foreach ($applications as $application)
                <tr class="border-t border-line" data-application="{{ $application->number }}">
                    <td class="px-5 py-3">
                        <a class="font-medium text-fg-link" href="{{ route('admin.applications.show', $application->id) }}">{{ $application->number }}</a>
                        <p class="text-xs text-fg-secondary">{{ $application->type->label() }}</p>
                    </td>
                    <td class="px-5 py-3 text-sm">{{ $application->name }}<p class="text-xs text-fg-secondary">{{ $application->email }}</p></td>
                    <td class="px-5 py-3 text-xs">{{ $application->submitted_at ? $clock->inAdminTime($application->submitted_at)->format('j M Y H:i') : '—' }}</td>
                    <td class="px-5 py-3"><x-ui.badge :tone="$application->documents_count > 0 && $application->approved_documents_count === $application->documents_count ? 'success' : 'neutral'">{{ __('Docs :ok/:all', ['ok' => $application->approved_documents_count, 'all' => $application->documents_count]) }}</x-ui.badge></td>
                    <td class="px-5 py-3 text-xs">{{ $reviewers->get($application->assigned_to_user_id, '—') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$application->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $applications->links() }}
</x-layouts.panel>
