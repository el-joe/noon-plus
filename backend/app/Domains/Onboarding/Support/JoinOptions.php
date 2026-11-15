<?php

namespace App\Domains\Onboarding\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Onboarding\Enums\ApplicationType;
use App\Domains\Settings\SettingsService;

/**
 * Join options per country (PORTAL_PAGES §2, role-permission matrix
 * `join_options`): the sections of the `/join` chooser, each switched on or
 * off per country with the settings key `join_options.{section}` (default
 * from `config('nawy.join_options')`). A disabled section's wizards refuse
 * new applications.
 */
class JoinOptions
{
    public const SECTIONS = ['vendor', 'open_souq', 'travel_agency', 'marketer', 'shipping'];

    public function __construct(private SettingsService $settings) {}

    public function enabled(string $section, Country $country): bool
    {
        return (bool) $this->settings->get('join_options.'.$section, config('nawy.join_options.'.$section, true), $country);
    }

    public function set(string $section, bool $enabled, Country $country, int $adminUserId): void
    {
        $this->settings->set('join_options.'.$section, $enabled, $country, $adminUserId);
    }

    /**
     * The chooser for a country: every section with its application types,
     * wizard steps and whether it is open.
     *
     * @return array<int, array{section: string, label: string, enabled: bool, types: array<int, array{type: string, label: string, steps: array<int, string>}>, roles: array<int, array<string, mixed>>}>
     */
    public function forCountry(Country $country): array
    {
        return array_map(fn (string $section): array => [
            'section' => $section,
            'label' => $this->label($section),
            'enabled' => $this->enabled($section, $country),
            'types' => array_values(array_map(fn (ApplicationType $type): array => [
                'type' => $type->value,
                'label' => $type->label(),
                'steps' => $type->steps(),
            ], array_filter(ApplicationType::cases(), fn (ApplicationType $type): bool => $type->joinSection() === $section))),
            'roles' => $this->roles($section),
        ], self::SECTIONS);
    }

    /**
     * The cards of a section (G-W28): the marketer section splits into
     * influencer, affiliate (both the `marketer` wizard, preselecting
     * `profile.job_types`) and broker (the `broker` wizard); every other
     * section has one card per application type.
     *
     * @return array<int, array{role: string, label: string, type: string, job_types: array<int, string>}>
     */
    public function roles(string $section): array
    {
        if ($section === 'marketer') {
            return [
                ['role' => 'influencer', 'label' => __('Influencer'), 'type' => ApplicationType::Marketer->value, 'job_types' => ['influencer']],
                ['role' => 'affiliate', 'label' => __('Affiliate'), 'type' => ApplicationType::Marketer->value, 'job_types' => ['affiliate']],
                ['role' => 'broker', 'label' => __('Real-estate broker'), 'type' => ApplicationType::Broker->value, 'job_types' => []],
            ];
        }

        return array_values(array_map(fn (ApplicationType $type): array => ['role' => $type->value, 'label' => $type->label(), 'type' => $type->value, 'job_types' => []],
            array_filter(ApplicationType::cases(), fn (ApplicationType $type): bool => $type->joinSection() === $section)));
    }

    public function label(string $section): string
    {
        return match ($section) {
            'vendor' => __('Product seller'),
            'open_souq' => __('Open Souq'),
            'travel_agency' => __('Travel agency'),
            'marketer' => __('Marketer (influencer, affiliate, broker)'),
            'shipping' => __('Shipping partner'),
            default => $section,
        };
    }
}
