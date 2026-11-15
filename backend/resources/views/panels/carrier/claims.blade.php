<x-layouts.panel :title="__('Claims & scorecard')" :subtitle="__('Lost, damaged and late claims raised by Nawy, sellers or customers · scorecard over 30 days')" :breadcrumbs="[__('Reports'), __('Claims & scorecard')]">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card :label="__('Scorecard (30 d)')" :value="($score['score'] ?? null) === null ? '—' : $score['score'].' / 100'" />
        <x-ui.stat-card :label="__('Open claims')" :value="$openClaims" />
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5">
            <span class="text-sm text-fg-secondary">{{ __('Claims paid (30 d)') }}</span>
            <span class="text-2xl font-bold"><x-ui.money :amount="$paid" :currency="$currentCountry->currency" /></span>
        </div>
        <x-ui.stat-card :label="__('Upheld claims (30 d)')" :value="$score['claims_upheld'] ?? 0" />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('Claims')" flush class="lg:col-span-2">
            <x-ui.table :headers="[__('Claim'), __('Order'), __('Type'), __('Amount'), __('Status'), '']" :rows="$claims->isNotEmpty()" :empty="__('No claims.')">
                @foreach ($claims as $claim)
                    <tr class="border-t border-line align-top" data-claim="{{ $claim->id }}">
                        <td class="px-5 py-3 font-medium">{{ $claim->number }}<p class="text-xs text-fg-secondary">{{ __('by :who', ['who' => $claim->raised_by_type]) }}</p></td>
                        <td class="px-5 py-3 text-xs">{{ $claim->subOrder?->number }}</td>
                        <td class="px-5 py-3 text-xs">{{ str_replace('_', ' ', ucfirst($claim->type->value)) }}<p class="max-w-xs text-fg-secondary">{{ $claim->description }}</p></td>
                        <td class="px-5 py-3"><x-ui.money :amount="$claim->claimed_amount" :currency="$claim->currency" /></td>
                        <td class="px-5 py-3"><x-ui.status-badge :status="$claim->status" /></td>
                        <td class="px-5 py-3">
                            @if ($claim->carrier_response)
                                <p class="max-w-xs text-xs">{{ __('Your response') }}: {{ $claim->carrier_response }}</p>
                            @elseif (in_array($claim->status->value, ['submitted', 'under_review'], true))
                                <form method="POST" action="{{ route('carrier.claims.respond', $claim->id) }}" class="flex flex-col gap-2">@csrf
                                    <textarea name="response" rows="2" required class="rounded border border-line px-2 py-1 text-xs" placeholder="{{ __('Your side of the story, tracking evidence…') }}"></textarea>
                                    <x-ui.button type="submit" size="sm" variant="outline">{{ __('Respond') }}</x-ui.button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
        <x-ui.card :title="__('Scorecard breakdown')">
            <dl class="flex flex-col gap-2 text-sm" data-scorecard>
                <div class="flex justify-between"><dt>{{ __('On-time delivery') }}</dt><dd>{{ ($score['on_time_rate'] ?? null) === null ? '—' : $score['on_time_rate'].'%' }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('Delivery success') }}</dt><dd>{{ ($score['success_rate'] ?? null) === null ? '—' : $score['success_rate'].'%' }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('Average pickup → delivery') }}</dt><dd>{{ ($score['avg_minutes'] ?? null) === null ? '—' : $score['avg_minutes'].' min' }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('SLA breaches') }}</dt><dd>{{ $score['sla_breaches'] ?? 0 }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('Claims (30 d)') }}</dt><dd>{{ $score['claims'] ?? 0 }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-fg-secondary">{{ __('Score = 60% success + 40% on time, minus 5 points per upheld claim.') }}</p>
        </x-ui.card>
    </div>
    {{ $claims->links() }}
</x-layouts.panel>
