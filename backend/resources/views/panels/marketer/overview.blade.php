@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="__('Hi :name', ['name' => $marketer->display_name])" :subtitle="implode(' · ', array_map('ucfirst', $marketer->job_types)).' · '.__('payouts in :currency', ['currency' => $currency])" :breadcrumbs="[__('Creator Studio'), __('Overview')]">
    <x-slot:actions>
        <x-ui.button variant="outline" :href="route('marketer.links')">{{ __('Copy my link') }}</x-ui.button>
        <x-ui.button :href="route('marketer.marketplace')">{{ __('Browse campaigns') }}</x-ui.button>
    </x-slot:actions>

    @if ($agencyMarketers->count() > 0)
        <form method="POST" action="{{ route('marketer.agency.switch') }}" class="flex items-end gap-2">
            @csrf
            <x-ui.select :label="__('Acting as')" name="marketer_id" :options="$agencyMarketers->pluck('display_name', 'id')->all()" :selected="$marketer->id" />
            <x-ui.button type="submit" variant="outline">{{ __('Switch') }}</x-ui.button>
        </form>
    @endif

    @if ($marketer->global_status->value !== 'active')
        <div role="status" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ __('Your account is :status. You can take campaigns once Nawy approves it.', ['status' => $marketer->global_status->value]) }}</div>
    @endif
    @unless ($contractAccepted)
        <div role="alert" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ __('Accept the marketer contract to take campaigns.') }} <a class="underline" href="{{ route('marketer.contract') }}">{{ __('Open contract') }}</a></div>
    @endunless

    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Earnings (30 days)')" :value="$fmt->format((string) $earnings->getAmount(), $currency)" />
        <x-ui.stat-card :label="__('Clicks')" :value="number_format($clicks)" />
        <x-ui.stat-card :label="__('Orders')" :value="number_format($orders)" />
        <x-ui.stat-card :label="__('Pending clawback')" :value="$fmt->format((string) $clawback->getAmount(), $currency)" />
    </section>

    <section class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('Earnings by day')" class="lg:col-span-2">
            @php($max = max(1, (float) collect($daily)->max('total')))
            <div class="flex h-36 items-end gap-2" role="img" aria-label="{{ __('Earnings over the last 14 days') }}">
                @foreach ($daily as $day)
                    <div class="w-full rounded-t bg-brand" style="height: {{ max(4, (int) round(100 * (float) $day['total'] / $max)) }}%" title="{{ $day['day'] }}: {{ $day['total'] }}"></div>
                @endforeach
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Your code')" data-referral-card>
            @if ($topCode)
                <p class="text-2xl font-bold font-mono">{{ $topCode->referral_code }}</p>
                <p class="text-xs text-fg-link break-all">{{ rtrim(config('nawy.payments.storefront_url'), '/') }}/r/{{ $topCode->referral_code }}</p>
            @else
                <p class="text-sm text-fg-secondary">{{ __('Accept an invitation to get your referral code.') }}</p>
            @endif
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Campaign invitations')" flush>
        <x-ui.table :headers="[__('Campaign'), __('Brand'), __('Model'), __('Status')]" :rows="$invitations->isNotEmpty()" :empty="__('No invitations yet.')">
            @foreach ($invitations as $invitation)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $invitation->campaign->title }}</td>
                    <td class="px-5 py-3 text-xs">{{ $invitation->campaign->vendor?->store_name ?? 'Nawy' }}</td>
                    <td class="px-5 py-3 text-xs">{{ ucfirst($invitation->effective_type) }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$invitation->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
