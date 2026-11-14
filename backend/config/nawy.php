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

    /*
    | Checkout defaults per country (amounts VAT-inclusive, in the country
    | currency), overridable per country with the settings keys
    | "checkout.{key}". `shipping_fee_per_seller` is only the fallback used
    | when no shipping zone or rate matches the destination (SRS §8).
    */
    'checkout' => [
        'cart_lock_minutes' => 15,
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
    'orders' => [
        'return_window_days' => 14,
        'auto_complete_time' => '02:00',
    ],

    /*
    | Flash sale defaults (settings catalogue §9), setting keys "flash_sale.{key}".
    */
    'flash_sale' => [
        'max_qty_per_customer' => 2,
    ],

    'customer_auth' => [
        'email_code_length' => 6,
        'email_code_ttl_minutes' => 10,
        'email_code_max_attempts' => 5,
        'email_code_lock_minutes' => 15,
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

];
