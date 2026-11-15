<x-layouts.panel :title="__('New marketer campaign')" :subtitle="__('Invite influencers to promote your products · fees are quoted before you launch and voided if rejected')" :breadcrumbs="[__('Seller Center'), __('Marketer campaigns'), __('New')]">
    <ol class="flex flex-wrap gap-6 text-sm" aria-label="{{ __('Wizard steps') }}">
        @foreach ([__('Goal & products'), __('Marketers'), __('Fees & budget'), __('Contract'), __('Review')] as $i => $step)
            <li class="flex items-center gap-2"><span class="grid size-7 place-items-center rounded-full bg-brand-subtle text-xs font-bold text-fg-brand">{{ $i + 1 }}</span>{{ $step }}</li>
        @endforeach
    </ol>

    @if ($missingContracts->isNotEmpty())
        <div role="alert" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">
            {{ __('Sign the collaboration contract before inviting marketers.') }} <a class="underline" href="{{ route('partner.contracts') }}">{{ __('Open contracts') }}</a>
        </div>
    @endif
    @error('contract')<div role="alert" class="rounded-lg border border-line-danger bg-danger-subtle px-4 py-3 text-sm text-fg-danger">{{ $message }}</div>@enderror

    <form method="POST" action="{{ route('partner.campaigns.store') }}" class="grid gap-6 lg:grid-cols-3" data-campaign-wizard data-quote-url="{{ route('partner.campaigns.quote') }}">
        @csrf
        <div class="flex flex-col gap-6 lg:col-span-2">
            <x-ui.card :title="__('Campaign setup')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input :label="__('Campaign name')" name="title" required />
                    <x-ui.select :label="__('Product')" name="listing_id" :options="$listings->mapWithKeys(fn ($l) => [$l->id => $l->variant->product->title_en.' · '.$l->fulfilment])->all()" :placeholder="__('Choose a listing')" data-quote-input />
                    <x-ui.input :label="__('Ends (your local time)')" name="ends_at" type="datetime-local" />
                    <x-ui.checkbox :label="__('Open to every affiliate and broker of the country')" name="open_to_affiliates" />
                </div>
                <x-ui.textarea :label="__('Notes for marketers')" name="notes" />
            </x-ui.card>

            <x-ui.card :title="__('Invited marketers')" :subtitle="__('Influencers are paid one fixed amount when the campaign completes')">
                @error('influencer_ids')<p class="mb-2 text-xs text-fg-danger">{{ $message }}</p>@enderror
                <div class="grid gap-2 sm:grid-cols-2">
                    @forelse ($influencers as $influencer)
                        <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm">
                            <input type="checkbox" name="influencer_ids[]" value="{{ $influencer->id }}" class="accent-brand" data-quote-input @checked(in_array($influencer->id, old('influencer_ids', [])))>
                            {{ $influencer->display_name }} <span class="text-xs text-fg-secondary">· {{ number_format($influencer->followers) }}</span>
                        </label>
                    @empty
                        <p class="text-sm text-fg-secondary">{{ __('No active influencers in this country yet.') }}</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Fees & budget')">
                <x-ui.input :label="__('Max commission budget (blank = unlimited, then an end date is required)')" name="budget" type="number" step="0.001" min="0" data-quote-input />
                @error('ends_at')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                @error('listing_id')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
            </x-ui.card>
        </div>

        <div class="flex flex-col gap-4">
            <x-ui.card :title="__('Fee quote')" data-fee-quote>
                <dl class="flex flex-col gap-2 text-sm">
                    <div class="flex justify-between"><dt>{{ __('Campaign fee (fixed)') }}</dt><dd data-quote="fixed_fee">—</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Variable fee') }}</dt><dd data-quote="variable_fee">—</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Influencers fixed total') }}</dt><dd data-quote="influencer_fixed_total">—</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 font-semibold"><dt>{{ __('Fee due at go-live') }}</dt><dd data-quote="fee">—</dd></div>
                </dl>
                <p class="mt-3 text-xs text-fg-secondary">{{ __('Charged on your next statement when the campaign goes live; voided if it is rejected or cancelled before.') }}</p>
            </x-ui.card>
            <x-ui.button type="submit">{{ __('Launch campaign') }}</x-ui.button>
        </div>
    </form>

    <script>
        (() => {
            const form = document.querySelector('[data-campaign-wizard]');
            const refresh = async () => {
                const data = new FormData(form);
                if (!data.get('listing_id')) return;
                const response = await fetch(form.dataset.quoteUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': data.get('_token') }, body: data });
                if (!response.ok) return;
                const quote = (await response.json()).data;
                form.querySelectorAll('[data-quote]').forEach((cell) => { cell.textContent = (quote[cell.dataset.quote] ?? '—') + ' ' + quote.currency; });
            };
            form.querySelectorAll('[data-quote-input]').forEach((input) => input.addEventListener('change', refresh));
        })();
    </script>
</x-layouts.panel>
