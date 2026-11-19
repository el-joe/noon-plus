@php($clock = app(\App\Domains\Countries\Support\CountryClock::class))
@php($inReview = in_array($application->status, \App\Domains\Onboarding\Enums\ApplicationStatus::inReview(), true))
<x-layouts.panel :title="$application->number" :subtitle="$application->type->label().' · '.$application->name.' · '.$application->email" :breadcrumbs="[__('Users & vendors'), __('Applications'), $application->number]">
    <x-slot:actions>
        <x-ui.status-badge :status="$application->status" />
        @can('applications.approve')
            @if ($application->assigned_to_user_id !== auth()->id() && ! in_array($application->status->value, ['approved', 'rejected', 'withdrawn'], true))
                <form method="POST" action="{{ route('admin.applications.assign', $application->id) }}">@csrf<x-ui.button type="submit" variant="outline" size="sm">{{ __('Assign to me') }}</x-ui.button></form>
            @endif
            @if ($application->status->value === 'submitted')
                <form method="POST" action="{{ route('admin.applications.decide', $application->id) }}">@csrf<input type="hidden" name="decision" value="start"><x-ui.button type="submit" size="sm">{{ __('Start review') }}</x-ui.button></form>
            @endif
        @endcan
    </x-slot:actions>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="flex flex-col gap-4 lg:col-span-2">
            <x-ui.card :title="__('Applicant')">
                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-fg-secondary">{{ __('Name') }}</dt><dd>{{ $application->name }}</dd></div>
                    <div><dt class="text-xs text-fg-secondary">{{ __('Email') }}</dt><dd>{{ $application->email }}</dd></div>
                    <div><dt class="text-xs text-fg-secondary">{{ __('Phone') }}</dt><dd>{{ $application->phone }}</dd></div>
                    <div><dt class="text-xs text-fg-secondary">{{ __('Submitted (Oman time)') }}</dt><dd>{{ $application->submitted_at ? $clock->inAdminTime($application->submitted_at)->format('j M Y H:i') : '—' }}</dd></div>
                    <div><dt class="text-xs text-fg-secondary">{{ __('Reviewer') }}</dt><dd>{{ $actors->get($application->assigned_to_user_id, '—') }}</dd></div>
                    @if ($application->tenant_id)
                        <div><dt class="text-xs text-fg-secondary">{{ __('Created') }}</dt><dd data-tenant>{{ $application->tenant_type }} #{{ $application->tenant_id }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>

            @foreach ($application->type->dataSteps() as $step)
                @continue($step === 'account')
                <x-ui.card :title="ucfirst($step)" :subtitle="$application->hasCompleted($step) ? __('Completed') : __('Not completed')">
                    <dl class="grid gap-2 text-sm sm:grid-cols-2" data-step="{{ $step }}">
                        @forelse ($application->step($step) as $field => $value)
                            <div><dt class="text-xs text-fg-secondary">{{ str_replace('_', ' ', ucfirst($field)) }}</dt>
                                <dd>{{ is_array($value) ? collect($value)->map(fn ($item) => is_array($item) ? implode(' ', $item) : $item)->implode(', ') : ($value === null || $value === '' ? '—' : $value) }}</dd></div>
                        @empty
                            <p class="text-fg-secondary">—</p>
                        @endforelse
                    </dl>
                    @if ($step === 'agency' && $application->logo_path)
                        <img src="{{ Storage::disk('public')->url($application->logo_path) }}" alt="{{ __('Agency logo') }}" class="mt-3 h-16 rounded border border-line">
                    @endif
                </x-ui.card>
            @endforeach

            <x-ui.card :title="__('Documents')" flush>
                @if ($missing->isNotEmpty())
                    <p class="px-5 pt-4 text-sm text-fg-danger">{{ __('Missing or rejected: :list', ['list' => $missing->map(fn ($type) => $type->name_en)->implode(', ')]) }}</p>
                @endif
                <x-ui.table :headers="[__('Document'), __('File'), __('Valid until'), __('Status'), '']" :rows="$documents->isNotEmpty()" :empty="__('No documents uploaded.')">
                    @foreach ($documents as $document)
                        <tr class="border-t border-line" data-document="{{ $document->id }}">
                            <td class="px-5 py-3">{{ $types->get($document->document_type_id)?->name_en ?? '—' }}</td>
                            <td class="px-5 py-3"><a class="text-sm text-fg-link" href="{{ route('admin.applications.documents.download', [$application->id, $document->id]) }}">{{ $document->original_name }}</a></td>
                            <td class="px-5 py-3 text-xs">{{ $document->valid_until?->format('j M Y') ?? '—' }}</td>
                            <td class="px-5 py-3"><x-ui.status-badge :status="$document->status" />@if ($document->rejection_reason)<p class="text-xs text-fg-secondary">{{ $document->rejection_reason }}</p>@endif</td>
                            <td class="px-5 py-3">
                                @if ($document->status->value === 'pending' && $inReview)
                                    @can('applications.approve')
                                        <form method="POST" action="{{ route('admin.applications.documents.review', [$application->id, $document->id]) }}" class="flex gap-2">@csrf
                                            <input name="reason" class="h-8 rounded border border-line px-2 text-xs" placeholder="{{ __('Reason if rejecting') }}">
                                            <x-ui.button type="submit" size="sm" name="decision" value="approve">{{ __('Approve') }}</x-ui.button>
                                            <x-ui.button type="submit" size="sm" variant="outline" name="decision" value="reject">{{ __('Reject') }}</x-ui.button>
                                        </form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="flex flex-col gap-4">
            <x-ui.card :title="__('Agreement')">
                @if ($signature)
                    <p class="text-sm" data-signature>{{ __(':kind v:version signed by :name', ['kind' => $application->type->contractKind()->label(), 'version' => $signature->version, 'name' => $signature->full_name]) }}</p>
                    <p class="text-xs text-fg-secondary">{{ $clock->inAdminTime($signature->signed_at)->format('j M Y H:i') }} {{ __('Oman time') }} · IP {{ $signature->ip_address }}</p>
                @elseif ($agreementPublished)
                    <p class="text-sm text-fg-secondary">{{ __('Signed when the applicant submits.') }}</p>
                @else
                    <p class="text-sm text-fg-secondary">{{ __('No :kind is published in this country; the registration acceptance is recorded instead.', ['kind' => $application->type->contractKind()->label()]) }}</p>
                @endif
            </x-ui.card>

            {{-- FR-FIN-6: who brought the partner in; approval credits the new store to them (acquisition commissions). --}}
            <x-ui.card :title="__('Acquisition')" :subtitle="__('Account manager credited for this partner')">
                <div class="flex flex-col gap-3 text-sm" data-acquirer>
                    <p>{{ $acquirer ? $acquirer->name.' · '.$acquirer->email : __('Nobody recorded') }}</p>
                    @if ($application->referral_code)
                        <p class="text-xs text-fg-secondary" data-referral-code>{{ __('From referral link :code', ['code' => $application->referral_code]) }}</p>
                    @endif
                    @if ($application->tenant_id && $application->tenant_type === 'vendor')
                        <p class="text-xs text-fg-secondary">{{ __('Change it on the seller page (Users & vendors → Vendors).') }}</p>
                    @endif
                    @can('applications.approve')
                        @if (! in_array($application->status->value, ['approved', 'rejected', 'withdrawn'], true))
                            <form method="POST" action="{{ route('admin.applications.acquirer', $application->id) }}" class="flex flex-col gap-2" data-acquirer-form>
                                @csrf
                                <x-ui.select :label="__('Referred by / account manager')" name="acquired_by_user_id" :selected="(string) ($application->acquired_by_user_id ?? '')"
                                    :placeholder="__('Nobody')" :options="$acquirers->mapWithKeys(fn ($user) => [$user->id => $user->name.' · '.$user->email])->all()" />
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Save acquirer') }}</x-ui.button>
                            </form>
                        @endif
                    @endcan
                </div>
            </x-ui.card>

            @if ($application->decision_reason || $application->requested_changes)
                <x-ui.card :title="__('Last decision')">
                    <p class="text-sm">{{ $application->decision_reason ?? ($application->requested_changes['note'] ?? '') }}</p>
                </x-ui.card>
            @endif

            @can('applications.approve')
                @if ($inReview)
                    <x-ui.card :title="__('Decision')">
                        <form method="POST" action="{{ route('admin.applications.decide', $application->id) }}" class="flex flex-col gap-3" data-decision-form>
                            @csrf
                            <x-ui.textarea :label="__('Reason / note to applicant')" name="reason" :rows="3" />
                            <fieldset class="flex flex-col gap-1 text-sm">
                                <legend class="text-xs text-fg-secondary">{{ __('Steps to reopen (request changes)') }}</legend>
                                @foreach ($application->type->dataSteps() as $step)
                                    <label class="flex items-center gap-2"><input type="checkbox" name="steps[]" value="{{ $step }}" class="accent-brand"> {{ ucfirst($step) }}</label>
                                @endforeach
                                @foreach ($documents->filter(fn ($document) => $document->status->value === 'pending') as $document)
                                    <label class="flex items-center gap-2"><input type="checkbox" name="document_ids[]" value="{{ $document->id }}" class="accent-brand"> {{ __('Re-upload: :doc', ['doc' => $types->get($document->document_type_id)?->name_en]) }}</label>
                                @endforeach
                            </fieldset>
                            <div class="flex flex-wrap gap-2">
                                <x-ui.button type="submit" name="decision" value="approve">{{ __('Approve') }}</x-ui.button>
                                <x-ui.button type="submit" variant="outline" name="decision" value="changes">{{ __('Request changes') }}</x-ui.button>
                                <x-ui.button type="submit" variant="outline" name="decision" value="reject">{{ __('Reject') }}</x-ui.button>
                            </div>
                        </form>
                    </x-ui.card>
                @endif
            @endcan

            <x-ui.state-timeline :title="__('Audit trail')" :subtitle="__('Every step with who and when (Oman time)')"
                :steps="$history->map(fn ($row) => ['state' => 'done', 'label' => ($row->from_status === $row->to_status ? __('Assigned') : str_replace('_', ' ', ucfirst($row->to_status))), 'meta' => ucfirst((string) $row->actor_type).($row->actor_user_id ? ' '.$actors->get($row->actor_user_id) : '').' · '.$clock->inAdminTime($row->created_at)->format('j M Y H:i').($row->reason ? ' · '.$row->reason : '')])->all()" />
        </div>
    </div>
</x-layouts.panel>
