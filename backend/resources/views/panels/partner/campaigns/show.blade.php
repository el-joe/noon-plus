<x-layouts.panel :title="$campaign->title" :subtitle="$campaign->number.' · '.__('Origin :o', ['o' => $campaign->origin->letter()]).' · '.$campaign->listing->variant->product->title_en" :breadcrumbs="[__('Seller Center'), __('Marketer campaigns'), $campaign->number]">
    <x-slot:actions>
        <x-ui.status-badge :status="$campaign->status" />
    </x-slot:actions>

    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Budget')" :value="$campaign->budget === null ? __('Unlimited') : app(\App\Shared\Money\MoneyFormatter::class)->format($campaign->budget, $campaign->currency)" />
        <x-ui.stat-card :label="__('Spent')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format($campaign->budget_spent, $campaign->currency)" />
        <x-ui.stat-card :label="__('Reserved for influencers')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format($campaign->influencer_fixed_reserved, $campaign->currency)" />
        <x-ui.stat-card :label="__('Campaign fee')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format($campaign->fee_amount, $campaign->currency).' · '.$campaign->fee_status->value" />
    </section>

    @if ($campaign->status->value === 'marketer_requested')
        <x-ui.card :title="__(':name asked to promote this listing', ['name' => $campaign->requester?->display_name])">
            <form method="POST" action="{{ route('partner.campaigns.approve-request', $campaign->id) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <x-ui.input :label="__('Max commission budget')" name="budget" type="number" step="0.001" min="0" required />
                <x-ui.button type="submit">{{ __('Approve and go live') }}</x-ui.button>
            </form>
            <form method="POST" action="{{ route('partner.campaigns.reject-request', $campaign->id) }}" class="mt-3 flex items-end gap-3">
                @csrf
                <x-ui.input :label="__('Reason')" name="reason" required />
                <x-ui.button type="submit" variant="outline">{{ __('Reject request') }}</x-ui.button>
            </form>
        </x-ui.card>
    @endif

    <div class="flex flex-wrap gap-2">
        @if (in_array($campaign->status->value, ['active', 'auto_approved']))
            <form method="POST" action="{{ route('partner.campaigns.pause', $campaign->id) }}">@csrf<x-ui.button type="submit" variant="outline">{{ __('Pause') }}</x-ui.button></form>
        @endif
        @if ($campaign->status->value === 'paused')
            <form method="POST" action="{{ route('partner.campaigns.resume', $campaign->id) }}">@csrf<x-ui.button type="submit" variant="outline">{{ __('Resume') }}</x-ui.button></form>
        @endif
        @unless ($campaign->status->isTerminal())
            <form method="POST" action="{{ route('partner.campaigns.cancel', $campaign->id) }}">@csrf<x-ui.button type="submit" variant="danger">{{ __('Cancel campaign') }}</x-ui.button></form>
        @endunless
    </div>

    <x-ui.card :title="__('Invited marketers')" flush>
        <x-ui.table :headers="[__('Marketer'), __('Type'), __('Fixed'), __('Code'), __('Status')]" :rows="$campaign->invitations->isNotEmpty()" :empty="__('Invitations go out when the campaign goes live.')">
            @foreach ($campaign->invitations as $invitation)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $invitation->marketer->display_name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $invitation->effective_type }} · {{ $invitation->source->value }}</td>
                    <td class="px-5 py-3">@if ($invitation->influencer_fixed) <x-ui.money :amount="$invitation->influencer_fixed" :currency="$campaign->currency" /> @else — @endif</td>
                    <td class="px-5 py-3 font-mono text-xs">{{ $invitation->referral_code }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$invitation->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($campaign->status->value === 'pending_admin' || $campaign->status->isLive())
            <form method="POST" action="{{ route('partner.campaigns.invite', $campaign->id) }}" class="flex flex-wrap items-end gap-3 px-5 py-4">
                @csrf
                <x-ui.select :label="__('Invite more')" name="influencer_ids[]" :options="$influencers->pluck('display_name', 'id')->all()" />
                <x-ui.button type="submit" variant="outline">{{ __('Invite') }}</x-ui.button>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Samples')" flush>
        <x-ui.table :headers="[__('Kind'), __('Qty'), __('Address'), __('Status'), '']" :rows="$campaign->samples->isNotEmpty()">
            @foreach ($campaign->samples as $sample)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $sample->kind }}</td>
                    <td class="px-5 py-3">{{ $sample->quantity }}</td>
                    <td class="px-5 py-3 text-xs">{{ $sample->address['city'] ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$sample->status" /></td>
                    <td class="px-5 py-3">
                        @if (in_array($sample->status->value, ['planned', 'requested']) && $campaign->status->isLive())
                            <form method="POST" action="{{ route('partner.campaigns.samples.ship', $sample->id) }}" class="flex gap-2">@csrf<input name="tracking_number" class="h-8 rounded border border-line px-2 text-xs" placeholder="{{ __('Tracking no.') }}"><x-ui.button type="submit" size="sm" variant="outline">{{ __('Ship') }}</x-ui.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Content submissions')" flush>
        <x-ui.table :headers="[__('Platform'), __('Link'), __('Status'), '']" :rows="$campaign->contentSubmissions->isNotEmpty()">
            @foreach ($campaign->contentSubmissions as $submission)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $submission->platform }}</td>
                    <td class="px-5 py-3 text-xs"><a class="text-fg-link" href="{{ $submission->url }}" rel="noopener" target="_blank">{{ \Illuminate\Support\Str::limit($submission->url, 48) }}</a></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$submission->status" /></td>
                    <td class="px-5 py-3">
                        @if ($submission->status->value === 'submitted')
                            <form method="POST" action="{{ route('partner.campaigns.content.review', $submission->id) }}" class="flex gap-2">@csrf
                                <input name="reason" class="h-8 rounded border border-line px-2 text-xs" placeholder="{{ __('Reason if rejecting') }}">
                                <x-ui.button type="submit" size="sm" name="decision" value="approve">{{ __('Approve') }}</x-ui.button>
                                <x-ui.button type="submit" size="sm" variant="outline" name="decision" value="reject">{{ __('Reject') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('History')">
        <ol class="flex flex-col gap-1 text-sm">
            @foreach ($campaign->transitions as $transition)
                <li>{{ $transition->created_at->format('j M Y H:i') }} — {{ $transition->from_status ?? '∅' }} → <strong>{{ $transition->to_status }}</strong> ({{ $transition->actor_type }}) {{ $transition->reason }}</li>
            @endforeach
        </ol>
    </x-ui.card>
</x-layouts.panel>
