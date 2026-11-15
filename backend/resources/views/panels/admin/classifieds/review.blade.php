<x-layouts.panel :title="__('Classifieds review')" :subtitle="__('Ads waiting for approval in :country — never public before approval', ['country' => $country->name_en])" :breadcrumbs="[__('Verticals'), __('Classifieds review')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Ad'), __('Seller'), __('Price'), __('Media'), __('Details'), __('Decision')]" :rows="$ads->isNotEmpty()" :empty="__('Nothing waiting for review.')">
            @foreach ($ads as $ad)
                <tr class="border-t border-line align-top" data-ad="{{ $ad->id }}">
                    <td class="px-5 py-3"><span class="font-medium">{{ $ad->title }}</span><span class="block text-xs text-fg-secondary">{{ $ad->number }} · {{ $ad->category->name() }} · {{ $ad->kind }} · {{ $ad->city }}</span></td>
                    <td class="px-5 py-3 text-sm">{{ $ad->vendor?->store_name ?? $ad->marketer?->display_name ?? __('Individual #:id', ['id' => $ad->owner_user_id]) }}</td>
                    <td class="px-5 py-3">@if ($ad->price !== null)<x-ui.money :amount="$ad->price" :currency="$ad->currency" />@else — @endif</td>
                    <td class="px-5 py-3 text-xs">
                        @foreach ($ad->media as $media)
                            <a class="text-fg-link" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($media->path) }}" target="_blank" rel="noopener">{{ $media->type }} {{ $loop->iteration }}</a>@if (! $loop->last), @endif
                        @endforeach
                    </td>
                    <td class="px-5 py-3 text-xs">
                        @foreach ($ad->specs ?? [] as $key => $value){{ $key }}: {{ is_array($value) ? implode(', ', $value) : $value }}<br>@endforeach
                        @if ($ad->property)<span class="font-medium">{{ __('Permit') }}: {{ $ad->property->permit_number }} ({{ $ad->property->permit_authority }})</span>@endif
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex flex-col gap-2">
                            <form method="POST" action="{{ route('admin.classifieds.approve', $ad->id) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Approve') }}</x-ui.button></form>
                            <form method="POST" action="{{ route('admin.classifieds.reject', $ad->id) }}" class="flex gap-2">@csrf
                                <label class="sr-only" for="reason-{{ $ad->id }}">{{ __('Reject reason') }}</label>
                                <input id="reason-{{ $ad->id }}" name="reason" required minlength="5" placeholder="{{ __('Reason (required)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Reject') }}</x-ui.button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $ads->links() }}
</x-layouts.panel>
