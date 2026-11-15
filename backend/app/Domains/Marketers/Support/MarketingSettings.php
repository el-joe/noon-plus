<?php

namespace App\Domains\Marketers\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Settings\SettingsService;

/**
 * Marketing settings for the current country: the SRS settings key
 * (country override, then global) or the default in `config('nawy.marketing')`.
 */
class MarketingSettings
{
    /**
     * Config key => settings key (SRS §20.1 names where they exist).
     */
    public const KEYS = [
        'auto_approve_hours' => 'marketer_campaign_auto_approve_hours',
        'invitation_timeout_hours' => 'marketer_invitation_timeout_hours',
        'replacement_min_accepted' => 'marketer_replacement_min_accepted_campaigns',
        'attribution_window_days' => 'marketer_attribution_window_days',
        'referral_grace_days' => 'marketer_referral_grace_days',
        'clearing_days' => 'marketer_payout_clearing_days',
        'peer_min_stock' => 'marketer_peer_campaign_min_stock',
        'min_platform_sample_qty' => 'marketer_campaign_min_platform_sample_qty',
        'min_influencer_sample_qty' => 'marketer_campaign_min_influencer_sample_qty',
        'allowed_fulfilment_models' => 'marketer_campaign_allowed_fulfilment_models',
        'whatsapp_resend_cooldown_minutes' => 'marketer_whatsapp_resend_cooldown_minutes',
        'min_stock_for_campaign' => 'min_stock_for_campaign',
        'campaign_fee_refund_policy' => 'campaign_fee_refund_policy',
        'attribution_model' => 'attribution_model',
        'peer_per_owner_per_month' => 'cross_promotion.peer_per_owner_per_month',
        'flash_sale_bonus_percent' => 'flash_sale.marketer_bonus',
        'withdrawal_minimum' => 'marketer.withdrawal_minimum',
        'sample_review_days' => 'marketer.sample_review_days',
        'cross_promotion_enabled' => 'cross_promotion.enabled',
        'clawback_enabled' => 'clawback.enabled',
    ];

    public function __construct(private SettingsService $settings, private CountryContext $countryContext) {}

    public function get(string $key): mixed
    {
        return $this->settings->get(self::KEYS[$key] ?? $key, config("nawy.marketing.{$key}"), $this->countryContext->has() ? $this->countryContext->current() : null);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $this->settings->set(self::KEYS[$key] ?? $key, $value, $this->countryContext->current(), $userId);
    }
}
