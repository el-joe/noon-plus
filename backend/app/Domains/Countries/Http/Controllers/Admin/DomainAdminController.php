<?php

namespace App\Domains\Countries\Http\Controllers\Admin;

use App\Domains\Countries\Enums\DomainKind;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\Domain;
use App\Domains\Countries\Support\DomainMap;
use App\Domains\Panels\Support\Panel;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Countries & gateways → Domains (QC-044, MASTER_PLAN §5): map
 * hostnames to a storefront market (custom domain / ccTLD), a panel alias
 * (optionally locked to one country, `{panel}.{cc}.{base}`), the portal or
 * an API host; one primary storefront host per country is used for email
 * and payment-return links. DNS / TLS steps: docs/deploy/domains.md.
 * Audited (activity log "domains"); the cached map is forgotten on save.
 */
class DomainAdminController extends Controller
{
    public function index(Request $request, DomainMap $map): View
    {
        $filters = $request->validate(['kind' => ['nullable', Rule::enum(DomainKind::class)], 'country' => ['nullable', 'integer']]);

        return view('panels.admin.countries.domains', [
            'domains' => Domain::query()->with('country')
                ->when($filters['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
                ->when($filters['country'] ?? null, fn ($query, int $country) => $query->where('country_id', $country))
                ->orderBy('kind')->orderBy('hostname')->get(),
            'countries' => Country::query()->orderBy('sort_order')->get(),
            'panels' => $this->panels(),
            'map' => $map,
            'filters' => $filters,
        ] + $this->targets());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save(new Domain, $request);

        return back()->with('status', __('Domain added.'));
    }

    public function edit(int $domain): View
    {
        return view('panels.admin.countries.domain-form', [
            'domain' => Domain::query()->findOrFail($domain),
            'countries' => Country::query()->orderBy('sort_order')->get(),
            'panels' => $this->panels(),
        ] + $this->targets());
    }

    public function update(Request $request, int $domain): RedirectResponse
    {
        $this->save(Domain::query()->findOrFail($domain), $request);

        return redirect()->route('admin.countries.domains')->with('status', __('Domain saved.'));
    }

    public function destroy(int $domain): RedirectResponse
    {
        Domain::query()->findOrFail($domain)->delete();

        return redirect()->route('admin.countries.domains')->with('status', __('Domain removed.'));
    }

    private function save(Domain $domain, Request $request): void
    {
        $request->merge([
            'hostname' => DomainMap::normalise($request->input('hostname')) ?? $request->input('hostname'),
            'redirect_to' => filled($request->input('redirect_to')) ? (DomainMap::normalise($request->input('redirect_to')) ?? $request->input('redirect_to')) : null,
        ]);
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253', Rule::unique(Domain::class, 'hostname')->ignore($domain->id)],
            'kind' => ['required', Rule::enum(DomainKind::class)],
            'country_id' => ['nullable', 'integer', Rule::exists(Country::class, 'id'), Rule::requiredIf($request->input('kind') === DomainKind::Storefront->value)],
            'locale' => ['nullable', 'string', 'size:2'],
            'panel' => ['nullable', Rule::in(array_keys($this->panels())), Rule::requiredIf($request->input('kind') === DomainKind::Panel->value)],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
            'redirect_to' => ['nullable', 'string', 'max:253', 'different:hostname'],
            'tls_status' => ['required', Rule::in(Domain::TLS_STATUSES)],
            'dns_verified' => ['boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $kind = DomainKind::from($data['kind']);
        $country = isset($data['country_id']) ? Country::query()->find($data['country_id']) : null;
        $errors = [];

        if (! DomainMap::isHostname($data['hostname'])) {
            $errors['hostname'] = __('Enter a hostname such as shop.example.com (no scheme, path or port).');
        } elseif ($this->isReserved($data['hostname'])) {
            $errors['hostname'] = __('This host is already a built-in panel or API host.');
        }

        if (($data['redirect_to'] ?? null) !== null && ! DomainMap::isHostname($data['redirect_to'])) {
            $errors['redirect_to'] = __('Enter a hostname such as shop.example.com (no scheme, path or port).');
        }

        if ($kind === DomainKind::Storefront && $country !== null && filled($data['locale'] ?? null) && ! in_array($data['locale'], $country->locales ?? [], true)) {
            $errors['locale'] = __(':country is not offered in this language.', ['country' => $country->name_en]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $isPrimary = (bool) ($data['is_primary'] ?? false) && ($data['redirect_to'] ?? null) === null;

        DB::transaction(function () use ($domain, $data, $kind, $isPrimary, $request): void {
            if ($isPrimary) {
                // One primary host per kind, country (and panel).
                Domain::query()->where('kind', $kind)->where('country_id', $data['country_id'] ?? null)
                    ->when($kind === DomainKind::Panel, fn ($query) => $query->where('panel', $data['panel']))
                    ->whereKeyNot($domain->id ?? 0)->where('is_primary', true)
                    ->each(fn (Domain $other) => $other->update(['is_primary' => false]));
            }

            $domain->fill([
                'hostname' => $data['hostname'],
                'kind' => $kind,
                'country_id' => $data['country_id'] ?? null,
                'locale' => $kind === DomainKind::Storefront ? ($data['locale'] ?? null) : null,
                'panel' => $kind === DomainKind::Panel ? $data['panel'] : null,
                'is_primary' => $isPrimary,
                'is_active' => (bool) ($data['is_active'] ?? false),
                'redirect_to' => $data['redirect_to'] ?? null,
                'tls_status' => $data['tls_status'],
                'dns_verified_at' => ($data['dns_verified'] ?? false) ? ($domain->dns_verified_at ?? now()) : null,
                'note' => $data['note'] ?? null,
                'updated_by' => $request->user()->id,
            ])->save();
        });
    }

    private function isReserved(string $host): bool
    {
        if ($host === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return true;
        }

        foreach (Panel::all() as $panel) {
            if ($panel->host === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function panels(): array
    {
        return collect(Panel::all())->mapWithKeys(fn (Panel $panel): array => [$panel->key => $panel->name.' ('.$panel->host.')'])->all();
    }

    /**
     * @return array{storefrontTarget: string|null, backendTarget: string|null}
     */
    private function targets(): array
    {
        return ['storefrontTarget' => config('nawy.domains.storefront_target'), 'backendTarget' => config('nawy.domains.backend_target')];
    }
}
