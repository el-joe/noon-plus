<x-layouts.panel :title="__('Payment gateways · :country', ['country' => $country->name_en])"
                 :subtitle="__('Choose which gateways customers in :country see at checkout, their order and the recommended one.', ['country' => $country->name_en])"
                 :breadcrumbs="[__('Countries'), $country->name_en, __('Payment gateways')]">
    @php($canUpdate = auth()->user()->can('payment_gateways.update'))

    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-line-danger bg-danger-subtle px-4 py-3 text-sm text-fg-danger">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <x-ui.card :title="__('Enabled gateways')" :subtitle="__('Credentials are encrypted and never sent to the browser or apps.')" flush>
            @if ($canUpdate && $missing !== [])
                <x-slot:actions>
                    <form method="POST" action="{{ route('admin.countries.payment-gateways.store') }}" class="flex items-end gap-2">
                        @csrf
                        <x-ui.select name="gateway" :options="collect($missing)->mapWithKeys(fn ($method) => [$method->value => $method->label()])->all()" :aria-label="__('Gateway')" />
                        <x-ui.button type="submit" variant="outline" size="md">+ {{ __('Add gateway') }}</x-ui.button>
                    </form>
                </x-slot:actions>
            @endif

            <ul class="divide-y divide-line border-t border-line">
                @forelse ($configs as $config)
                    <li class="px-5 py-3" data-gateway="{{ $config->gateway->value }}">
                        <details>
                            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-4">
                                <span class="w-4 text-sm text-fg-muted">{{ $loop->iteration }}</span>
                                <span class="grid h-9 w-16 place-items-center rounded-md border border-line text-[10px] font-semibold uppercase text-fg-secondary">{{ $config->gateway->value }}</span>
                                <span class="flex min-w-40 flex-1 flex-col">
                                    <span class="flex items-center gap-2 font-semibold">
                                        {{ $config->gateway->label() }}
                                        @if ($config->is_recommended)<x-ui.badge tone="warning">{{ __('Recommended') }}</x-ui.badge>@endif
                                    </span>
                                    <span class="text-xs text-fg-secondary">{{ $config->gateway->description() }} · {{ implode(', ', $config->currencies ?? []) }}</span>
                                </span>
                                @if (! $config->is_enabled)
                                    <x-ui.badge>{{ __('Disabled') }}</x-ui.badge>
                                @elseif ($config->mode->value === 'live')
                                    <x-ui.badge tone="success">{{ __('Live') }}</x-ui.badge>
                                @else
                                    <x-ui.badge tone="danger">{{ __('Sandbox') }}</x-ui.badge>
                                @endif
                                <span class="text-sm font-semibold">{{ __('Configure') }}</span>
                            </summary>

                            <form method="POST" action="{{ route('admin.countries.payment-gateways.update', $config->id) }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                                @csrf
                                @method('PUT')
                                <div class="flex items-end"><x-ui.checkbox :label="__('Enabled')" name="is_enabled" :id="'is_enabled-'.$config->id" :checked="$config->is_enabled" /></div>
                                <x-ui.select :label="__('Mode')" name="mode" :id="'mode-'.$config->id" :selected="$config->mode->value" :options="['sandbox' => __('Sandbox'), 'live' => __('Live')]" />
                                <x-ui.input :label="__('Order')" name="sort_order" :id="'sort_order-'.$config->id" type="number" min="0" :value="$config->sort_order" />
                                <x-ui.input :label="__('Currencies')" name="currencies" :id="'currencies-'.$config->id" :value="implode(', ', $config->currencies ?? [])" :helper="__('ISO codes, comma separated')" />
                                <x-ui.input :label="__('Minimum (:currency)', ['currency' => $country->currency])" name="min_amount" :id="'min_amount-'.$config->id" :value="$config->min_amount === null ? null : rtrim(rtrim((string) $config->min_amount, '0'), '.')" />
                                <x-ui.input :label="__('Maximum (:currency)', ['currency' => $country->currency])" name="max_amount" :id="'max_amount-'.$config->id" :value="$config->max_amount === null ? null : rtrim(rtrim((string) $config->max_amount, '0'), '.')" />
                                <x-ui.input :label="__('Fee % (paid by Nawy)')" name="fee_percent" :id="'fee_percent-'.$config->id" :value="$config->fee_rule['percent'] ?? null" />
                                <x-ui.input :label="__('Fixed fee (paid by Nawy)')" name="fee_fixed" :id="'fee_fixed-'.$config->id" :value="$config->fee_rule['fixed'] ?? null" />

                                <fieldset class="grid gap-3 sm:col-span-3 sm:grid-cols-3">
                                    <legend class="mb-2 text-sm font-semibold">{{ __('Credentials') }}</legend>
                                    @foreach ($config->gateway->credentialFields() as $field)
                                        <x-ui.input :label="str_replace('_', ' ', ucfirst($field))" :name="'credentials['.$field.']'" :id="'credential-'.$config->id.'-'.$field"
                                                    type="password" autocomplete="off" value=""
                                                    :placeholder="$config->hasCredential($field) ? __('Saved — leave blank to keep') : __('Not set')" />
                                    @endforeach
                                </fieldset>

                                @if ($canUpdate)
                                    <div class="flex flex-wrap gap-2 sm:col-span-3">
                                        <x-ui.button type="submit" size="sm">{{ __('Save changes') }}</x-ui.button>
                                    </div>
                                @endif
                            </form>

                            @if ($canUpdate && ! $config->is_recommended && $config->is_enabled)
                                <form method="POST" action="{{ route('admin.countries.payment-gateways.recommend', $config->id) }}" class="mt-2">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" variant="outline">{{ __('Make recommended') }}</x-ui.button>
                                </form>
                            @endif
                        </details>
                    </li>
                @empty
                    <li class="px-5 py-4 text-sm text-fg-secondary">{{ __('No gateways yet — customers can only pay cash on delivery.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card :title="__('Customer checkout preview')"
                   :subtitle="__('What a customer in :country sees for a :currency :amount order. Disabled gateways and gateways that cannot take this amount or currency are hidden.', ['country' => $country->name_en, 'currency' => $country->currency, 'amount' => (string) $previewAmount->getAmount()])">
            <form method="GET" class="mb-3 flex items-end gap-2">
                <x-ui.input :label="__('Order amount')" name="preview_amount" :value="(string) $previewAmount->getAmount()" />
                <x-ui.button type="submit" variant="outline" size="md">{{ __('Preview') }}</x-ui.button>
            </form>
            <ul class="flex flex-col gap-2" data-preview>
                @forelse ($preview as $option)
                    <li @class(['flex items-center gap-3 rounded-lg border px-3 py-2', 'border-brand bg-brand-subtle' => $loop->first, 'border-line' => ! $loop->first])>
                        <span @class(['size-4 rounded-full border-2', 'border-brand bg-brand' => $loop->first, 'border-line-strong' => ! $loop->first])></span>
                        <span class="flex flex-col">
                            <span class="flex items-center gap-2 text-sm font-semibold">
                                {{ $option['method']->label() }}
                                @if ($option['recommended'])<x-ui.badge tone="warning">{{ __('Recommended') }}</x-ui.badge>@endif
                            </span>
                            <span class="text-xs text-fg-secondary">
                                {{ $option['method']->description() }}
                                @if ($option['fee'] !== null) · + {{ $country->currency }} {{ (string) $option['fee']->getAmount() }} {{ __('fee') }}@endif
                            </span>
                        </span>
                    </li>
                @empty
                    <li class="text-sm text-fg-secondary">{{ __('No payment method can take this amount.') }}</li>
                @endforelse
            </ul>
            @php($recommended = $configs->firstWhere('is_recommended', true))
            @if ($recommended)
                <div class="mt-3 rounded-lg bg-info-subtle px-3 py-2 text-xs text-fg-info">
                    <p class="font-semibold">{{ __('Recommended for :country', ['country' => $country->name_en]) }}</p>
                    <p>{{ __(':gateway is shown first. Change this with "Make recommended" in Configure.', ['gateway' => $recommended->gateway->label()]) }}</p>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.panel>
