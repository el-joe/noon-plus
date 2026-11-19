<?php

namespace App\Domains\Migration\Support;

/**
 * Legacy setting keys → new settings keys (plan §6 "Settings", MG1). An
 * existing global value in the new system is kept (linked, never
 * overwritten). Credentials are never copied: they are re-entered in the new
 * admin (plan §2.6). Keys not listed here are archived and reported.
 */
class SettingKeyMap
{
    public const SECRET = '__secret__';

    /**
     * @var array<string, string>
     */
    public const MAP = [
        'marketer_campaign_auto_approve_hours' => 'marketer_campaign_auto_approve_hours',
        'marketer_invitation_timeout_hours' => 'marketer_invitation_timeout_hours',
        'marketer_replacement_min_accepted_campaigns' => 'marketer_replacement_min_accepted_campaigns',
        'marketer_referral_grace_days' => 'marketer_referral_grace_days',
        'marketer_whatsapp_resend_cooldown_minutes' => 'marketer_whatsapp_resend_cooldown_minutes',
        'marketer_peer_campaign_min_stock' => 'marketer_peer_campaign_min_stock',
        'join_option_product_vendor_enabled' => 'join_options.vendor',
        'join_option_open_souq_enabled' => 'join_options.open_souq',
        'join_option_travel_agency_enabled' => 'join_options.travel_agency',
        'join_option_marketer_enabled' => 'join_options.marketer',
    ];

    /**
     * @var array<int, string>
     */
    public const SECRETS = [
        'whatsapp_meta_access_token', 'whatsapp_meta_phone_number_id', 'mail_password', 'mail_username', 'mail_host',
    ];

    public static function target(string $legacyKey): ?string
    {
        if (in_array($legacyKey, self::SECRETS, true) || str_contains($legacyKey, 'password') || str_contains($legacyKey, 'secret') || str_ends_with($legacyKey, '_token')) {
            return self::SECRET;
        }

        return self::MAP[$legacyKey] ?? null;
    }
}
