<?php

use App\Domains\Campaigns\Support\ReferralCodes;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Contracts\Support\ContractRenderer;
use App\Domains\Contracts\Support\ContractResolver;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\GiftCards\Support\GiftCodes;
use App\Domains\Migration\Steps\ContractsStep;
use App\Domains\Migration\Support\Reconciler;
use App\Domains\Migration\Support\TableRules;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyFixture as Legacy;

uses(RefreshDatabase::class);

/*
| WP11: the legacy migration against a small fixture snapshot
| (nawy_test_legacy, never the real one). One coherent old-platform data set
| covers identity merge, the country split, exact money, ledger and wallets,
| signatures, idempotency, reconciliation and redirects.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->seed(AdminRoleSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
    $root = storage_path('framework/testing/legacy-files');
    File::deleteDirectory($root);
    File::ensureDirectoryExists($root.'/public/products/p1');
    File::put($root.'/public/products/p1/main.jpg', 'jpeg-bytes');
    config(['nawy.legacy.storage_root' => $root]);
    Legacy::reset();
    $this->legacy = legacyScenario();
});

/**
 * @return array<string, string>
 */
function legacyScenario(): array
{
    $c = Legacy::COUNTRIES;
    $hash = password_hash('secret-password', PASSWORD_BCRYPT);
    $ids = [];

    foreach (['c1', 'c2', 'c3', 'v1', 'v2', 'va1', 'a1', 'cat_root', 'cat_child', 'attr', 'attr_value', 'brand', 'p1', 'pv1', 'l1', 'l2', 'o1', 's1', 'i1', 'h1', 'o2', 's2', 'i2', 'w1', 'w2', 'wt1', 'wt2', 'wt3', 'g1', 'g2', 't1', 'vc1', 'vc2', 'hc1', 'ha1', 'bp1', 'bc1', 'zone', 'method'] as $key) {
        $ids[$key] = Legacy::uuid();
    }

    Legacy::insert('roles', [['id' => 1, 'name' => 'super_admin', 'guard_name' => 'admin'], ['id' => 6, 'name' => 'vendor_owner', 'guard_name' => 'vendor']]);
    Legacy::insert('admins', [['id' => $ids['a1'], 'name' => 'Root Admin', 'email' => 'root@legacy.test', 'password' => $hash, 'status' => 'active']]);
    Legacy::insert('model_has_roles', [['role_id' => 1, 'model_type' => 'App\\Models\\Admin', 'model_uuid' => $ids['a1']], ['role_id' => 6, 'model_type' => 'App\\Models\\VendorAdmin', 'model_uuid' => $ids['va1']]]);
    Legacy::insert('customers', [
        ['id' => $ids['c1'], 'name' => 'Dual Person', 'email' => 'Dual@Legacy.test', 'email_verified_at' => '2026-01-01 10:00:00', 'phone' => '+971500000001', 'password' => $hash, 'country_id' => $c['uae'], 'locale' => 'ar', 'status' => 'active', 'created_at' => '2026-01-01 10:00:00'],
        ['id' => $ids['c2'], 'name' => 'Phone Only', 'email' => '', 'phone' => '00966500000002', 'password' => $hash, 'country_id' => $c['ksa'], 'status' => 'active'],
    ]);
    // Same person's email on another legacy login, but a different phone: a weak signal, never merged.
    Legacy::insert('delivery_agents', [['id' => $ids['c3'], 'country_id' => $c['uae'], 'name' => 'Conflict', 'email' => 'dual@legacy.test', 'phone' => '+971599999999', 'password' => $hash, 'status' => 'active']]);
    Legacy::insert('vendors', [
        ['id' => $ids['v1'], 'name' => 'Store Owner', 'email' => 'store@legacy.test', 'password' => $hash, 'store_name' => 'Legacy Store', 'store_slug' => 'legacy-store', 'country_id' => $c['uae'], 'global_status' => 'active', 'vendor_type' => 'product_vendor', 'payout_schedule' => 'monthly'],
        ['id' => $ids['v2'], 'name' => 'Nowhere', 'email' => 'nowhere@legacy.test', 'password' => $hash, 'store_name' => 'Nowhere', 'store_slug' => 'nowhere', 'country_id' => null, 'global_status' => 'active'],
    ]);
    Legacy::insert('vendor_admins', [['id' => $ids['va1'], 'vendor_id' => $ids['v1'], 'name' => 'Dual Person', 'email' => 'dual@legacy.test', 'password' => $hash, 'role' => 'vendor_owner', 'is_owner' => 1, 'is_active' => 1]]);

    Legacy::insert('categories', [
        ['id' => $ids['cat_root'], 'parent_id' => null, 'name_en' => 'Legacy Root', 'name_ar' => 'جذر', 'slug' => 'legacy-root', 'depth' => 0, 'lft' => 1, 'rgt' => 4, 'is_active' => 1, 'commission_rate' => '10.00'],
        ['id' => $ids['cat_child'], 'parent_id' => $ids['cat_root'], 'name_en' => 'Legacy Child', 'name_ar' => 'فرع', 'slug' => 'legacy-child', 'depth' => 1, 'lft' => 2, 'rgt' => 3, 'is_active' => 1],
    ]);
    Legacy::insert('attributes', [['id' => $ids['attr'], 'name_en' => 'Colour', 'name_ar' => 'اللون', 'code' => 'colour', 'type' => 'color', 'is_filterable' => 1]]);
    Legacy::insert('attribute_values', [['id' => $ids['attr_value'], 'attribute_id' => $ids['attr'], 'value_en' => 'Black', 'value_ar' => 'أسود', 'slug' => 'black']]);
    Legacy::insert('category_attributes', [['id' => Legacy::uuid(), 'category_id' => $ids['cat_child'], 'attribute_id' => $ids['attr'], 'is_required' => 1]]);
    Legacy::insert('brands', [['id' => $ids['brand'], 'name_en' => 'Legacy Brand', 'name_ar' => 'علامة', 'slug' => 'legacy-brand', 'is_active' => 1]]);
    Legacy::insert('products', [['id' => $ids['p1'], 'category_id' => $ids['cat_child'], 'brand_id' => $ids['brand'], 'name_en' => 'Legacy Kettle', 'name_ar' => 'غلاية', 'slug' => 'legacy-kettle', 'status' => 'active']]);
    Legacy::insert('product_countries', [['id' => Legacy::uuid(), 'product_id' => $ids['p1'], 'country_id' => $c['uae'], 'is_available' => 1]]);
    Legacy::insert('product_variants', [['id' => $ids['pv1'], 'product_id' => $ids['p1'], 'sku' => 'LEG-KETTLE-1', 'weight_grams' => 1200]]);
    Legacy::insert('product_variant_attributes', [['id' => Legacy::uuid(), 'product_variant_id' => $ids['pv1'], 'attribute_id' => $ids['attr'], 'attribute_value_id' => $ids['attr_value']]]);
    Legacy::insert('product_images', [['id' => Legacy::uuid(), 'product_id' => $ids['p1'], 'path' => 'products/p1/main.jpg', 'disk' => 'public', 'position' => 0]]);
    Legacy::insert('vendor_listings', [
        ['id' => $ids['l1'], 'vendor_id' => $ids['v1'], 'product_variant_id' => $ids['pv1'], 'country_id' => $c['uae'], 'price' => '100.5000', 'compare_at_price' => '120.0000', 'currency' => 'AED', 'condition' => 'new', 'fulfillment_model' => 'fbm', 'status' => 'active'],
        ['id' => $ids['l2'], 'vendor_id' => $ids['v1'], 'product_variant_id' => $ids['pv1'], 'country_id' => $c['omn'], 'price' => '10.1250', 'currency' => 'OMR', 'condition' => 'like_new', 'fulfillment_model' => 'fbm', 'status' => 'active'],
    ]);
    Legacy::insert('shipping_methods', [['id' => $ids['method'], 'name' => 'Standard', 'code' => 'standard', 'min_delivery_days' => 2, 'max_delivery_days' => 4]]);
    Legacy::insert('shipping_zones', [['id' => $ids['zone'], 'name' => 'Muscat', 'country_id' => $c['omn'], 'is_active' => 1]]);
    Legacy::insert('shipping_rates', [['id' => Legacy::uuid(), 'destination_zone_id' => $ids['zone'], 'shipping_method_id' => $ids['method'], 'base_fee' => '1.5000', 'rate_per_kg' => '0.2500', 'is_active' => 1]]);

    $snapshot = ['sku' => 'LEG-KETTLE-1', 'name_en' => 'Legacy Kettle', 'name_ar' => 'غلاية'];
    Legacy::insert('orders', [
        ['id' => $ids['o1'], 'order_number' => 'OLD-OMN-1', 'customer_id' => $ids['c1'], 'country_id' => $c['omn'], 'status' => 'shipped', 'currency' => 'OMR', 'subtotal' => '20.2500', 'discount' => '0.0000', 'shipping' => '1.5000', 'tax' => '1.0880', 'cod_fee' => '0.5000', 'total' => '23.3380', 'payment_method' => 'cod', 'payment_status' => 'pending', 'shipping_address_snapshot' => ['city' => 'Muscat'], 'ip_address' => '127.0.0.1', 'placed_at' => '2026-02-01 09:00:00'],
        ['id' => $ids['o2'], 'order_number' => 'OLD-USD-1', 'customer_id' => $ids['c1'], 'country_id' => $c['uae'], 'status' => 'placed', 'currency' => 'USD', 'subtotal' => '10.0000', 'shipping' => '0', 'tax' => '0', 'total' => '10.0000', 'payment_method' => 'cod', 'payment_status' => 'pending', 'shipping_address_snapshot' => [], 'ip_address' => '127.0.0.1', 'placed_at' => '2026-02-01 09:00:00'],
    ]);
    Legacy::insert('sub_orders', [
        ['id' => $ids['s1'], 'order_id' => $ids['o1'], 'sub_order_number' => 'OLD-OMN-1-A', 'vendor_id' => $ids['v1'], 'seller_type' => 'vendor', 'status' => 'shipped', 'fulfillment_model' => 'fbm', 'subtotal' => '20.2500', 'shipping' => '1.5000', 'tax' => '1.0880', 'platform_commission' => '2.0250', 'vendor_payout' => '18.2250', 'shipping_method_id' => $ids['method']],
        ['id' => $ids['s2'], 'order_id' => $ids['o2'], 'sub_order_number' => 'OLD-USD-1-A', 'vendor_id' => $ids['v1'], 'seller_type' => 'vendor', 'status' => 'placed', 'fulfillment_model' => 'fbm', 'subtotal' => '10.0000', 'shipping' => '0', 'tax' => '0', 'platform_commission' => '0', 'vendor_payout' => '10'],
    ]);
    Legacy::insert('order_items', [
        ['id' => $ids['i1'], 'order_id' => $ids['o1'], 'sub_order_id' => $ids['s1'], 'product_variant_id' => $ids['pv1'], 'vendor_listing_id' => $ids['l2'], 'vendor_id' => $ids['v1'], 'product_snapshot' => $snapshot, 'sku' => 'LEG-KETTLE-1', 'quantity' => 2, 'unit_price' => '10.1250', 'line_subtotal' => '20.2500', 'line_tax' => '1.0880', 'line_total' => '21.3380', 'commission_rate_pct' => '10.00', 'commission_amount' => '2.0250', 'fulfillment_status' => 'shipped'],
        ['id' => $ids['i2'], 'order_id' => $ids['o2'], 'sub_order_id' => $ids['s2'], 'product_variant_id' => $ids['pv1'], 'vendor_listing_id' => $ids['l1'], 'vendor_id' => $ids['v1'], 'product_snapshot' => $snapshot, 'sku' => 'LEG-KETTLE-1', 'quantity' => 1, 'unit_price' => '10.0000', 'line_subtotal' => '10.0000', 'line_tax' => '0', 'line_total' => '10.0000', 'commission_rate_pct' => '0', 'commission_amount' => '0', 'fulfillment_status' => 'pending'],
    ]);
    Legacy::insert('order_status_histories', [['id' => $ids['h1'], 'order_id' => $ids['o1'], 'sub_order_id' => $ids['s1'], 'from_status' => 'packed', 'to_status' => 'shipped', 'changed_by_admin_id' => $ids['a1'], 'created_at' => '2026-02-02 10:00:00']]);

    Legacy::insert('wallets', [
        ['id' => $ids['w1'], 'owner_type' => 'vendor', 'owner_id' => $ids['v1'], 'balance' => '40.1500', 'pending_balance' => '0.0000', 'currency' => 'AED', 'is_frozen' => 0],
        ['id' => $ids['w2'], 'owner_type' => 'customer', 'owner_id' => $ids['c1'], 'balance' => '99.0000', 'pending_balance' => '0', 'currency' => 'AED', 'is_frozen' => 0],
    ]);
    Legacy::insert('wallet_transactions', [
        ['id' => $ids['wt1'], 'wallet_id' => $ids['w1'], 'type' => 'sale', 'direction' => 'credit', 'amount' => '50.2500', 'balance_after' => '50.2500', 'currency_code' => 'AED', 'source_type' => 'order', 'created_at' => '2026-02-01 10:00:00'],
        ['id' => $ids['wt2'], 'wallet_id' => $ids['w1'], 'type' => 'payout', 'direction' => 'debit', 'amount' => '10.1000', 'balance_after' => '40.1500', 'currency_code' => 'AED', 'source_type' => 'payout', 'created_at' => '2026-02-02 10:00:00'],
        ['id' => $ids['wt3'], 'wallet_id' => $ids['w2'], 'type' => 'refund', 'direction' => 'credit', 'amount' => '10.0000', 'balance_after' => '10.0000', 'currency_code' => 'AED', 'source_type' => 'refund', 'created_at' => '2026-02-01 10:00:00'],
    ]);
    $entry = fn (string $group, string $type, string $debit, string $credit, ?string $holderType = null, ?string $holder = null): array => ['id' => Legacy::uuid(), 'transaction_group_id' => $group, 'account_type' => $type, 'account_holder_type' => $holderType, 'account_holder_id' => $holder, 'debit' => $debit, 'credit' => $credit, 'currency' => 'AED', 'reference_type' => 'order', 'reference_id' => $ids['o1'], 'description' => 'Sale', 'created_at' => '2026-02-01 10:00:00'];
    Legacy::insert('ledger_entries', [
        $entry($ids['g1'], 'customer_payment', '105.0000', '0'),
        $entry($ids['g1'], 'seller_payable', '0', '100.0000', 'vendor', $ids['v1']),
        $entry($ids['g1'], 'tax_payable', '0', '5.0000'),
        $entry($ids['g2'], 'customer_payment', '50.0000', '0'),
        $entry($ids['g2'], 'platform_revenue', '0', '49.9900'),
    ]);

    $variables = array_combine(ContractsStep::VARIABLE_ORDER, array_map(fn (string $key): string => $key === 'vendor.store_name' ? 'Legacy Store' : '', ContractsStep::VARIABLE_ORDER));
    $content = '<p>PRODUCT VENDOR AGREEMENT for Legacy Store</p>';
    Legacy::insert('classified_contract_templates', [['id' => $ids['t1'], 'category_scope' => 'product', 'contract_kind' => 'seller_category', 'name' => 'Product Vendor Agreement', 'content_mode' => 'editor', 'version' => 1, 'content_en' => $content, 'content_ar' => $content, 'created_by_admin_id' => $ids['a1'], 'is_published' => 1, 'is_active' => 1]]);
    Legacy::insert('vendor_contracts', [
        ['id' => $ids['vc1'], 'vendor_id' => $ids['v1'], 'category_scope' => 'product', 'contract_template_id' => $ids['t1'], 'country_id' => $c['uae'], 'template_version' => 1, 'language_signed' => 'en', 'content_mode' => 'editor', 'rendered_content' => $content, 'rendered_content_hash' => hash('sha256', $content.json_encode($variables)), 'variables' => $variables, 'signed_by_vendor_admin_id' => $ids['va1'], 'signer_name' => 'Dual Person', 'signed_at' => '2026-01-05 10:00:00', 'status' => 'active', 'acceptance_method' => 'click_wrap'],
        ['id' => $ids['vc2'], 'vendor_id' => $ids['v1'], 'category_scope' => 'product', 'contract_template_id' => $ids['t1'], 'country_id' => $c['uae'], 'template_version' => 1, 'language_signed' => 'en', 'content_mode' => 'editor', 'rendered_content' => $content.' (edited later)', 'rendered_content_hash' => hash('sha256', $content.json_encode($variables)), 'variables' => $variables, 'signer_name' => 'Dual Person', 'signed_at' => '2026-01-06 10:00:00', 'status' => 'superseded', 'acceptance_method' => 'click_wrap'],
    ]);

    Legacy::insert('help_center_categories', [['id' => $ids['hc1'], 'country_id' => null, 'name' => 'Payouts', 'name_en' => 'Payouts', 'name_ar' => 'المدفوعات', 'slug' => 'payouts', 'is_active' => 1]]);
    Legacy::insert('help_center_articles', [['id' => $ids['ha1'], 'help_center_category_id' => $ids['hc1'], 'country_id' => null, 'title' => 'When am I paid?', 'title_en' => 'When am I paid?', 'title_ar' => 'متى أستلم؟', 'slug' => 'when-am-i-paid', 'body' => 'Weekly.', 'body_en' => 'Weekly.', 'body_ar' => 'أسبوعيا.', 'status' => 'published']]);
    Legacy::insert('blog_categories', [['id' => $ids['bc1'], 'name_en' => 'News', 'name_ar' => 'أخبار', 'slug' => 'legacy-news', 'is_active' => 1]]);
    Legacy::insert('blog_posts', [['id' => $ids['bp1'], 'blog_category_id' => $ids['bc1'], 'author_admin_id' => $ids['a1'], 'title_en' => 'Hello', 'title_ar' => 'مرحبا', 'slug' => 'hello-legacy', 'body_en' => 'Body', 'body_ar' => 'نص', 'status' => 'published']]);
    Legacy::insert('cache', [['key' => 'x', 'value' => 'y', 'expiration' => 0]]);
    Legacy::insert('payment_transactions', [['id' => Legacy::uuid(), 'order_id' => $ids['o1'], 'customer_id' => $ids['c1'], 'type' => 'sale', 'gateway' => 'cod', 'gateway_transaction_id' => 'cod-1', 'idempotency_key' => 'pay-1', 'amount' => '23.3380', 'currency' => 'OMR', 'status' => 'pending']]);

    return legacyCommerceScenario($ids);
}

/**
 * Tables that were empty in the real snapshot (plan §13.3): payments and
 * refunds, payouts, campaigns with invitations / referral codes and
 * conversions, travel, classifieds, gift cards and support tickets.
 *
 * @param  array<string, string>  $ids
 * @return array<string, string>
 */
function legacyCommerceScenario(array $ids): array
{
    $c = Legacy::COUNTRIES;
    $hash = password_hash('secret-password', PASSWORD_BCRYPT);

    foreach (['pt_auth', 'pt_cap', 'pt_ref', 'pt_cb', 'rf1', 'm1', 'mc1', 'mc2', 'inv1', 'inv2', 'conv1', 'conv2', 'ta1', 'tp1', 'tp2', 'tb1', 'tb2', 'cc1', 'cc2', 'cl1', 'cl2', 'gc1', 'gc2', 'st1', 'st2', 'tm1', 'tm2', 'tm3'] as $key) {
        $ids[$key] = Legacy::uuid();
    }

    // Payments of the Omani order: an authorisation captured later, a partial gateway refund, a chargeback.
    $payment = fn (string $id, string $type, string $amount, string $reference): array => ['id' => $ids[$id], 'order_id' => $ids['o1'], 'customer_id' => $ids['c1'], 'type' => $type, 'gateway' => 'thawani', 'gateway_transaction_id' => $reference, 'idempotency_key' => 'key-'.$reference, 'amount' => $amount, 'currency' => 'OMR', 'status' => 'succeeded', 'processed_at' => '2026-02-01 09:05:00', 'created_at' => '2026-02-01 09:05:00'];
    Legacy::insert('payment_transactions', [
        $payment('pt_auth', 'authorization', '23.3380', 'thw-auth-1'),
        $payment('pt_cap', 'capture', '23.3380', 'thw-cap-1'),
        ['gateway_fee' => '0.1500'] + $payment('pt_ref', 'refund', '5.0000', 'thw-ref-1'),
        $payment('pt_cb', 'chargeback', '23.3380', 'thw-cb-1'),
    ]);
    Legacy::insert('refunds', [['id' => $ids['rf1'], 'order_id' => $ids['o1'], 'sub_order_id' => $ids['s1'], 'original_transaction_id' => $ids['pt_auth'], 'refund_transaction_id' => $ids['pt_ref'], 'amount' => '6.0000', 'gateway_fee_deducted' => '1.0000', 'tax_deducted' => '0', 'currency' => 'OMR', 'reason' => 'damaged', 'reason_notes' => 'Lid cracked', 'refund_type' => 'partial', 'vendor_charged_back' => 0, 'status' => 'completed', 'created_at' => '2026-02-03 10:00:00', 'updated_at' => '2026-02-04 10:00:00']]);
    Legacy::insert('payouts', [
        ['id' => 1, 'payout_number' => 'PO-OLD-1', 'vendor_id' => $ids['v1'], 'period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'gross_sales' => '20.2500', 'commission' => '2.0250', 'refunds_deducted' => '0', 'chargebacks_deducted' => '0', 'net_amount' => '18.2250', 'currency' => 'OMR', 'status' => 'completed', 'bank_account_id' => Legacy::uuid(), 'payout_method' => 'bank_transfer', 'gateway_reference' => 'BANK-77', 'processed_at' => '2026-03-02 10:00:00'],
        ['id' => 2, 'payout_number' => 'PO-OLD-2', 'vendor_id' => $ids['v1'], 'period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'gross_sales' => '5.000', 'commission' => '0', 'refunds_deducted' => '0', 'chargebacks_deducted' => '0', 'net_amount' => '5.0000', 'currency' => 'KWD', 'status' => 'pending', 'bank_account_id' => Legacy::uuid(), 'payout_method' => 'bank_transfer'],
    ]);

    // An Omani affiliate promoting the Omani listing; a travel campaign (closed by D11) cannot move.
    Legacy::insert('marketers', [['id' => $ids['m1'], 'name' => 'Legacy Creator', 'email' => 'creator@legacy.test', 'phone' => '+96890000001', 'country_id' => $c['omn'], 'global_status' => 'active']]);
    Legacy::insert('marketer_campaigns', [
        ['id' => $ids['mc1'], 'vendor_id' => $ids['v1'], 'owner_type' => 'vendor', 'owner_id' => $ids['v1'], 'vendor_listing_id' => $ids['l2'], 'campaign_category' => 'product', 'country_id' => $c['omn'], 'currency' => 'OMR', 'commission_type' => 'percentage', 'max_commission_budget' => '50.0000', 'commission_budget_spent' => '2.0250', 'marketer_commission_amount' => '10.0000', 'status' => 'active', 'title' => 'Kettle push', 'campaign_subtotal' => '50.0000', 'marketing_fee_fixed' => '1.0000', 'marketing_fee_variable' => '0.5000', 'marketing_fee_total' => '1.5000', 'campaign_grand_total' => '51.5000', 'open_to_affiliates' => 1, 'influencer_fixed_reserved' => '0.0000', 'campaign_fee_status' => 'paid', 'campaign_fee_paid_at' => '2026-01-20 10:00:00', 'created_at' => '2026-01-20 09:00:00'],
        ['id' => $ids['mc2'], 'owner_type' => 'travel_agency', 'campaign_category' => 'travel', 'country_id' => $c['uae'], 'currency' => 'AED', 'commission_type' => 'fixed', 'max_commission_budget' => '10', 'commission_budget_spent' => '0', 'marketer_commission_amount' => '1', 'status' => 'active', 'campaign_subtotal' => '0', 'marketing_fee_fixed' => '0', 'marketing_fee_variable' => '0', 'marketing_fee_total' => '0', 'campaign_grand_total' => '0', 'influencer_fixed_reserved' => '0', 'campaign_fee_status' => 'pending'],
    ]);
    Legacy::insert('marketer_campaign_invitations', [
        ['id' => $ids['inv1'], 'campaign_id' => $ids['mc1'], 'marketer_id' => $ids['m1'], 'status' => 'accepted', 'acceptance_window_hours' => 48, 'responded_at' => '2026-01-21 10:00:00', 'referral_code' => 'Legacy10', 'referral_link' => 'https://old.example/r/Legacy10', 'whatsapp_sent' => 1, 'whatsapp_sent_at' => '2026-01-20 10:00:00', 'whatsapp_attempts' => 1, 'whatsapp_resend_count' => 0, 'total_commission_earned' => '1.8225', 'platform_fee_amount' => '0', 'platform_fee_vat_rate' => '0', 'platform_fee_vat_amount' => '0', 'platform_fee_status' => 'not_applicable', 'total_conversions' => 1, 'influencer_fixed_status' => 'none', 'created_at' => '2026-01-20 10:00:00'],
        ['id' => $ids['inv2'], 'campaign_id' => $ids['mc1'], 'marketer_id' => $ids['m1'], 'status' => 'replaced', 'acceptance_window_hours' => 48, 'replaced_at' => '2026-01-20 12:00:00', 'whatsapp_sent' => 0, 'whatsapp_attempts' => 0, 'whatsapp_resend_count' => 0, 'total_commission_earned' => '0', 'platform_fee_amount' => '0', 'platform_fee_vat_rate' => '0', 'platform_fee_vat_amount' => '0', 'platform_fee_status' => 'not_applicable', 'total_conversions' => 0, 'influencer_fixed_status' => 'none', 'created_at' => '2026-01-20 09:30:00'],
    ]);
    Legacy::insert('marketer_campaign_conversions', [
        ['id' => $ids['conv1'], 'campaign_id' => $ids['mc1'], 'invitation_id' => $ids['inv1'], 'order_id' => $ids['o1'], 'order_item_id' => $ids['i1'], 'conversion_type' => 'product_sale', 'commission_amount' => '2.0250', 'gross_commission_amount' => '2.0250', 'commission_fee_amount' => '0.2025', 'net_commission_amount' => '1.8225', 'sale_amount' => '20.2500', 'currency' => 'OMR', 'commissioned' => 1, 'status' => 'approved', 'approved_at' => '2026-02-10 10:00:00', 'wallet_released_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-01 09:00:00'],
        ['id' => $ids['conv2'], 'campaign_id' => $ids['mc1'], 'invitation_id' => $ids['inv1'], 'conversion_type' => 'travel_booking', 'commission_amount' => '1.0000', 'currency' => 'OMR', 'commissioned' => 1, 'status' => 'pending'],
    ]);

    // A UAE agency: a per-person trip with a booking, and a tier-priced trip that cannot move.
    Legacy::insert('travel_agencies', [['id' => $ids['ta1'], 'name' => 'Legacy Tours', 'email' => 'tours@legacy.test', 'password' => $hash, 'country_id' => $c['uae'], 'status' => 'active']]);
    $package = fn (string $id, string $slug, int $tiers): array => ['id' => $ids[$id], 'travel_agency_id' => $ids['ta1'], 'slug' => $slug, 'title_en' => 'Baku '.$slug, 'title_ar' => 'باكو', 'destination_city' => 'Baku', 'destination_country' => 'Azerbaijan', 'price' => '1500.0000', 'currency' => 'AED', 'pricing_tiers_enabled' => $tiers, 'show_pricing_tiers_to_customer' => 0, 'duration_days' => 5, 'duration_nights' => 4, 'departure_date' => '2027-03-01', 'return_date' => '2027-03-05', 'available_seats' => 20, 'seats_booked' => 2, 'status' => 'active', 'campaign_enabled' => 0];
    Legacy::insert('travel_packages', [$package('tp1', 'baku-spring', 0), $package('tp2', 'baku-groups', 1)]);
    Legacy::insert('travel_bookings', [
        ['id' => $ids['tb1'], 'booking_number' => 'TB-OLD-1', 'travel_package_id' => $ids['tp1'], 'customer_id' => $ids['c1'], 'travelers_count' => 2, 'total_price' => '3000.0000', 'status' => 'confirmed', 'passport_file_path' => 'travel-bookings/passports/p.pdf', 'created_at' => '2026-02-01 10:00:00'],
        ['id' => $ids['tb2'], 'booking_number' => 'TB-OLD-2', 'travel_package_id' => $ids['tp2'], 'customer_id' => $ids['c1'], 'travelers_count' => 4, 'total_price' => '5000.0000', 'status' => 'pending_documents'],
    ]);

    Legacy::insert('classified_categories', [
        ['id' => $ids['cc1'], 'name_en' => 'Real estate', 'name_ar' => 'عقارات', 'slug' => 'real_estate', 'is_active' => 1, 'sort_order' => 1, 'requires_location_map' => 1, 'requires_sketch_upload' => 0],
        ['id' => $ids['cc2'], 'parent_id' => $ids['cc1'], 'name_en' => 'Apartments', 'name_ar' => 'شقق', 'slug' => 'apartments', 'is_active' => 1, 'sort_order' => 1, 'requires_location_map' => 1, 'requires_sketch_upload' => 0, 'requires_contract' => 1],
    ]);
    Legacy::insert('classified_listings', [
        ['id' => $ids['cl1'], 'listing_number' => 'CL-OLD-1', 'slug' => 'flat', 'seller_type' => 'App\\Models\\Customer', 'seller_id' => $ids['c1'], 'classified_category_id' => $ids['cc2'], 'country_id' => $c['uae'], 'listing_purpose' => 'rent', 'title_en' => 'Marina flat', 'title_ar' => 'شقة المارينا', 'price' => '750000.0000', 'currency' => 'AED', 'price_negotiable' => 1, 'attributes' => ['bedrooms' => 3], 'status' => 'active', 'views_count' => 12, 'is_vendor_listing' => 0, 'campaign_enabled' => 0, 'contract_signature_data' => 'signed-blob'],
        ['id' => $ids['cl2'], 'listing_number' => 'CL-OLD-2', 'slug' => 'villa', 'seller_type' => 'App\\Models\\Vendor', 'seller_id' => $ids['v2'], 'classified_category_id' => $ids['cc2'], 'country_id' => $c['uae'], 'listing_purpose' => 'sale', 'title_en' => 'Villa', 'title_ar' => 'فيلا', 'price' => '1.0000', 'currency' => 'AED', 'price_negotiable' => 0, 'status' => 'active', 'views_count' => 0, 'is_vendor_listing' => 1, 'campaign_enabled' => 0],
    ]);

    Legacy::insert('gift_cards', [
        ['id' => $ids['gc1'], 'source' => 'purchased', 'code' => 'ABCD1234EFGH5678', 'pin_hash' => $hash, 'amount' => '100.0000', 'remaining_balance' => '40.5000', 'currency_code' => 'AED', 'status' => 'active', 'purchased_by_customer_id' => $ids['c1'], 'recipient_email' => 'Gift@Legacy.test', 'recipient_name' => 'Gift Friend', 'expires_at' => '2027-01-01 00:00:00'],
        ['id' => $ids['gc2'], 'source' => 'batch', 'code' => 'ZZZZ9999YYYY8888', 'pin_hash' => $hash, 'amount' => '50.0000', 'remaining_balance' => '50.0000', 'currency_code' => 'AED', 'status' => 'inactive', 'expires_at' => '2027-01-01 00:00:00'],
    ]);

    Legacy::insert('support_tickets', [
        ['id' => $ids['st1'], 'ticket_number' => 'TK-OLD-1', 'requester_user_id' => $ids['c1'], 'requester_role' => 'customer', 'category' => 'order_issue', 'priority' => 'high', 'status' => 'in_progress', 'subject' => 'Where is my kettle?', 'description' => 'It has not arrived.', 'created_at' => '2026-02-05 10:00:00'],
        ['id' => $ids['st2'], 'ticket_number' => 'TK-OLD-2', 'requester_user_id' => $ids['c3'], 'requester_role' => 'delivery_agent', 'category' => 'technical', 'priority' => 'normal', 'status' => 'open', 'subject' => 'App crash', 'description' => 'Crashes.'],
    ]);
    Legacy::insert('ticket_messages', [
        ['id' => $ids['tm1'], 'ticket_id' => $ids['st1'], 'sender_type' => 'App\\Models\\Admin', 'sender_id' => $ids['a1'], 'message' => 'Checking with the store.', 'is_internal_note' => 0, 'is_ai_generated' => 0, 'created_at' => '2026-02-05 11:00:00'],
        ['id' => $ids['tm2'], 'ticket_id' => $ids['st1'], 'sender_type' => 'App\\Models\\Customer', 'sender_id' => $ids['c1'], 'message' => 'Thanks', 'is_internal_note' => 0, 'is_ai_generated' => 0, 'created_at' => '2026-02-05 12:00:00'],
        ['id' => $ids['tm3'], 'ticket_id' => $ids['st2'], 'sender_type' => 'App\\Models\\DeliveryAgent', 'sender_id' => $ids['c3'], 'message' => 'Still crashing', 'is_internal_note' => 0, 'is_ai_generated' => 0, 'created_at' => '2026-02-05 12:00:00'],
    ]);

    return $ids;
}

function legacyTarget(string $sourceTable, string $sourceId, string $db, string $targetTable): ?int
{
    $id = DB::table('migration_id_map')->where('source_table', $sourceTable)->where('source_id', $sourceId)->where('target_db', $db)->where('target_table', $targetTable)->value('target_id');

    return $id === null ? null : (int) $id;
}

function legacyQuarantine(string $table, string $sourceId): ?string
{
    return DB::table('migration_quarantine')->where('source_table', $table)->where('source_id', $sourceId)->whereNull('resolved_at')->value('reason');
}

test('identities merge by email into one user with role memberships, keeping the password hash', function () {
    $this->artisan('legacy:migrate', ['step' => 'identity'])->assertSuccessful();

    $user = DB::table('users')->where('email', 'dual@legacy.test')->first();
    expect($user)->not->toBeNull()
        ->and(password_verify('secret-password', $user->password))->toBeTrue()
        ->and($user->preferred_locale)->toBe('ar')
        ->and(legacyTarget('vendor_admins', $this->legacy['va1'], 'central', 'users'))->toBe((int) $user->id);

    // One person, a profile in each country with data (plan §4.2): customer in the UAE and in Oman (an order there), owner of the store in both.
    $roles = DB::table('role_memberships')->join('countries', 'countries.id', '=', 'role_memberships.country_id')->where('user_id', $user->id)
        ->orderBy('role')->orderBy('countries.code')->get()->map(fn (object $row): string => $row->role.'@'.$row->code)->all();
    expect($roles)->toBe(['customer@omn', 'customer@uae', 'vendor:product@omn', 'vendor:product@uae']);

    $vendorId = legacyTarget('vendors', $this->legacy['v1'], 'uae', 'vendors');
    expect(DB::connection('country_uae')->table('vendors')->where('id', $vendorId)->value('owner_user_id'))->toBe((int) $user->id);

    // D9: a phone-only customer gets a placeholder address and must add an email.
    $phoneOnly = DB::table('users')->where('legacy_id', $this->legacy['c2'])->first();
    expect($phoneOnly->requires_email)->toBe(1)
        ->and($phoneOnly->email)->toBe('phone-966500000002@legacy.nawy.invalid')
        ->and($phoneOnly->phone)->toBe('+966500000002');

    // Same email, a different phone: never merged on a weak signal.
    expect(legacyQuarantine('delivery_agents', $this->legacy['c3']))->toBe('identity_conflict');

    // A super admin is a country-less admin membership.
    $admin = DB::table('users')->where('email', 'root@legacy.test')->value('id');
    expect(DB::table('role_memberships')->where('user_id', $admin)->where('role', 'admin')->whereNull('country_id')->exists())->toBeTrue();
});

test('rows are split by country: reference replicated, products where used, vendors where they sell', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();

    foreach (array_keys(config('nawy.countries')) as $code) {
        expect(DB::connection("country_{$code}")->table('categories')->where('slug', 'legacy-child')->exists())->toBeTrue();
    }

    $child = DB::connection('country_uae')->table('categories')->where('slug', 'legacy-child')->first();
    expect($child->parent_id)->toBe(DB::connection('country_uae')->table('categories')->where('slug', 'legacy-root')->value('id'))
        ->and(json_decode(DB::connection('country_uae')->table('category_attributes')->where('category_id', $child->id)->value('options'), true))->toBe(['Black']);

    // R5: the product goes to UAE (product_countries) and Oman (a listing), nowhere else.
    expect(array_keys(DB::table('migration_id_map')->where('source_table', 'products')->where('target_table', 'products')->pluck('target_id', 'target_db')->all()))
        ->toEqualCanonicalizing(['uae', 'omn']);
    expect(json_decode(DB::connection('country_omn')->table('product_variants')->where('sku', 'LEG-KETTLE-1')->value('option_values'), true))->toBe(['colour' => 'Black']);

    // One vendor identity, a vendor row in each country it sells in.
    expect(legacyTarget('vendors', $this->legacy['v1'], 'uae', 'vendors'))->not->toBeNull()
        ->and(legacyTarget('vendors', $this->legacy['v1'], 'omn', 'vendors'))->not->toBeNull()
        ->and(legacyQuarantine('vendors', $this->legacy['v2']))->toBe('country_unresolved');

    // The OMR listing keeps 3+ decimals untouched; like-new folds into "used".
    $omn = DB::connection('country_omn')->table('listings')->where('id', legacyTarget('vendor_listings', $this->legacy['l2'], 'omn', 'listings'))->first();
    expect($omn->price)->toBe('10.1250')->and($omn->condition)->toBe('used');
    $uae = DB::connection('country_uae')->table('listings')->where('id', legacyTarget('vendor_listings', $this->legacy['l1'], 'uae', 'listings'))->first();
    expect($uae->price)->toBe('120.0000')->and($uae->sale_price)->toBe('100.5000');

    // The payment of an Omani order lands in Oman, transient rows are dropped.
    expect(DB::connection('country_omn')->table('payments')->where('gateway_reference', 'cod-1')->value('status'))->toBe('pending')
        ->and(DB::table('legacy_records')->where('source_table', 'cache')->exists())->toBeFalse();
});

test('category slugs become storefront URL segments, repairing earlier runs and redirecting the old URL', function () {
    $smartHome = Legacy::uuid();
    $kidsToys = Legacy::uuid();
    Legacy::insert('categories', [
        ['id' => $smartHome, 'parent_id' => $this->legacy['cat_root'], 'name_en' => 'Smart Home', 'name_ar' => 'منزل ذكي', 'slug' => 'smart_home', 'depth' => 1, 'lft' => 5, 'rgt' => 6, 'is_active' => 1],
        ['id' => $kidsToys, 'parent_id' => null, 'name_en' => "Kids' Toys", 'name_ar' => 'ألعاب', 'slug' => 'kids_toys', 'depth' => 0, 'lft' => 7, 'rgt' => 8, 'is_active' => 1],
    ]);
    $existing = DB::connection('country_uae')->table('categories')->insertGetId(['slug' => 'kids-toys', 'name_en' => 'Toys', 'name_ar' => 'ألعاب', 'is_active' => true]);

    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();

    $uae = DB::connection('country_uae')->table('categories');
    expect($uae->clone()->where('id', legacyTarget('categories', $smartHome, 'uae', 'categories'))->value('slug'))->toBe('smart-home')
        ->and($uae->clone()->where('slug', 'like', '%\_%')->exists())->toBeFalse()
        ->and(legacyTarget('categories', $kidsToys, 'uae', 'categories'))->toBe($existing)
        ->and(DB::connection('country_omn')->table('categories')->where('id', legacyTarget('categories', $kidsToys, 'omn', 'categories'))->value('slug'))->toBe('kids-toys');

    // A run before the normalisation kept the legacy slug: the next run renames it in place.
    $target = legacyTarget('categories', $smartHome, 'omn', 'categories');
    DB::connection('country_omn')->table('categories')->where('id', $target)->update(['slug' => 'smart_home']);
    DB::table('migration_id_map')->where('source_table', 'categories')->where('source_id', $smartHome)->where('target_db', 'omn')->update(['checksum' => 'before-normalisation']);

    $this->artisan('legacy:migrate', ['step' => 'catalog'])->assertSuccessful();
    expect(DB::connection('country_omn')->table('categories')->where('id', $target)->value('slug'))->toBe('smart-home')
        ->and(legacyTarget('categories', $smartHome, 'omn', 'categories'))->toBe($target);

    $this->artisan('legacy:redirects')->assertSuccessful();
    expect(DB::table('legacy_redirects')->where('from_path', '/omn-ar/smart_home')->value('to_path'))->toBe('/omn-ar/smart-home')
        ->and(DB::table('legacy_redirects')->where('from_path', '/uae-en/legacy-child')->exists())->toBeFalse();
});

test('legacy contract templates arrive as drafts, so no store is sent to re-sign until an admin publishes them', function () {
    $agreement = Legacy::uuid();
    Legacy::insert('classified_contract_templates', [['id' => $agreement, 'category_scope' => 'platform', 'contract_kind' => 'seller_platform', 'name' => 'Legacy Seller Agreement', 'content_mode' => 'editor', 'version' => 1, 'content_en' => '<p>Seller agreement</p>', 'content_ar' => '<p>اتفاقية البائع</p>', 'created_by_admin_id' => $this->legacy['a1'], 'is_published' => 1, 'is_active' => 1, 'published_at' => '2026-11-02 15:45:25']]);

    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();

    $versions = DB::table('contract_versions')->whereIn('contract_template_id', DB::table('contract_templates')->whereNotNull('legacy_id')->pluck('id'))->get();
    expect($versions)->toHaveCount(2)
        ->and($versions->pluck('status')->unique()->all())->toBe(['draft'])
        ->and($versions->pluck('published_at')->filter()->all())->toBe([]);

    $uae = Country::query()->where('code', 'uae')->sole();
    app(CountryContext::class)->runAs($uae, function () use ($uae) {
        expect(app(ContractResolver::class)->current(ContractKind::SellerPlatform, $uae))->toBeNull();
    });

    // Publishing the reviewed draft keeps the migrated signatures of that version.
    $product = ContractVersion::query()->findOrFail(legacyTarget('classified_contract_templates', $this->legacy['t1'].':v1', 'central', 'contract_versions'));
    app(ContractPublisher::class)->publish($product, 1);
    $signature = DB::connection('country_uae')->table('contract_signatures')->where('id', legacyTarget('vendor_contracts', $this->legacy['vc1'], 'uae', 'contract_signatures'))->first();
    expect($signature->contract_version_id)->toBe($product->id)
        ->and($signature->status)->toBe('active');
});

test('orders move exactly, and orders that cannot be placed are quarantined with their children', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();

    $order = DB::connection('country_omn')->table('orders')->where('number', 'OLD-OMN-1')->first();
    expect($order->grand_total)->toBe('23.3380')
        ->and($order->currency)->toBe('OMR')
        ->and($order->cod_fee)->toBe('0.5000')
        ->and($order->tax_included)->toBe('1.0880')
        ->and($order->status)->toBe('shipped');

    $sub = DB::connection('country_omn')->table('sub_orders')->where('order_id', $order->id)->first();
    expect($sub->total)->toBe('22.8380')->and($sub->shipping_method)->toBe('standard');
    expect(DB::connection('country_omn')->table('order_items')->where('sub_order_id', $sub->id)->value('line_total'))->toBe('21.3380');
    expect(DB::connection('country_omn')->table('order_status_history')->where('sub_order_id', $sub->id)->value('actor_type'))->toBe('admin');

    // USD in the UAE: never converted, quarantined with everything under it.
    expect(legacyQuarantine('orders', $this->legacy['o2']))->toBe('currency_mismatch')
        ->and(legacyQuarantine('sub_orders', $this->legacy['s2']))->toBe('parent_quarantined')
        ->and(legacyQuarantine('order_items', $this->legacy['i2']))->toBe('parent_quarantined')
        ->and(DB::connection('country_uae')->table('orders')->where('number', 'OLD-USD-1')->exists())->toBeFalse();
});

test('wallets reproduce the legacy balance_after and ledger groups stay balanced', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();
    $db = DB::connection('country_uae');

    $accountId = legacyTarget('wallets', $this->legacy['w1'], 'uae', 'ledger_accounts');
    $account = $db->table('ledger_accounts')->where('id', $accountId)->first();
    expect($account->balance)->toBe('40.1500')->and($account->code)->toBe('vendor_payable')->and($account->bucket)->toBe('spendable');
    expect($db->table('ledger_entries')->where('ledger_account_id', $accountId)->orderBy('id')->pluck('balance_after')->all())->toBe(['50.2500', '40.1500']);

    foreach ([$this->legacy['wt1'], $this->legacy['wt2']] as $transaction) {
        $id = legacyTarget('wallet_transactions', $transaction, 'uae', 'ledger_transactions');
        $legs = $db->table('ledger_entries')->where('ledger_transaction_id', $id)->get();
        expect($legs)->toHaveCount(2)
            ->and((string) $legs->where('direction', 'debit')->sum('amount'))->toBe((string) $legs->where('direction', 'credit')->sum('amount'));
    }

    // A chain that does not reproduce the wallet balance is not written at all.
    expect(legacyQuarantine('wallets', $this->legacy['w2']))->toBe('balance_mismatch')
        ->and(legacyQuarantine('wallet_transactions', $this->legacy['wt3']))->toBe('parent_quarantined');

    // Legacy ledger groups: balanced ones move (bucket legacy), unbalanced ones are quarantined whole.
    $group = legacyTarget('ledger_groups', $this->legacy['g1'], 'uae', 'ledger_transactions');
    $entries = $db->table('ledger_entries')->where('ledger_transaction_id', $group)->get();
    expect($entries)->toHaveCount(3)
        ->and($entries->where('direction', 'debit')->sum('amount'))->toEqual($entries->where('direction', 'credit')->sum('amount'))
        ->and($db->table('ledger_transactions')->where('id', $group)->value('total'))->toBe('105.0000')
        ->and(DB::table('migration_quarantine')->where('source_table', 'ledger_entries')->where('reason', 'ledger_unbalanced')->count())->toBe(2);
});

test('contract signatures are re-verified against their legacy hash before they move', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();

    $signature = DB::connection('country_uae')->table('contract_signatures')->where('id', legacyTarget('vendor_contracts', $this->legacy['vc1'], 'uae', 'contract_signatures'))->first();
    expect($signature)->not->toBeNull()
        ->and(hash('sha256', (string) Storage::disk(ContractRenderer::DISK)->get($signature->frozen_path)))->toBe($signature->sha256)
        ->and($signature->signer_user_id)->toBe(DB::table('users')->where('email', 'dual@legacy.test')->value('id'))
        ->and(legacyQuarantine('vendor_contracts', $this->legacy['vc2']))->toBe('signature_hash_mismatch');
});

test('a second run changes nothing and a dry run writes nothing', function () {
    $this->artisan('legacy:migrate', ['step' => 'all', '--dry-run' => true])->assertSuccessful();
    expect(DB::table('migration_id_map')->count())->toBe(0)
        ->and(DB::table('users')->whereNotNull('legacy_id')->count())->toBe(0)
        ->and(DB::table('migration_quarantine')->count())->toBe(0);

    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();
    $snapshot = fn (): array => [
        DB::table('migration_id_map')->orderBy('id')->get(['source_table', 'source_id', 'target_db', 'target_id', 'checksum'])->toArray(),
        DB::table('users')->count(),
        DB::table('role_memberships')->count(),
        DB::connection('country_uae')->table('ledger_entries')->count(),
        DB::connection('country_uae')->table('ledger_accounts')->pluck('balance', 'id')->all(),
        DB::connection('country_omn')->table('orders')->count(),
        DB::table('migration_quarantine')->whereNull('resolved_at')->count(),
    ];
    $first = $snapshot();

    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();
    expect($snapshot())->toEqual($first);

    $metrics = collect(DB::table('migration_runs')->latest('id')->limit(count(TableRules::STEPS))->pluck('metrics'))->map(fn (string $json): array => json_decode($json, true));
    expect($metrics->flatMap(fn (array $tables): array => array_merge(...array_values(array_map(fn (array $counts): array => array_keys($counts), $tables))))->intersect(['written', 'updated', 'archived', 'archived_updated'])->all())->toBe([]);
});

test('a fixed legacy row leaves the quarantine on the next run', function () {
    $this->artisan('legacy:migrate', ['step' => 'identity'])->assertSuccessful();
    expect(legacyQuarantine('vendors', $this->legacy['v2']))->toBe('country_unresolved');

    Legacy::update('vendors', $this->legacy['v2'], ['country_id' => Legacy::COUNTRIES['kwt']]);
    $this->artisan('legacy:migrate', ['step' => 'identity'])->assertSuccessful();

    expect(legacyQuarantine('vendors', $this->legacy['v2']))->toBeNull()
        ->and(legacyTarget('vendors', $this->legacy['v2'], 'kwt', 'vendors'))->not->toBeNull();
});

test('reconciliation accounts for every legacy row and masks personal data in its report', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();
    $report = storage_path('framework/testing/reconciliation-report.md');

    $this->artisan('legacy:reconcile', ['--report' => $report])->assertSuccessful();

    $content = File::get($report);
    expect($content)->toContain('**Clean:**')
        ->toContain('| Σ order totals | OMR | 23.3380 | 23.3380 | yes |')
        ->toContain('| orders | currency_mismatch | 1 |')
        ->toContain('| Σ payments | OMR | 46.6760 | 46.6760 | yes |')
        ->toContain('| Σ campaign budget spent | OMR | 2.0250 | 2.0250 | yes |')
        ->toContain('| Σ gift card balances | AED | 40.5000 | 40.5000 | yes |')
        ->not->toContain('dual@legacy.test')
        ->not->toContain('+971500000001');
    expect(Reconciler::mask('call +971 50 000 0001 or mail someone@example.com, order 01a111ff-5e11-7106-8c98'))
        ->toBe('call •••••••••••••001 or mail so***@***, order 01a111ff-5e11-7106-8c98');
});

test('reconciliation reports a legacy row that was never accounted for', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();
    Legacy::insert('coupons', [['id' => Legacy::uuid(), 'code' => 'LATE', 'name' => 'Late', 'type' => 'percentage', 'value' => '5', 'scope' => 'platform', 'country_ids' => [Legacy::COUNTRIES['uae']], 'customer_eligibility' => 'all', 'created_by_user_id' => Legacy::uuid(), 'valid_from' => '2026-01-01', 'valid_until' => '2027-01-01']]);

    $this->artisan('legacy:reconcile', ['--report' => storage_path('framework/testing/reconciliation-report.md')])->assertFailed();
});

test('the redirect map sends old seller, help and blog URLs to the new website', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();
    $this->artisan('legacy:redirects')->assertSuccessful();

    $slug = DB::connection('country_uae')->table('vendors')->where('id', legacyTarget('vendors', $this->legacy['v1'], 'uae', 'vendors'))->value('slug');
    $redirects = DB::table('legacy_redirects')->pluck('to_path', 'from_path');

    expect($redirects["/uae-en/seller/{$this->legacy['v1']}"])->toBe("/uae-en/seller/{$slug}")
        ->and($redirects['/uae-ar/my-bookings'])->toBe('/uae-ar/account/bookings')
        ->and($redirects["/omn-en/orders/{$this->legacy['o1']}"])->toBe('/omn-en/account/orders/OLD-OMN-1')
        ->and($redirects['/omn/helpcenter/article/when-am-i-paid'])->toBe('/omn-en/help/articles/when-am-i-paid')
        ->and($redirects['/blog/hello-legacy'])->toBe('/uae-en/blog/hello-legacy')
        ->and(DB::table('legacy_redirects')->where('status', '!=', 301)->exists())->toBeFalse();

    $export = json_decode(Storage::disk('local')->get(config('nawy.legacy.redirects_export')), true);
    expect($export)->toHaveCount($redirects->count());
});

test('payments, refunds and payouts move with exact money; what has no new equivalent is quarantined', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();
    $omn = DB::connection('country_omn');

    // An authorisation and its capture are one payment, refunded in part by the gateway refund.
    $paymentId = legacyTarget('payment_transactions', $this->legacy['pt_auth'], 'omn', 'payments');
    $payment = $omn->table('payments')->where('id', $paymentId)->first();
    expect($payment->reference)->toBe($this->legacy['pt_auth'])
        ->and($payment->method)->toBe('thawani')->and($payment->status)->toBe('partially_refunded')
        ->and($payment->amount)->toBe('23.3380')->and($payment->captured_amount)->toBe('23.3380')->and($payment->refunded_amount)->toBe('5.0000')
        ->and($payment->order_id)->toBe(legacyTarget('orders', $this->legacy['o1'], 'omn', 'orders'))
        ->and(legacyTarget('payment_transactions', $this->legacy['pt_cap'], 'omn', 'payments'))->toBe($paymentId);
    $gatewayRefund = $omn->table('payment_refunds')->where('id', legacyTarget('payment_transactions', $this->legacy['pt_ref'], 'omn', 'payment_refunds'))->first();
    expect($gatewayRefund->payment_id)->toBe($paymentId)->and($gatewayRefund->amount)->toBe('5.0000')->and($gatewayRefund->status)->toBe('completed')
        ->and(legacyQuarantine('payment_transactions', $this->legacy['pt_cb']))->toBe('shape_unsupported');

    // The business refund carries the net amount; the deductions stay readable next to it.
    $refund = $omn->table('refunds')->where('id', legacyTarget('refunds', $this->legacy['rf1'], 'omn', 'refunds'))->first();
    expect($refund->amount)->toBe('5.0000')->and($refund->status)->toBe('completed')->and($refund->currency)->toBe('OMR')
        ->and($refund->payment_refund_id)->toBe($gatewayRefund->id)
        ->and($refund->sub_order_id)->toBe(legacyTarget('sub_orders', $this->legacy['s1'], 'omn', 'sub_orders'))
        ->and(json_decode($omn->table('legacy_records')->where('source_table', 'refunds')->value('payload'), true)['gateway_fee_deducted'])->toBe('1.0000');

    $payout = $omn->table('vendor_payouts')->where('id', legacyTarget('payouts', '1', 'omn', 'vendor_payouts'))->first();
    expect($payout->number)->toBe('PO-OLD-1')->and($payout->total)->toBe('18.2250')->and($payout->status)->toBe('completed')
        ->and($payout->schedule)->toBe('monthly')->and($payout->bank_reference)->toBe('BANK-77')
        ->and(legacyQuarantine('payouts', '2'))->toBe('parent_unmigrated');
});

test('campaigns keep budgets, fees and referral codes, so /r/{code} links keep working', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();
    $omn = DB::connection('country_omn');

    $campaign = $omn->table('marketer_campaigns')->where('id', legacyTarget('marketer_campaigns', $this->legacy['mc1'], 'omn', 'marketer_campaigns'))->first();
    expect($campaign->origin)->toBe('vendor')->and($campaign->status)->toBe('active')->and($campaign->currency)->toBe('OMR')
        ->and($campaign->budget)->toBe('50.0000')->and($campaign->budget_spent)->toBe('2.0250')
        ->and($campaign->fee_amount)->toBe('1.5000')->and($campaign->fee_status)->toBe('charged')
        ->and(json_decode($campaign->fee_snapshot, true)['legacy']['campaign_grand_total'])->toBe('51.5000')
        ->and($campaign->listing_id)->toBe(legacyTarget('vendor_listings', $this->legacy['l2'], 'omn', 'listings'))
        ->and(legacyQuarantine('marketer_campaigns', $this->legacy['mc2']))->toBe('shape_unsupported');

    $invitation = $omn->table('campaign_invitations')->where('id', legacyTarget('marketer_campaign_invitations', $this->legacy['inv1'], 'omn', 'campaign_invitations'))->first();
    expect($invitation->referral_code)->toBe('Legacy10')->and($invitation->status)->toBe('accepted')->and($invitation->effective_type)->toBe('affiliate')
        ->and(DB::table('referral_codes')->where('code', 'Legacy10')->value('invitation_id'))->toBe($invitation->id)
        ->and(app(ReferralCodes::class)->countryOf('LEGACY10')?->code)->toBe('omn');
    $this->getJson('/api/v1/omn-en/referrals/LEGACY10')->assertOk()->assertJsonPath('data.creator.name', 'Legacy Creator');

    // A replaced invitation (no new status) is cancelled; it never had a link, so it gets an internal code.
    $replaced = $omn->table('campaign_invitations')->where('id', legacyTarget('marketer_campaign_invitations', $this->legacy['inv2'], 'omn', 'campaign_invitations'))->first();
    expect($replaced->status)->toBe('cancelled')->and($replaced->referral_code)->toMatch('/^L[A-Z2-9]{9}$/');

    $conversion = $omn->table('marketer_conversions')->where('id', legacyTarget('marketer_campaign_conversions', $this->legacy['conv1'], 'omn', 'marketer_conversions'))->first();
    expect($conversion->status)->toBe('released')->and($conversion->gross)->toBe('2.0250')->and($conversion->fee)->toBe('0.2025')->and($conversion->net)->toBe('1.8225')
        ->and($conversion->basis)->toBe('20.2500')->and($conversion->invitation_id)->toBe($invitation->id)
        ->and($conversion->source_id)->toBe(legacyTarget('order_items', $this->legacy['i1'], 'omn', 'order_items'))
        ->and(legacyQuarantine('marketer_campaign_conversions', $this->legacy['conv2']))->toBe('shape_unsupported');
});

test('travel, classifieds, gift cards and support tickets move to their country', function () {
    $this->artisan('legacy:migrate', ['step' => 'all'])->assertSuccessful();
    $uae = DB::connection('country_uae');

    // One departure with the per-person price on every adult room type; the booking keeps its exact total.
    $packageId = legacyTarget('travel_packages', $this->legacy['tp1'], 'uae', 'travel_packages');
    $departure = $uae->table('travel_departures')->where('travel_package_id', $packageId)->first();
    expect($uae->table('travel_packages')->where('id', $packageId)->value('vendor_id'))->toBe(legacyTarget('travel_agencies', $this->legacy['ta1'], 'uae', 'vendors'))
        ->and($departure->seat_capacity)->toBe(20)->and($departure->seats_reserved)->toBe(2)->and($departure->status)->toBe('open')
        ->and($uae->table('travel_departure_rooms')->where('travel_departure_id', $departure->id)->pluck('price_per_person', 'room_type')->all())
        ->toEqual(['single' => '1500.0000', 'double' => '1500.0000', 'triple' => '1500.0000', 'quad' => '1500.0000']);
    $booking = $uae->table('travel_bookings')->where('id', legacyTarget('travel_bookings', $this->legacy['tb1'], 'uae', 'travel_bookings'))->first();
    expect($booking->number)->toBe('TB-OLD-1')->and($booking->total)->toBe('3000.0000')->and($booking->status)->toBe('confirmed')
        ->and($booking->travel_departure_id)->toBe($departure->id)->and($booking->contact_email)->toBe('dual@legacy.test')
        ->and(json_decode($booking->rooms, true))->toEqual([['room_type' => 'per_person', 'travellers' => 2, 'unit_price' => '1500.0000', 'line_total' => '3000.0000']])
        ->and(legacyQuarantine('travel_packages', $this->legacy['tp2']))->toBe('pricing_model_unsupported')
        ->and(legacyQuarantine('travel_bookings', $this->legacy['tb2']))->toBe('parent_quarantined');

    foreach (array_keys(config('nawy.countries')) as $code) {
        expect(DB::connection("country_{$code}")->table('classified_categories')->where('slug', 'real-estate')->exists())->toBeTrue();
    }

    $ad = $uae->table('classified_ads')->where('id', legacyTarget('classified_listings', $this->legacy['cl1'], 'uae', 'classified_ads'))->first();
    expect($ad->number)->toBe('CL-OLD-1')->and($ad->price)->toBe('750000.0000')->and($ad->status)->toBe('active')->and($ad->views)->toBe(12)
        ->and($ad->owner_user_id)->toBe(DB::table('users')->where('email', 'dual@legacy.test')->value('id'))
        ->and(json_decode($ad->specs, true))->toMatchArray(['bedrooms' => 3, 'purpose' => 'rent', 'title_ar' => 'شقة المارينا'])
        ->and($ad->classified_category_id)->toBe($uae->table('classified_categories')->where('slug', 'apartments')->value('id'))
        ->and(legacyQuarantine('classified_listings', $this->legacy['cl2']))->toBe('owner_unresolved');

    // Gift card codes are never stored in clear text, yet still redeem (hash) and resend (encrypted).
    $card = $uae->table('gift_cards')->where('id', legacyTarget('gift_cards', $this->legacy['gc1'], 'uae', 'gift_cards'))->first();
    expect($card->code_hash)->toBe(app(GiftCodes::class)->hash('ABCD-1234-EFGH-5678'))->and(Crypt::decryptString($card->code_encrypted))->toBe('ABCD1234EFGH5678')
        ->and($card->code_last4)->toBe('5678')->and($card->amount)->toBe('100.0000')->and($card->balance)->toBe('40.5000')
        ->and($card->recipient_email)->toBe('gift@legacy.test')->and($card->source)->toBe('purchase')
        ->and($uae->table('legacy_records')->where('source_table', 'gift_cards')->value('payload'))->not->toContain('ABCD1234EFGH5678')
        ->and(legacyQuarantine('gift_cards', $this->legacy['gc2']))->toBe('status_unmapped');

    $ticket = $uae->table('support_tickets')->where('id', legacyTarget('support_tickets', $this->legacy['st1'], 'uae', 'support_tickets'))->first();
    expect($ticket->number)->toBe('TK-OLD-1')->and($ticket->requester_type)->toBe('customer')->and($ticket->category)->toBe('order')
        ->and($ticket->priority)->toBe('high')->and($ticket->status)->toBe('in_progress')
        ->and($uae->table('support_ticket_messages')->where('support_ticket_id', $ticket->id)->orderBy('created_at')->pluck('author_type')->all())->toBe(['customer', 'admin', 'customer'])
        ->and(legacyQuarantine('support_tickets', $this->legacy['st2']))->toBe('shape_unsupported')
        ->and(legacyQuarantine('ticket_messages', $this->legacy['tm3']))->toBe('parent_quarantined');
});

test('the legacy connection is read only', function () {
    expect(fn () => DB::connection('legacy')->table('customers')->insert(['id' => Legacy::uuid(), 'name' => 'x', 'email' => 'x@x.test', 'password' => 'x']))
        ->toThrow(QueryException::class, 'READ ONLY');
});
