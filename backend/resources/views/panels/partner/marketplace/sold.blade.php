<x-layouts.panel :title="__('Mark as sold')" :subtitle="$ad->title.' · '.$ad->number" :breadcrumbs="[__('My listings'), __('Mark as sold')]">
    <x-ui.card :title="__('Who brought the buyer?')">
        <form method="POST" action="{{ route('partner.marketplace.ads.sold.store', $ad->id) }}" class="grid max-w-xl gap-4">
            @csrf
            <x-ui.input :label="__('Final sold price (:currency)', ['currency' => $currency])" name="final_price" type="number" step="any" min="0" :value="old('final_price', $ad->price)" required />
            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">{{ __('Referring marketer') }}</legend>
                <label class="text-sm"><input type="radio" name="marketer_profile_id" value="" checked> {{ __('Nobody / direct buyer') }}</label>
                @foreach ($referrers as $id => $referrer)
                    <label class="text-sm"><input type="radio" name="marketer_profile_id" value="{{ $id }}"> {{ $referrer['name'] }} · {{ trans_choice(':count inquiry|:count inquiries', $referrer['inquiries'], ['count' => $referrer['inquiries']]) }}</label>
                @endforeach
            </fieldset>
            <x-ui.textarea :label="__('Buyer note (optional)')" name="buyer_note" />
            <div><x-ui.button type="submit">{{ __('Mark as sold') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
