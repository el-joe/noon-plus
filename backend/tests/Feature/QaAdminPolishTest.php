<?php

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Models\CountryCalendar;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Panels\Notifications\TeamInvitationNotification;
use App\Domains\Shipping\Models\ShippingZone;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Warranty\Models\WarrantyPlan;
use App\Models\User;
use App\Shared\StateMachine\StateTransition;
use App\Shared\Support\UserNames;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\CountryCalendarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

/*
 * Browser-QA admin polish: QA-C08 (dispatch board paging + filters),
 * BT-ADM-005…011 (validation names, scoped form errors, actor names,
 * brand old input, invitation subject, role display names, holiday data)
 * and the dev image CSP of the panels.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
});

it('pages the ready-to-dispatch board so parcel 51+ can be reached, and filters it (QA-C08)', function () {
    $ops = adminAccount('uae', 'operations');
    $vendor = Vendor::factory()->create(['store_name' => 'Board Store']);
    $listing = sellableListing('100.00', stock: 100, vendor: $vendor);
    $parcels = collect(range(1, 55))->map(fn () => subOrderFor($vendor, SubOrderStatus::Packed, listing: $listing));
    $last = $parcels->last();

    $first = $this->actingAs($ops)->get(onPanel('admin', 'delivery/assignments'))->assertOk();
    $first->assertSee($parcels->first()->number)->assertDontSee($last->number)->assertSee('55 parcels');

    $this->actingAs($ops)->get(onPanel('admin', 'delivery/assignments?ready_page=3'))->assertOk()
        ->assertSee($last->number)->assertDontSee($parcels->first()->number);

    // Search by parcel or order number.
    $this->actingAs($ops)->get(onPanel('admin', 'delivery/assignments?q='.urlencode($last->number)))->assertOk()
        ->assertSee($last->number)->assertSee('1 parcel')->assertDontSee($parcels->first()->number);

    // Zone, seller, city, payment, shipping company and placed date.
    $zone = ShippingZone::query()->create(['name' => 'North', 'is_active' => true]);
    $zoned = $parcels[10];
    $zoned->forceFill(['shipping_zone_id' => $zone->id])->save();
    $this->actingAs($ops)->get(onPanel('admin', "delivery/assignments?zone={$zone->id}"))->assertOk()
        ->assertSee($zoned->number)->assertSee('1 parcel');

    $other = subOrderFor(Vendor::factory()->create(['store_name' => 'Other Store']), SubOrderStatus::Packed);
    $other->order->forceFill(['payment_method' => 'stripe', 'shipping_address' => ['city' => 'Sharjah', 'area' => 'Nahda'], 'placed_at' => now()->addDays(3)])->save();
    $this->actingAs($ops)->get(onPanel('admin', "delivery/assignments?seller={$other->vendor_id}"))->assertSee($other->number)->assertSee('1 parcel');
    $this->actingAs($ops)->get(onPanel('admin', 'delivery/assignments?city=sharj'))->assertSee($other->number)->assertSee('1 parcel');
    $this->actingAs($ops)->get(onPanel('admin', 'delivery/assignments?payment=prepaid'))->assertSee($other->number)->assertSee('1 parcel');
    $this->actingAs($ops)->get(onPanel('admin', 'delivery/assignments?from='.now()->addDays(2)->toDateString()))->assertSee($other->number)->assertSee('1 parcel');

    $carrier = Carrier::query()->create(['code' => 'swift', 'name' => 'Swift Couriers', 'status' => 'active']);
    Shipment::query()->create(['sub_order_id' => $zoned->id, 'carrier_id' => $carrier->id, 'number' => 'SH-'.$zoned->number, 'status' => 'label_created']);
    $this->actingAs($ops)->get(onPanel('admin', "delivery/assignments?carrier={$carrier->id}"))->assertSee($zoned->number)->assertSee('1 parcel');
    $this->actingAs($ops)->get(onPanel('admin', 'delivery/assignments?carrier=none'))->assertSee('55 parcels');

    $this->actingAs($ops)->get(onPanel('admin', 'delivery/assignments?payment=bitcoin'))->assertRedirect();
});

it('keeps the typed brand name after a refused add (BT-ADM-008)', function () {
    $admin = adminAccount();
    Brand::query()->create(['name' => 'Taken', 'slug' => 'taken', 'is_active' => true]);
    $brands = onPanel('admin', 'catalog/brands');

    $this->actingAs($admin)->from($brands)->post($brands, ['name' => 'Fresh Brand', 'name_ar' => 'علامة', 'slug' => 'taken'])
        ->assertRedirect($brands)->assertSessionHasErrors('slug');
    $this->actingAs($admin)->get($brands)->assertOk()->assertSee('value="Fresh Brand"', false)->assertSee('value="علامة"', false);
});

it('names fields in words in validation messages, in English and Arabic (BT-ADM-005)', function () {
    $admin = adminAccount();
    $categories = onPanel('admin', 'catalog/categories');

    $this->actingAs($admin)->from($categories.'/create')->post($categories, [])->assertSessionHasErrors([
        'name_en' => 'The name (English) field is required.',
        'name_ar' => 'The name (Arabic) field is required.',
    ]);

    $admin->forceFill(['preferred_locale' => 'ar'])->save();
    $this->actingAs($admin)->from($categories.'/create')->post($categories, [])->assertSessionHasErrors([
        'name_en' => 'حقل الاسم (بالإنجليزية) مطلوب.',
    ]);
    $this->actingAs($admin)->from($categories.'/create')->followingRedirects()->post($categories, [])->assertOk()
        ->assertSee('حقل الاسم (بالإنجليزية) مطلوب.')->assertDontSee('name en');
});

it('builds friendly names for dotted, row and id keys when no line is defined', function (string $locale, array $rules, string $expected) {
    app()->setLocale($locale);
    $validator = Validator::make([], $rules);

    expect($validator->errors()->first())->toBe($expected);
})->with([
    'values.street en' => ['en', ['values.street' => 'required'], 'The street field is required.'],
    'values.street ar' => ['ar', ['values.street' => 'required'], 'حقل الشارع مطلوب.'],
    'category_id en' => ['en', ['category_id' => 'required'], 'The category field is required.'],
    'body_ar ar' => ['ar', ['body_ar' => 'required'], 'حقل المحتوى (بالعربية) مطلوب.'],
    'name.en en' => ['en', ['name.en' => 'required'], 'The name (English) field is required.'],
]);

it('numbers rows of list fields', function () {
    $validator = Validator::make(['lines' => [['quantity' => 1], ['quantity' => null]]], ['lines.*.quantity' => ['required']]);

    expect($validator->errors()->first('lines.1.quantity'))->toBe('The quantity (row 2) field is required.');
});

it('keeps a refused warranty plan form\'s errors and input to that form (BT-ADM-006)', function () {
    $admin = adminAccount();
    $category = Category::query()->create(['name_en' => 'Phones', 'name_ar' => 'هواتف', 'slug' => 'phones', 'is_active' => true]);
    $plan = WarrantyPlan::query()->create(['category_id' => $category->id, 'name_en' => 'One year', 'name_ar' => 'سنة', 'duration_months' => 12, 'price' => '20', 'sort_order' => 0, 'is_active' => true]);
    $page = onPanel('admin', 'catalog/warranty-plans');

    $html = $this->actingAs($admin)->from($page)->followingRedirects()->post($page, ['_scope' => 'new-plan', 'name_en' => 'Typed new', 'name_ar' => '', 'duration_months' => 6, 'price' => '5'])
        ->assertOk()->getContent();

    expect(substr_count($html, 'id="name_ar-error"'))->toBe(1)
        ->and(substr_count($html, 'id="name_ar-'.$plan->id.'-error"'))->toBe(0)
        ->and($html)->toContain('value="One year"')->toContain('value="Typed new"')
        ->and($html)->toContain('The category field is required.');

    $html = $this->actingAs($admin)->from($page)->followingRedirects()->put($page.'/'.$plan->id, ['_scope' => 'plan-'.$plan->id, 'category_id' => $category->id, 'name_en' => 'Edited', 'name_ar' => '', 'duration_months' => 12, 'price' => '20'])
        ->assertOk()->getContent();
    expect($html)->toContain('id="name_ar-'.$plan->id.'-error"')->not->toContain('id="name_ar-error"')->toContain('value="Edited"')
        ->and(substr_count($html, 'value="Edited"'))->toBe(1);
});

it('shows the staff member\'s name instead of "Admin #id" in histories (BT-ADM-007)', function () {
    $staff = User::factory()->create(['name' => 'Layla Haddad']);
    $transition = new StateTransition(['from_status' => 'pending', 'to_status' => 'approved', 'actor_type' => 'admin', 'actor_user_id' => $staff->id]);
    $transition->created_at = now();

    $html = view('panels.admin._partials.transitions', ['transitions' => collect([$transition])])->render();

    expect($html)->toContain('Admin · Layla Haddad')->not->toContain('#'.$staff->id);
    expect(app(UserNames::class)->name(999_999))->toBe('User #999999');
});

it('keeps the typed brand name after a refused edit too (BT-ADM-008)', function () {
    $admin = adminAccount();
    Brand::query()->create(['name' => 'Taken', 'slug' => 'taken', 'is_active' => true]);
    $brand = Brand::query()->create(['name' => 'Mine', 'slug' => 'mine', 'is_active' => true]);
    $edit = onPanel('admin', "catalog/brands/{$brand->id}/edit");

    $this->actingAs($admin)->from($edit)->put(onPanel('admin', "catalog/brands/{$brand->id}"), ['name' => 'Renamed', 'slug' => 'taken'])->assertRedirect($edit);
    $this->actingAs($admin)->get($edit)->assertSee('value="Renamed"', false);
});

it('words staff invitation subjects per team (BT-ADM-009)', function () {
    $super = adminAccount();
    $this->seed(AdminRoleSeeder::class);
    Notification::fake();

    $this->actingAs($super)->post(onPanel('admin', 'staff/invitations'), ['email' => 'new.staff@nawy.test', 'name' => 'New Staff', 'role' => 'operations'])->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification): bool {
        expect($notification->toMail(new AnonymousNotifiable)->subject)->toBe('You are invited to join the Nawy admin team');
        app()->setLocale('ar');
        expect($notification->subject())->toBe('أنت مدعو للانضمام إلى فريق إدارة Nawy');
        app()->setLocale('en');

        return true;
    });
    expect((new TeamInvitationNotification('Swift Couriers', 'driver', 'https://x.test', 7))->subject())->toBe('You are invited to join Swift Couriers on Nawy')
        ->and((new TeamInvitationNotification('Nawy', 'driver', 'https://x.test', 7, 'delivery'))->subject())->toBe('You are invited to join the Nawy delivery team');
});

it('keeps a custom role name as typed (BT-ADM-010)', function () {
    $super = adminAccount();
    $this->seed(AdminRoleSeeder::class);

    $this->actingAs($super)->post(onPanel('admin', 'roles'), ['name' => 'QA narrow 9okp9wr', 'permissions' => ['orders.view']])
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Role QA narrow 9okp9wr created.');
    $role = Role::query()->where('name', 'qa_narrow_9okp9wr')->sole();
    expect($role->getAttribute('display_name'))->toBe('QA narrow 9okp9wr');

    $this->actingAs($super)->get(onPanel('admin', 'roles'))->assertSee('QA narrow 9okp9wr')->assertDontSee('Qa narrow');
    $this->actingAs($super)->get(onPanel('admin', 'staff'))->assertSee('<option value="qa_narrow_9okp9wr" >QA narrow 9okp9wr</option>', false);

    $this->actingAs($super)->from(onPanel('admin', 'roles'))->post(onPanel('admin', 'roles'), ['name' => 'qa NARROW 9okp9wr'])
        ->assertSessionHasErrors(['name' => 'A role with this name already exists.']);
});

it('seeds every holiday with an Arabic name so it can be saved again (BT-ADM-011)', function () {
    $this->seed(CountryCalendarSeeder::class);
    $admin = adminAccount();

    expect(CountryCalendar::query()->where(fn ($query) => $query->whereNull('name_ar')->orWhere('name_ar', ''))->count())->toBe(0);

    $holiday = CountryCalendar::query()->where('country_id', $this->country->id)->where('name_en', 'Commemoration Day and National Day')->sole();
    $this->actingAs($admin)->put(onPanel('admin', "settings/holidays/{$holiday->id}"), [
        'name_en' => $holiday->name_en, 'name_ar' => $holiday->name_ar, 'starts_on' => $holiday->starts_on->toDateString(), 'ends_on' => $holiday->ends_on->toDateString(), 'is_confirmed' => 1,
    ])->assertSessionHasNoErrors();

    // The data migration fills rows seeded before the names existed.
    $holiday->forceFill(['name_ar' => null])->save();
    (include database_path('migrations/2026_12_08_510100_fill_arabic_names_of_seeded_holidays.php'))->up();
    expect($holiday->refresh()->name_ar)->toBe('يوم الشهيد واليوم الوطني');
});

it('lets panel pages load uploads from the app origin outside production only', function () {
    config(['app.url' => 'http://localhost:8000', 'filesystems.disks.public.url' => 'http://localhost:8000/storage']);
    $csp = fn () => $this->get(onPanel('admin', 'login'))->headers->get('Content-Security-Policy');

    expect($csp())->toContain("img-src 'self' data: blob: https: http://localhost:8000;")
        ->toContain("media-src 'self' blob: https: http://localhost:8000;");

    app()->detectEnvironment(fn () => 'production');
    expect($csp())->toContain("img-src 'self' data: blob: https:;")->not->toContain('http://localhost');
});
