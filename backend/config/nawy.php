<?php

/*
|--------------------------------------------------------------------------
| Nawy platform configuration
|--------------------------------------------------------------------------
|
| Country reference data is the seed for the central `countries` table
| (MASTER_PLAN §4a). Runtime code reads countries from the database; this
| file only provides the defaults used when provisioning.
|
*/

return [

    'base_domain' => env('NAWY_BASE_DOMAIN', 'nawy.test'),

    /*
    | QC-044 domain mapping (Admin → Countries → Domains): the DNS targets
    | shown for new hostnames (CNAME / ALIAS) — storefront hosts point at the
    | website edge, panel / API hosts at the backend. See docs/deploy/domains.md.
    */
    'domains' => [
        'storefront_target' => env('NAWY_DOMAINS_STOREFRONT_TARGET'),
        'backend_target' => env('NAWY_DOMAINS_BACKEND_TARGET'),
    ],

    'admin_timezone' => env('NAWY_ADMIN_TIMEZONE', 'Asia/Muscat'),

    'ops_alert_email' => env('NAWY_OPS_ALERT_EMAIL'),

    'country_connection_prefix' => 'country_',

    'country_database_prefix' => env('NAWY_COUNTRY_DB_PREFIX', 'nawy_'),

    /*
    | Business panels: host prefix, display name and the membership roles that
    | may sign in (MASTER_PLAN §5). The public portal (business.) is Next.js.
    */
    'panels' => [
        'admin' => ['host' => 'admin', 'name' => 'Admin', 'roles' => ['admin']],
        'partner' => ['host' => 'partner', 'name' => 'Seller Center', 'roles' => ['vendor:product', 'vendor:classified']],
        'marketer' => ['host' => 'marketer', 'name' => 'Creator Studio', 'roles' => ['marketer:influencer', 'marketer:affiliate', 'marketer:broker', 'marketer:agency']],
        'travel_agency' => ['host' => 'travel-agency', 'name' => 'Travel Partner', 'roles' => ['travel_agency']],
        'carrier' => ['host' => 'carrier', 'name' => 'Shipping', 'roles' => ['carrier_supervisor']],
        'delivery' => ['host' => 'delivery', 'name' => 'Delivery', 'roles' => ['delivery_agent']],
    ],

    // Blade panel sign-in: how long the pending two-step challenge (after a correct password) stays valid.
    'panel_auth' => [
        'two_factor_challenge_minutes' => (int) env('NAWY_PANEL_2FA_CHALLENGE_MINUTES', 10),
    ],

    /*
    | Checkout defaults per country (amounts VAT-inclusive, in the country
    | currency), overridable per country with the settings keys
    | "checkout.{key}". `shipping_fee_per_seller` is only the fallback used
    | when no shipping zone or rate matches the destination (SRS §8).
    */
    /*
    | API token lifetimes per app (QC-047 / QA-036); `sanctum:prune-expired` runs daily.
    */
    'onboarding' => [
        'email_code_minutes' => 30,                  // partner application email code (QA-002)
    ],

    'tokens' => [
        'customer_days' => (int) env('NAWY_CUSTOMER_TOKEN_DAYS', 90),
        'delivery_days' => (int) env('NAWY_DELIVERY_TOKEN_DAYS', 30),
        'ops_days' => (int) env('NAWY_OPS_TOKEN_DAYS', 30),
    ],

    'checkout' => [
        'cart_lock_minutes' => 15,
        'cart_lock_max_minutes' => 60,               // a hold is never renewed past this (QA-010)
        'guest_hold_max_quantity' => 3,              // units a guest cart line holds (QA-010)
        'guest_hold_max_per_ip' => 6,                // units of one listing all guest carts from one IP hold together (RT-002)
        'guest_carts_per_hour' => 10,                // new guest carts one IP may open per hour (RT-002)
        'guest_cart_writes_per_minute' => 30,
        'defaults' => [
            'uae' => ['shipping_fee_per_seller' => '10.00', 'free_shipping_threshold' => '100.00', 'cod_fee' => '10.00'],
            'omn' => ['shipping_fee_per_seller' => '1.000', 'free_shipping_threshold' => '10.000', 'cod_fee' => '1.000'],
            'ksa' => ['shipping_fee_per_seller' => '10.00', 'free_shipping_threshold' => '100.00', 'cod_fee' => '10.00'],
            'egy' => ['shipping_fee_per_seller' => '50.00', 'free_shipping_threshold' => '500.00', 'cod_fee' => '20.00'],
            'kwt' => ['shipping_fee_per_seller' => '1.000', 'free_shipping_threshold' => '10.000', 'cod_fee' => '0.500'],
            'qat' => ['shipping_fee_per_seller' => '10.00', 'free_shipping_threshold' => '100.00', 'cod_fee' => '10.00'],
            'bhr' => ['shipping_fee_per_seller' => '1.000', 'free_shipping_threshold' => '10.000', 'cod_fee' => '0.500'],
            'jor' => ['shipping_fee_per_seller' => '2.000', 'free_shipping_threshold' => '20.000', 'cod_fee' => '1.000'],
        ],
    ],

    /*
    | Shipping engine defaults (settings catalogue §6), overridable per
    | country with the settings keys "shipping.{key}".
    */
    'shipping' => [
        'volumetric_divisor' => 5000,
        'eta_cutoff_time' => '14:00',
        'fallback_transit_days' => ['min' => 2, 'max' => 4],
    ],

    /*
    | Order defaults (settings catalogue §4). The return window can be set per
    | category (inheriting the parent); this is the country default, setting
    | key "return_window_days".
    */
    /*
    | Reviews (SRS §7.8, lifecycle §2.9; QC-012). Setting
    | `reviews.auto_flag_rules` overrides the keyword list; a matching review
    | is created `auto_flagged` for moderation instead of `pending`.
    */
    /*
    | Analytics (QC-015): raw ad events are kept this long after the daily
    | rollup (setting `analytics.raw_retention_days`).
    */
    'analytics' => [
        'raw_retention_days' => 180,
        // FR-CTY-10 / ADR 2: nightly load of `nawy_reporting` (setting `analytics.reporting_etl_time`, admin time).
        'reporting_etl_time' => '01:30',
        // FR-ANL-2: celebrity (influencer) monthly report for the previous month, on the 1st (admin time).
        'celebrity_report_time' => '06:00',
    ],

    'reviews' => [
        'auto_flag_rules' => [
            'words' => ['fuck', 'shit', 'bitch', 'scam', 'whatsapp me', 'كس', 'زب', 'شرموط', 'نصاب'],
            'flag_links' => true,
        ],
    ],

    'orders' => [
        'return_window_days' => 14,
        'auto_complete_time' => '02:00',
    ],

    /*
    | Flash sale defaults (settings catalogue §9), setting keys "flash_sale.{key}".
    */
    /*
    | Discovery (QA wave 5a, QC-043 / QC-064 / QC-057): popular and trending
    | searches, recently viewed, frequently bought together and badges.
    */
    'discovery' => [
        'popular_window_days' => 30,          // popular searches look this far back
        'popular_min_hits' => 3,              // a query needs this many searches to be shown
        'trending_recent_days' => 3,          // trending: searches per day in the last N days…
        'trending_baseline_days' => 14,       // …against the N days before them
        'recently_viewed_max' => 50,          // products kept per customer
        'fbt_window_days' => 90,              // order co-occurrence window
        'fbt_min_orders' => 2,                // orders that must share both products
        'bestseller_badge_top' => 20,         // bestseller_rank 1…N gets the badge
        'new_badge_days' => 30,
        'top_rated_min_reviews' => 10,
        'top_rated_min_average' => '4.50',
    ],

    'flash_sale' => [
        'max_qty_per_customer' => 2,
    ],

    /*
    | Seller Center (SRS §4.3, §5.2, §7.1). A seller cancellation records a
    | strike worth `cancellation_strike_points`; the score is 100 minus the
    | points of the last `score_window_days` days (proposed defaults).
    */
    'vendors' => [
        'cancellation_strike_points' => 5,
        'score_window_days' => 90,
        'listing_conditions' => ['new', 'refurbished', 'used'],
        'fulfilment_models' => ['fbm', 'fbn', 'cross_dock'],
        'stock_reasons' => ['restock', 'correction', 'damaged', 'lost'],
    ],

    /*
    | Exchange rates (central `exchange_rates`, Admin → Countries → Exchange
    | rates). `driver`: manual (admins only, default), log (logs the sync),
    | openexchangerates (needs the owner's NAWY_EXCHANGE_RATES_APP_ID; see
    | docs/deploy/exchange-rates.md). `quotes`: currencies rates are kept
    | against; the sync job (`nawy:exchange-rates:sync`, daily 00:05 Oman)
    | stores every active country currency → each quote.
    */
    'exchange_rates' => [
        'driver' => env('NAWY_EXCHANGE_RATES_DRIVER', 'manual'),
        'app_id' => env('NAWY_EXCHANGE_RATES_APP_ID'),
        'quotes' => ['USD'],
        'sync_time' => '00:05',
    ],

    /*
    | Payments (ADR 6, FR-CHK-3, settings catalogue §5). `driver` "fake" swaps
    | every gateway for the deterministic FakeGateway (tests, local without
    | keys); "http" uses the real adapters (sandbox or live per country).
    | `pending_expiry_minutes` is the hold window for unpaid card/BNPL orders
    | (setting `payment.pending_expiry_minutes`), `retry_max_attempts` the
    | payment attempts per order (setting `payment.retry_max_attempts`).
    | `storefront_url` is the customer website origin (NAWY_STOREFRONT_URL;
    | `http://localhost:3000` for the Next.js dev server): the gateway return
    | redirect, referral links and newsletter confirmation links point there.
    */
    'payments' => [
        'driver' => env('NAWY_PAYMENTS_DRIVER', 'http'),
        'pending_expiry_minutes' => 30,
        'retry_max_attempts' => 3,
        'storefront_url' => env('NAWY_STOREFRONT_URL', 'http://nawy.test'),
        'http_timeout_seconds' => 20,
        // Driver "fake" only: HMAC secret of fake webhooks when the country has no webhook_secret credential (no built-in default).
        'fake_webhook_secret' => env('NAWY_FAKE_WEBHOOK_SECRET'),
        // In-app payments: the gateway return of a payment started from an app lands on the app's deep link
        // (`?payment={reference}&status={status}` appended) instead of the web. Only these server-side values are used.
        'app_return_urls' => [
            'travel_agency' => env('NAWY_TRAVEL_APP_RETURN_URL', 'nawytravel://promotions/return'),
        ],

        /*
        | Gateway defaults per country (MASTER_PLAN §4a; first = recommended ★).
        | Seeded by PaymentGatewaySeeder in sandbox mode without credentials.
        | PayPal is seeded disabled: PayPal does not settle AED/OMR/SAR, so it
        | charges in USD at the admin-maintained exchange rate (below) and can
        | be enabled per country once a rate {currency}→USD exists.
        */
        'country_gateways' => [
            'uae' => ['stripe', 'paytabs', 'paypal', 'tabby', 'tamara'],
            'omn' => ['thawani', 'paytabs', 'stripe', 'paypal'],
            'ksa' => ['paytabs', 'stripe', 'paypal', 'tabby', 'tamara'],
            'egy' => ['paytabs', 'stripe'],
            'kwt' => ['paytabs', 'stripe'],
            'qat' => ['paytabs', 'stripe'],
            'bhr' => ['paytabs', 'stripe'],
            'jor' => ['paytabs', 'stripe'],
        ],
        'seeded_disabled' => ['paypal'],

        /*
        | PayPal USD conversion: a country currency PayPal does not accept is
        | charged in `charge_currency` at the current exchange rate (Admin →
        | Countries → Exchange rates); the rate is stored on the payment and
        | refunds use it. `native_currencies` are charged as they are.
        */
        'paypal' => [
            'charge_currency' => 'USD',
            'native_currencies' => ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'CHF', 'CZK', 'DKK', 'HKD', 'ILS', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB'],
        ],

        /*
        | Proposed BNPL limits per currency (provider defaults to be confirmed
        | with Tabby/Tamara merchant onboarding).
        */
        'amount_limits' => [
            'tabby' => ['AED' => ['10.00', '5000.00'], 'SAR' => ['10.00', '5000.00'], 'KWD' => ['1.000', '500.000']],
            'tamara' => ['AED' => ['100.00', '5000.00'], 'SAR' => ['100.00', '5000.00'], 'KWD' => ['10.000', '500.000']],
        ],
    ],

    /*
    | Money (WP4, settings catalogue §8–§10, SRS §7, §9–§10). Every value is
    | the default of a settings key (country override wins), read through
    | App\Domains\Finance\Support\FinanceSettings. Proposed values where the
    | catalogue says TBD — owner review.
    */
    'finance' => [
        'default_commission_percent' => '10.00',      // category.commission_percent fallback
        'promotional_credit_expiry_days' => 90,       // promotional_credit.default_expiry_days
        'payout_minimum_amount' => '0',               // payout.minimum_amount
        'payout_require_four_eyes' => true,           // payout.require_four_eyes
        'fixed_fee_per_sub_order' => '0',             // payout.fixed_fee_per_sub_order (proposed)
        'fbn_fee_per_unit' => '0',                    // payout.fbn_fee_per_unit (proposed)
        'refund_cod_to_wallet' => true,               // refund.cod_to_wallet
        'standard_warranty_months' => 12,             // warranty.standard_months (proposed)
        'cod_settlement_time' => '23:30',             // cod.settlement_time
        'payout_generation_time' => '02:00',
        'membership_grace_days' => 7,                 // subscription.grace_days (Nawy One and vendor plans)
        'subscription_deduct_unpaid_from_payout' => false, // subscription.deduct_unpaid_from_payout: past_due vendor plan after the grace → payout deduction instead of expiry
        'subscription_billing_time' => '02:15',       // vendor plan billing job (admin time)
        'subscription_auto_charge_retries' => 3,      // subscription.auto_charge_retries: retries of a declined auto-renew card charge
        'subscription_auto_charge_retry_hours' => 24, // subscription.auto_charge_retry_hours: wait between those retries
        'gift_card_expiry_months' => 12,              // gift_card.default_expiry_months
        'gift_card_designs' => ['classic', 'birthday', 'eid', 'ramadan', 'thank_you', 'congrats', 'wedding', 'new_baby'], // G-W30; first = default
        'gift_card_limits' => [                       // gift_card.custom_amount_min / max
            'AED' => ['25.00', '5000.00'], 'SAR' => ['25.00', '5000.00'], 'EGP' => ['100.00', '50000.00'], 'QAR' => ['25.00', '5000.00'],
            'OMR' => ['2.000', '500.000'], 'KWD' => ['2.000', '500.000'], 'BHD' => ['2.000', '500.000'], 'JOD' => ['5.000', '1000.000'],
        ],
    ],

    /*
    | Fulfilled by Nawy (MASTER_PLAN §8). Fee placeholders follow the help
    | centre "FBN fees" article (outbound fee per unit by weight tier and
    | selling-price band, storage per cubic foot per month, long-term and
    | unsellable storage, removal fee) in AED; other currencies get the AED
    | value × `currency_factor`, rounded half-up. Admins override the fee
    | table per country (setting `fbn.fees`); the operational values are
    | read through App\Domains\Fulfilment\Support\FbnSettings (setting
    | `fbn.{key}`, country override wins). Owner decision F11 (confirmed
    | 2026-11-19): every FBN fee defaults to 0; the tiers keep the help-centre
    | structure so an admin can switch fees on per country.
    */
    'fbn' => [
        'fees' => [
            'outbound_price_threshold' => '0',       // ASP band: ≤ threshold uses `low`, above uses `high`
            'outbound_tiers' => [                     // billable weight ≤ max_grams → fee per unit
                ['max_grams' => 100, 'low' => '0', 'high' => '0'],
                ['max_grams' => 250, 'low' => '0', 'high' => '0'],
                ['max_grams' => 500, 'low' => '0', 'high' => '0'],
                ['max_grams' => 1000, 'low' => '0', 'high' => '0'],
                ['max_grams' => 1500, 'low' => '0', 'high' => '0'],
                ['max_grams' => 2000, 'low' => '0', 'high' => '0'],
                ['max_grams' => 3000, 'low' => '0', 'high' => '0'],
                ['max_grams' => 20000, 'low' => '0', 'high' => '0'],
            ],
            'outbound_extra_per_kg' => '0',         // above the last tier, per started kg
            'storage_per_cubic_foot' => '0',        // per cubic foot per month
            'long_term_per_cubic_foot' => '0',       // per cubic foot per month, units older than long_term_days
            'unsellable_per_cubic_foot' => '0',      // per cubic foot per month, damaged older than unsellable_days
            'removal_minimum' => '0',                // per removal order
            'removal_per_unit' => '0',
            'removal_per_kg' => '0',                // billable kg (actual or L×W×H/5000, the higher)
            'disposal_per_unit' => '0',
        ],
        'currency_factor' => ['AED' => '1', 'SAR' => '1', 'QAR' => '1', 'EGP' => '13', 'OMR' => '0.1', 'KWD' => '0.08', 'BHD' => '0.1', 'JOD' => '0.2'],
        'long_term_days' => 365,
        'unsellable_days' => 30,
        'handling_days' => 0,                         // warehouse processing days used in the delivery estimate
        'inbound_reminder_days' => 3,                 // submitted but not shipped after N days → reminder
        'low_stock_threshold' => 5,
        'removal_volumetric_divisor' => 5000,
        'default_unit_cubic_feet' => '0.1',           // when a variant has no dimensions
    ],

    /*
    | Marketers & campaigns (WP5, SRS §20.1, settings catalogue §10). Each
    | value is the default of the settings key named beside it (country
    | override wins), read through App\Domains\Marketers\Support\MarketingSettings.
    | Proposed values where the catalogue says TBD — owner review (WP5).
    */
    'marketing' => [
        'auto_approve_hours' => 36,                   // marketer_campaign_auto_approve_hours
        'invitation_timeout_hours' => 12,             // marketer_invitation_timeout_hours
        'replacement_min_accepted' => 0,              // marketer_replacement_min_accepted_campaigns
        'attribution_window_days' => 7,               // marketer_attribution_window_days
        'referral_grace_days' => 3,                   // marketer_referral_grace_days (proposed)
        'clearing_days' => 14,                        // marketer_payout_clearing_days (proposed)
        'peer_min_stock' => 1,                        // marketer_peer_campaign_min_stock
        'min_platform_sample_qty' => 1,               // marketer_campaign_min_platform_sample_qty
        'min_influencer_sample_qty' => 1,             // marketer_campaign_min_influencer_sample_qty
        'allowed_fulfilment_models' => ['fbn', 'fbm'], // marketer_campaign_allowed_fulfilment_models (proposed)
        'whatsapp_resend_cooldown_minutes' => 10,     // marketer_whatsapp_resend_cooldown_minutes
        'min_stock_for_campaign' => 10,               // min_stock_for_campaign (category overrides)
        'campaign_fee_refund_policy' => 'none',       // campaign_fee_refund_policy: none | before_first_conversion | pro_rata
        'attribution_model' => 'last_click',          // attribution_model: last_click | first_click | linear
        'peer_per_owner_per_month' => 1,              // cross_promotion.peer_per_owner_per_month
        'flash_sale_bonus_percent' => '0',            // flash_sale.marketer_bonus (percent of basis, proposed)
        'withdrawal_minimum' => '0',                  // marketer.withdrawal_minimum (proposed)
        'sample_review_days' => 7,                    // marketer.sample_review_days (proposed)
        'cross_promotion_enabled' => false,           // cross_promotion.enabled
        'clawback_enabled' => true,                   // clawback.enabled
        'campaign_end_time' => '04:00',
        'approve_conversions_time' => '03:15',
        'release_commissions_time' => '03:30',
    ],

    /*
    | Verticals (WP6, settings catalogue §11–§12). Each value is the default
    | of the settings key "{group}.{key}" (country override wins), read
    | through App\Shared\Settings\VerticalSettings. Proposed values where
    | the catalogue says TBD — owner review (WP6).
    */
    'classified' => [
        'listing_duration_days' => 30,               // classified.listing_duration_days (category overrides)
        'renewal_reminder_days' => [3, 1],           // classified.renewal_reminder_days
        'renewal_price' => '0',                      // classified.renewal_price (category overrides)
        'inquiry_throttle_per_minute' => 5,          // classified.inquiry_throttle_per_minute
        'report_reasons' => ['fraud', 'wrong_category', 'sold', 'offensive', 'duplicate', 'other'],
        'max_compare' => 4,
        'video_max_seconds' => 60,
        'video_max_kb' => 51200,
        'photo_max_kb' => 8192,
        'max_photos' => 20,
        // classified.request_types: D8 requests (no payment) a live ad takes, per vertical (G-W22)
        'request_types' => [
            'general' => ['viewing', 'reserve'],
            'property' => ['viewing', 'reserve'],
            'motors' => ['viewing', 'reserve'],
        ],
    ],
    'property' => [
        'verification_required' => true,            // property.verification_required: permit number before review
    ],
    'travel' => [
        'seat_hold_minutes' => 15,                   // travel.seat_hold_minutes (unsubmitted booking holds)
        'guest_hold_max_seats' => 4,                 // seats one guest hold may take (QA-010)
        'guest_holds_per_hour' => 3,                 // guest holds per IP per hour (QA-010)
        'completion_time' => '03:45',                // CompleteTravelBookingsJob
        'room_types' => ['single', 'double', 'triple', 'quad', 'child'],
        // D14 paid placement (travel promotions)
        'promotion_slots' => 3,                      // travel.promotion_slots: promoted trips shown first per list (the travel slot's cap when it is first set up)
        'promotion_rotation_minutes' => 15,          // travel.promotion_rotation_minutes: how often the promoted set rotates when more are live than slots
        'promotion_payment_minutes' => 60,           // travel.promotion_payment_minutes: an unpaid promotion is cancelled after this
        'promotion_label_en' => 'Sponsored',         // travel.promotion_label_en
        'promotion_label_ar' => 'مُموَّل',              // travel.promotion_label_ar
    ],
    'coupons' => [
        'seller_max_live' => 20,                     // coupons.seller_max_live: switched-on, unexpired seller coupons per store (proposed)
        'seller_max_percent' => 90,                  // coupons.seller_max_percent: highest percent off a seller may give (proposed)
        'seller_max_days' => 365,                    // coupons.seller_max_days: longest a seller coupon may run (proposed)
    ],
    'creators' => [
        'max_picks' => 48,                           // creators.max_picks: curated products per creator page (proposed)
    ],
    'advertising' => [
        'min_daily_budget' => '10',                  // ad_campaign.min_daily_budget (proposed)
        'min_cpc_bid' => '0.10',                     // ad_campaign.min_cpc_bid (proposed)
        'min_cpm_bid' => '1.00',                     // ad_campaign.min_cpm_bid (proposed)
        'max_sponsored_per_page' => 4,               // sponsored_ranking.max_sponsored_per_page (per 48 results)
        'sponsored_slots' => [1, 6, 11, 16],         // 1-based positions on a results page (proposed)
        'click_dedupe_minutes' => 10,                // same visitor clicking again within this window is not billed
        'label_en' => 'Ad',                          // sponsored.label_text (en)
        'label_ar' => 'إعلان',                        // sponsored.label_text (ar)
        'ctr_prior_clicks' => 1,                     // quality smoothing: (clicks + 1) / (impressions + 100)
        'ctr_prior_impressions' => 100,
        'package_vat_included' => true,
    ],

    /*
    | Portal join options (PORTAL_PAGES §2): sections of the /join chooser,
    | switched per country with the settings keys "join_options.{section}".
    */
    'join_options' => [
        'vendor' => true,
        'open_souq' => true,
        'travel_agency' => true,
        'marketer' => true,
        'shipping' => true,
    ],

    /*
    | Delivery & COD (WP7, settings catalogue §7, ADR 15), setting keys
    | "delivery.{key}" (country override, then global, then this default).
    */
    'delivery' => [
        'code_length' => 4,                          // delivery.code_length (locked)
        'pay_per_stop' => null,                      // delivery.pay_per_stop (agent earnings per delivered stop; TBD per country, null = not set)
        'pay_per_failed_attempt' => null,            // delivery.pay_per_failed_attempt (TBD; null = 0)
        'payslip_period' => 'weekly',                // delivery.payslip_period weekly (Mon–Sun) | monthly; payslips issued the day after the period ends
        'withdrawal_minimum' => '50',                // delivery.withdrawal_minimum (smallest wallet withdrawal, country currency)
        'withdrawal_four_eyes' => true,              // delivery.withdrawal_four_eyes: the reviewer who approved cannot mark it paid
        'proof_required_for_cod' => true,            // delivery.proof_required_for_cod
        'proof_required_above' => null,              // delivery_proof_required_above (OQ5, TBD per country; null = off)
        'proof_type' => 'either',                    // delivery.proof_type photo|signature|either
        'sla_minutes' => 240,                        // delivery.sla_minutes (TBD; proposed 4 h from assignment)
        'max_attempts' => 3,                         // delivery.max_attempts (proposed)
        'reschedule_max_days' => 7,                  // furthest reschedule date in business days (proposed)
        'failure_reasons' => ['not_home', 'wrong_address', 'refused', 'unreachable', 'cod_not_ready'],
        'auto_assign' => 'nearest',                  // delivery.auto_assign off|nearest|round_robin
        'proof_max_kb' => 8192,                      // largest proof photo / signature upload
    ],

    /*
    | Live media (WP7, ADR 11, SRS §14): admin-only. Driver `livekit` talks
    | to the LiveKit server API (config/services.php "livekit"); `fake` keeps
    | rooms in memory (tests, local without LiveKit credentials).
    */
    'streams' => [
        'driver' => env('NAWY_LIVE_MEDIA_DRIVER', 'fake'),
        'recorded_max_mb' => 500,
        'recorded_formats' => ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'mp4', 'webm', 'mov', 'm4v', 'mpeg'],
        'default_playback_mode' => 'synced',
        'viewer_token_minutes' => 120,
        'publisher_token_minutes' => 360,
        // G-W27: web playback of LiveKit rooms through an egress / CDN HLS
        // stream, e.g. "https://live-cdn.example.com/hls/{room}/index.m3u8"
        // ({room} = room name). Empty: no HLS (`playback.hls_url` null).
        'hls_url_template' => env('LIVEKIT_HLS_URL_TEMPLATE'),
    ],

    /*
    | Content (WP7, SRS §15, settings catalogue §14).
    */
    'content' => [
        'page_cache_minutes' => 10,                  // page_builder.cache_ttl_minutes
        'blog_per_page' => 12,                       // blog.per_page
        'blog_related_count' => 3,                   // blog.related_count
        'help_search_limit' => 30,                   // help_center.search_limit
    ],

    /*
    | National number lengths (digits after the country code, without the
    | trunk 0) accepted for contact phones of guest requests (owner decision WP6).
    */
    /*
    | Newsletter (SRS §10, settings catalogue `newsletter.double_opt_in`):
    | subscriptions are per country; with double opt-in the address is only
    | subscribed after the emailed link (to the storefront) is opened.
    */
    'newsletter' => [
        'double_opt_in' => true,
        'resend_after_seconds' => 60,
    ],

    /*
    | Support tickets (QC-007, SRS §15, lifecycle §9.3). Country overrides
    | through SettingsService keys `support.first_response_hours`,
    | `support.resolution_hours` (per priority, wall-clock hours),
    | `support.auto_close_days` and `support.reopen_days`.
    */
    'support' => [
        'first_response_hours' => ['urgent' => 2, 'high' => 4, 'normal' => 12, 'low' => 24],
        'resolution_hours' => ['urgent' => 24, 'high' => 48, 'normal' => 72, 'low' => 120],
        'auto_close_days' => 7,
        'reopen_days' => 7,
        'attachment_max_kb' => 5120,
        'attachment_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        'max_attachments' => 5,
    ],

    'phone_national_digits' => [
        'uae' => [9], 'omn' => [8], 'ksa' => [9], 'egy' => [10],
        'kwt' => [8], 'qat' => [8], 'bhr' => [8], 'jor' => [9],
    ],

    /*
    | SMS (legacy phone-only customers' first sign-in, data-migration-plan
    | §5.2). `log` writes messages to the log (development), `array` keeps
    | them in memory (tests). A live provider driver needs the owner's SMS
    | provider account and credentials before production.
    */
    'sms' => [
        'driver' => env('NAWY_SMS_DRIVER', 'log'),
    ],

    /*
    | Push notifications (G-A2): every in-app notification is pushed to the
    | user's registered devices. `log` (development), `array` (tests) or
    | `fcm` — Firebase Cloud Messaging HTTP v1, which needs the owner's
    | Firebase project id and service-account JSON (or its file path).
    */
    'push' => [
        'driver' => env('NAWY_PUSH_DRIVER', 'log'),
        'fcm' => [
            'project_id' => env('NAWY_FCM_PROJECT_ID'),
            'credentials' => env('NAWY_FCM_CREDENTIALS'),
        ],
    ],

    'customer_auth' => [
        'email_code_length' => 6,
        'email_code_ttl_minutes' => 10,
        'email_code_max_attempts' => 5,
        'email_code_lock_minutes' => 15,

        /*
        | D9: optional password and Google / Apple sign-in. `social_driver`
        | "fake" accepts the deterministic FakeSocialProvider tokens (tests,
        | local without keys); "live" talks to Google and Apple. Social
        | redirects land on the storefront (`payments.storefront_url`) at
        | `/{market}/auth/social/complete?ticket=…`, and the ticket is
        | exchanged once, within `social_ticket_ttl_seconds`, for a token.
        | Password reset links point to `/{market}/reset-password`.
        */
        'password_min_length' => 8,
        'social_providers' => ['google', 'apple'],
        'social_driver' => env('NAWY_SOCIAL_AUTH_DRIVER', 'live'),
        'social_state_ttl_minutes' => 10,
        'social_ticket_ttl_seconds' => 120,
        // The customer app's web sign-in (redirect with a PKCE
        // `code_challenge`) returns here instead of the storefront.
        'app_redirect_uri' => env('NAWY_APP_SOCIAL_REDIRECT_URI', 'nawy://auth/social/complete'),
    ],

    'countries' => [
        'uae' => [
            'iso2' => 'AE',
            'name_en' => 'United Arab Emirates',
            'name_ar' => 'الإمارات العربية المتحدة',
            'currency' => 'AED',
            'timezone' => 'Asia/Dubai',
            'weekend' => ['saturday', 'sunday'],
            'tax_name' => 'VAT',
            'tax_rate' => '5.00',
            'phone_code' => '+971',
            'locales' => ['en', 'ar'],
            'default_locale' => 'en',
            'sort_order' => 1,
        ],
        'omn' => [
            'iso2' => 'OM',
            'name_en' => 'Oman',
            'name_ar' => 'عُمان',
            'currency' => 'OMR',
            'timezone' => 'Asia/Muscat',
            'weekend' => ['friday', 'saturday'],
            'tax_name' => 'VAT',
            'tax_rate' => '5.00',
            'phone_code' => '+968',
            'locales' => ['ar', 'en'],
            'default_locale' => 'ar',
            'sort_order' => 2,
        ],
        'ksa' => [
            'iso2' => 'SA',
            'name_en' => 'Saudi Arabia',
            'name_ar' => 'المملكة العربية السعودية',
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'weekend' => ['friday', 'saturday'],
            'tax_name' => 'VAT',
            'tax_rate' => '15.00',
            'phone_code' => '+966',
            'locales' => ['ar', 'en'],
            'default_locale' => 'ar',
            'sort_order' => 3,
        ],
        'egy' => [
            'iso2' => 'EG',
            'name_en' => 'Egypt',
            'name_ar' => 'مصر',
            'currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'weekend' => ['friday', 'saturday'],
            'tax_name' => 'VAT',
            'tax_rate' => '14.00',
            'phone_code' => '+20',
            'locales' => ['ar', 'en'],
            'default_locale' => 'ar',
            'sort_order' => 4,
        ],
        'kwt' => [
            'iso2' => 'KW',
            'name_en' => 'Kuwait',
            'name_ar' => 'الكويت',
            'currency' => 'KWD',
            'timezone' => 'Asia/Kuwait',
            'weekend' => ['friday', 'saturday'],
            'tax_name' => null,
            'tax_rate' => '0.00',
            'phone_code' => '+965',
            'locales' => ['ar', 'en'],
            'default_locale' => 'ar',
            'sort_order' => 5,
        ],
        'qat' => [
            'iso2' => 'QA',
            'name_en' => 'Qatar',
            'name_ar' => 'قطر',
            'currency' => 'QAR',
            'timezone' => 'Asia/Qatar',
            'weekend' => ['friday', 'saturday'],
            'tax_name' => null,
            'tax_rate' => '0.00',
            'phone_code' => '+974',
            'locales' => ['ar', 'en'],
            'default_locale' => 'ar',
            'sort_order' => 6,
        ],
        'bhr' => [
            'iso2' => 'BH',
            'name_en' => 'Bahrain',
            'name_ar' => 'البحرين',
            'currency' => 'BHD',
            'timezone' => 'Asia/Bahrain',
            'weekend' => ['friday', 'saturday'],
            'tax_name' => 'VAT',
            'tax_rate' => '10.00',
            'phone_code' => '+973',
            'locales' => ['ar', 'en'],
            'default_locale' => 'ar',
            'sort_order' => 7,
        ],
        'jor' => [
            'iso2' => 'JO',
            'name_en' => 'Jordan',
            'name_ar' => 'الأردن',
            'currency' => 'JOD',
            'timezone' => 'Asia/Amman',
            'weekend' => ['friday', 'saturday'],
            'tax_name' => 'GST',
            'tax_rate' => '16.00',
            'phone_code' => '+962',
            'locales' => ['ar', 'en'],
            'default_locale' => 'ar',
            'sort_order' => 8,
        ],
    ],

    /*
    | WP11 legacy migration (docs/project/data-migration-plan.md): the old
    | platform's storage root (its `public/` and `private/` disks are read,
    | never written) and where the 301 redirect map is exported for the proxy.
    */
    /*
    | WP11: default limit of every API route per signed-in user or IP
    | (`throttleApi('api')`); sign-in and other sensitive routes have their own.
    */
    'api_rate_per_minute' => (int) env('NAWY_API_RATE_PER_MINUTE', 600),

    'legacy' => [
        'storage_root' => env('LEGACY_STORAGE_ROOT', '/var/www/marketplace/backend/storage/app'),
        'redirects_export' => 'migration/legacy-redirects.json',
        // Where legacy:reconcile writes its report (relative to the repository root), read by Admin → Data migration.
        'reconcile_report' => env('LEGACY_RECONCILE_REPORT', 'docs/project/migration/reconciliation-report.md'),
        // Cache of the 301 lookups (API endpoint + panel fallback), invalidated when legacy:redirects rebuilds the map.
        'redirects_cache_seconds' => 86400,
    ],

];
