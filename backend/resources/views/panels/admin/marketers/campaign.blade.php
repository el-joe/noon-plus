<x-layouts.panel :title="$campaign->title" :subtitle="$campaign->number.' · '.($campaign->vendor?->store_name ?? __('Platform')).' · '.$campaign->listing->variant->product->title_en" :breadcrumbs="[__('Growth'), __('Marketer campaigns'), $campaign->number]">
    <x-slot:actions><x-ui.status-badge :status="$campaign->status" /></x-slot:actions>

    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Budget')" :value="$campaign->budget === null ? __('Unlimited') : app(\App\Shared\Money\MoneyFormatter::class)->format($campaign->budget, $campaign->currency)" />
        <x-ui.stat-card :label="__('Left')" :value="$remaining === null ? '—' : app(\App\Shared\Money\MoneyFormatter::class)->format((string) $remaining->getAmount(), $campaign->currency)" />
        <x-ui.stat-card :label="__('Fee')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format($campaign->fee_amount, $campaign->currency).' · '.$campaign->fee_status->value" />
        <x-ui.stat-card :label="__('Auto-approve')" :value="$campaign->auto_approve_at?->setTimezone(config('nawy.admin_timezone'))->format('j M H:i').' '.__('Oman')" />
    </section>

    @can('marketer_campaigns.manage')
        <form method="POST" action="{{ route('admin.marketers.campaigns.transition', $campaign->id) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <x-ui.input :label="__('Reason')" name="reason" />
            @foreach ($campaign->status->allowedNext() as $next)
                @php($action = match ($next->value) { 'active' => $campaign->status->value === 'paused' ? 'resume' : 'approve', 'rejected' => 'reject', 'paused' => 'pause', 'done' => 'complete', 'cancelled' => 'cancel', default => null })
                @if ($action && ! ($next->value === 'auto_approved') && ! ($campaign->status->value === 'marketer_requested' && $next->value === 'active'))
                    <x-ui.button type="submit" name="action" :value="$action" :variant="in_array($action, ['reject', 'cancel']) ? 'danger' : 'outline'">{{ ucfirst($action) }}</x-ui.button>
                @endif
            @endforeach
        </form>
    @endcan

    <x-ui.card :title="__('Invitations')" flush>
        <x-ui.table :headers="[__('Marketer'), __('Type'), __('Code'), __('Expires'), __('Status'), '']" :rows="$campaign->invitations->isNotEmpty()">
            @foreach ($campaign->invitations as $invitation)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $invitation->marketer->display_name }}@if ($invitation->replaced_invitation_id)<div class="text-xs text-fg-secondary">{{ __('replacement') }}</div>@endif</td>
                    <td class="px-5 py-3 text-xs">{{ $invitation->effective_type }} · {{ $invitation->source->value }}</td>
                    <td class="px-5 py-3 font-mono text-xs">{{ $invitation->referral_code }}</td>
                    <td class="px-5 py-3 text-xs">{{ $invitation->expires_at?->setTimezone(config('nawy.admin_timezone'))->format('j M H:i') ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$invitation->status" /></td>
                    <td class="px-5 py-3">@if ($invitation->status->value === 'pending')<form method="POST" action="{{ route('admin.marketers.invitations.resend', $invitation->id) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Resend WhatsApp') }}</x-ui.button></form>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
        @can('marketer_campaigns.manage')
            <form method="POST" action="{{ route('admin.marketers.campaigns.invite', $campaign->id) }}" class="flex items-end gap-3 px-5 py-4">@csrf
                <x-ui.select :label="__('Invite more')" name="influencer_ids[]" :options="$influencers->pluck('display_name', 'id')->all()" />
                <x-ui.button type="submit" variant="outline">{{ __('Invite') }}</x-ui.button>
            </form>
        @endcan
    </x-ui.card>

    <x-ui.card :title="__('Samples')" flush>
        <x-ui.table :headers="[__('Kind'), __('Qty'), __('Status'), __('Commission'), '']" :rows="$campaign->samples->isNotEmpty()">
            @foreach ($campaign->samples as $sample)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $sample->kind }}</td><td class="px-5 py-3">{{ $sample->quantity }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$sample->status" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="$sample->commission_amount" :currency="$campaign->currency" /></td>
                    <td class="px-5 py-3">
                        @can('marketer_campaigns.manage')
                            @php($next = match ($sample->status->value) { 'planned', 'requested' => 'ship', 'shipped' => 'deliver', 'delivered', 'review_due' => 'return', default => null })
                            @if ($next)<form method="POST" action="{{ route('admin.marketers.samples.update', $sample->id) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline" name="action" :value="$next">{{ ucfirst($next) }}</x-ui.button></form>@endif
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Content')" flush>
        <x-ui.table :headers="[__('Link'), __('Status'), '']" :rows="$campaign->contentSubmissions->isNotEmpty()">
            @foreach ($campaign->contentSubmissions as $submission)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 text-xs"><a class="text-fg-link" href="{{ $submission->url }}" rel="noopener" target="_blank">{{ $submission->platform }} · {{ \Illuminate\Support\Str::limit($submission->url, 50) }}</a></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$submission->status" /></td>
                    <td class="px-5 py-3">@if ($submission->status->value === 'submitted')<form method="POST" action="{{ route('admin.marketers.content.review', $submission->id) }}" class="flex gap-2">@csrf<input name="reason" class="h-8 rounded border border-line px-2 text-xs" placeholder="{{ __('Reason') }}"><x-ui.button type="submit" size="sm" name="decision" value="approve">{{ __('Approve') }}</x-ui.button><x-ui.button type="submit" size="sm" variant="outline" name="decision" value="reject">{{ __('Reject') }}</x-ui.button></form>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('History (Oman time)')">
        <ol class="flex flex-col gap-1 text-sm">
            @foreach ($campaign->transitions as $transition)
                <li>{{ $transition->created_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y H:i') }} — {{ $transition->from_status ?? '∅' }} → <strong>{{ $transition->to_status }}</strong> ({{ $transition->actor_type }}) {{ $transition->reason }}</li>
            @endforeach
        </ol>
    </x-ui.card>
</x-layouts.panel>
