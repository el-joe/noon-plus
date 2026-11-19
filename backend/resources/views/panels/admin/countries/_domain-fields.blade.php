@php($kindOptions = collect(\App\Domains\Countries\Enums\DomainKind::cases())->mapWithKeys(fn ($kind) => [$kind->value => __(\App\Shared\Localization\Label::key($kind))])->all())
<x-ui.input :label="__('Hostname')" name="hostname" :value="old('hostname', $domain?->hostname)" :helper="__('e.g. nawy.om, shop.example.com, admin.omn.nawy.com')" required />
<x-ui.select :label="__('Serves')" name="kind" :selected="old('kind', $domain?->kind?->value ?? 'storefront')" :options="$kindOptions" />
<x-ui.select :label="__('Country')" name="country_id" :selected="old('country_id', $domain?->country_id)" :options="['' => __('Any country')] + $countries->mapWithKeys(fn ($country) => [$country->id => $country->name_en])->all()" />
<x-ui.select :label="__('Language (storefront)')" name="locale" :selected="old('locale', $domain?->locale)" :options="['' => __('Country default'), 'en' => __('English'), 'ar' => __('Arabic')]" />
<x-ui.select :label="__('Panel')" name="panel" :selected="old('panel', $domain?->panel)" :options="['' => '—'] + $panels" />
<x-ui.input :label="__('Redirect to host')" name="redirect_to" :value="old('redirect_to', $domain?->redirect_to)" :helper="__('Optional: answer 301 to this host instead')" />
<x-ui.select :label="__('TLS certificate')" name="tls_status" :selected="old('tls_status', $domain?->tls_status ?? 'pending')" :options="['pending' => __('Pending'), 'issued' => __('Issued'), 'failed' => __('Failed')]" />
<x-ui.input :label="__('Note')" name="note" :value="old('note', $domain?->note)" />
<div class="flex flex-col justify-end gap-2">
    <x-ui.checkbox :label="__('Primary host for its country')" name="is_primary" :checked="(bool) old('is_primary', $domain?->is_primary ?? false)" />
    <x-ui.checkbox :label="__('Active')" name="is_active" :checked="(bool) old('is_active', $domain?->is_active ?? true)" />
    <x-ui.checkbox :label="__('DNS verified')" name="dns_verified" :checked="(bool) old('dns_verified', $domain?->dns_verified_at !== null)" />
</div>
