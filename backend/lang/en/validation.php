<?php

/*
 * Application lines on top of Laravel's English validation messages: field
 * names in words (browser test BT-ADM-005). App\Shared\Validation\FriendlyValidator
 * builds the rest from these ("name_en" → "name (English)", "lines.1.quantity"
 * → "quantity (row 2)", "category_id" → "category").
 */
return [

    'attribute_locales' => [
        'en' => ':attribute (English)',
        'ar' => ':attribute (Arabic)',
    ],

    'attribute_row' => ':attribute (row :row)',

    'attributes' => [
        'q' => 'search',
        'iban' => 'IBAN',
        'gtin' => 'GTIN',
        'sku' => 'SKU',
        'vendor_sku' => 'seller SKU',
        'url' => 'URL',
        'link_url' => 'link URL',
        'reference_url' => 'reference URL',
        'cr_number' => 'CR number',
        'tax_id' => 'tax ID',
        'lat' => 'latitude',
        'lng' => 'longitude',
        'id_token' => 'ID token',
        'bin_code' => 'BIN code',
        'cod_remittance_confirmed' => 'COD remittance confirmed',
        'is_active' => 'active',
        'is_default' => 'default',
        'is_featured' => 'featured',
        'is_published' => 'published',
        'is_required' => 'required',
        'is_public' => 'public',
        'is_cod' => 'cash on delivery',
        'seo_title' => 'SEO title',
        'seo_description' => 'SEO description',
        'alt' => 'alt text',
        'dns_verified' => 'DNS verified',
        'tls_status' => 'TLS status',
        'values.street' => 'street',
        'values.building' => 'building',
        'values.city' => 'city',
        'values.area' => 'area',
        'per_kg_fee' => 'fee per kg',
        'weight_grams' => 'weight (grams)',
        'base_weight_kg' => 'base weight (kg)',
        'capacity_cubic_feet' => 'capacity (cubic feet)',
        'area_sqm' => 'area (m²)',
        'sort_order' => 'order',
        'duration_months' => 'months',
        'photo_max_kb' => 'photo size (KB)',
        'max_mb' => 'maximum size (MB)',
    ],

];
