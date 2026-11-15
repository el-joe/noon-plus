<?php

use App\Domains\Content\Models\HelpArticle;
use App\Domains\Content\Models\HelpCollection;
use App\Domains\Content\Models\StaticPage;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Customers\Models\CustomerNotification;
use App\Domains\GiftCards\Enums\GiftCardStatus;
use App\Domains\GiftCards\Models\GiftCard;
use App\Domains\Identity\Models\EmailLoginCode;
use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use App\Domains\Membership\Models\MembershipPlan;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Returns\Support\ReturnRequests;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Notification::fake();
    Storage::fake('public');
});

describe('profile (G-W29)', function () {
    it('reads and updates name, language and marketing preferences', function () {
        [$customer, $token] = customerWithToken();

        $this->withToken($token)->getJson('/api/v1/uae-en/me')->assertOk()
            ->assertJsonPath('data.email', $customer->email)
            ->assertJsonPath('data.avatar_url', null)
            ->assertJsonPath('data.marketing', ['email' => false, 'sms' => false, 'push' => false, 'whatsapp' => false]);

        $this->withToken($token)->patchJson('/api/v1/uae-en/me', ['name' => 'Sara Ahmed', 'locale' => 'ar', 'marketing' => ['email' => true, 'push' => true]])->assertOk()
            ->assertJsonPath('data.name', 'Sara Ahmed')
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.marketing.email', true)
            ->assertJsonPath('data.marketing.sms', false);
        $this->withToken($token)->patchJson('/api/v1/uae-en/me', ['marketing' => ['email' => false]])->assertOk()
            ->assertJsonPath('data.marketing.email', false)->assertJsonPath('data.marketing.push', true);

        $this->withToken($token)->patchJson('/api/v1/uae-en/me', ['locale' => 'fr'])->assertUnprocessable();
        $this->withToken($token)->patchJson('/api/v1/uae-en/me', ['marketing' => ['fax' => true]])->assertUnprocessable();
        $this->withToken($token)->patchJson('/api/v1/uae-en/me', ['name' => 'S'])->assertUnprocessable();
        app('auth')->forgetGuards();
        $this->flushHeaders()->patchJson('/api/v1/uae-en/me', ['name' => 'Guest'])->assertUnauthorized();
    });

    it('uploads, replaces and removes the avatar with a public URL', function () {
        [$customer, $token] = customerWithToken();

        $first = $this->withToken($token)->post('/api/v1/uae-en/me/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])->assertOk()->json('data.avatar_url');
        $path = $customer->refresh()->avatar_path;
        expect($first)->toContain('/storage/avatars/'.$customer->id.'/');
        Storage::disk('public')->assertExists($path);

        $this->withToken($token)->post('/api/v1/uae-en/me/avatar', ['avatar' => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->withToken($token)->post('/api/v1/uae-en/me/avatar', ['avatar' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->withToken($token)->deleteJson('/api/v1/uae-en/me/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        expect($customer->refresh()->avatar_path)->toBeNull();
    });

    it('changes the email only after the code sent to the new address is checked', function () {
        [$customer, $token] = customerWithToken();
        $old = $customer->email;
        User::factory()->create(['email' => 'taken@example.com']);

        $this->withToken($token)->postJson('/api/v1/uae-en/me/email', ['email' => 'taken@example.com'])->assertUnprocessable()->assertJsonPath('error.code', 'email_taken');
        $this->withToken($token)->postJson('/api/v1/uae-en/me/email', ['email' => $old])->assertUnprocessable()->assertJsonPath('error.code', 'email_unchanged');

        $this->withToken($token)->postJson('/api/v1/uae-en/me/email', ['email' => 'New@Example.com'])->assertStatus(202)->assertJsonPath('data.email', 'new@example.com');
        $code = null;
        Notification::assertSentOnDemand(EmailLoginCodeNotification::class, function (EmailLoginCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$code): bool {
            $code = $notification->code;

            return $notifiable->routes['mail'] === 'new@example.com' && $notification->purpose === 'email_change';
        });
        expect($customer->refresh()->email)->toBe($old);

        $this->withToken($token)->postJson('/api/v1/uae-en/me/email/verify', ['email' => 'new@example.com', 'code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable()->assertJsonPath('error.code', 'code_invalid');
        expect($customer->refresh()->email)->toBe($old);

        $this->withToken($token)->postJson('/api/v1/uae-en/me/email/verify', ['email' => 'new@example.com', 'code' => $code])->assertOk()->assertJsonPath('data.email', 'new@example.com');
        expect($customer->refresh()->email)->toBe('new@example.com')->and($customer->email_verified_at)->not->toBeNull();
        $this->withToken($token)->postJson('/api/v1/uae-en/me/email/verify', ['email' => 'new@example.com', 'code' => $code])->assertUnprocessable();
    });

    it('refuses the switch when the address was taken in the meantime', function () {
        [$customer, $token] = customerWithToken();
        EmailLoginCode::query()->create(['email' => 'late@example.com', 'purpose' => 'email_change', 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5)]);
        User::factory()->create(['email' => 'late@example.com']);

        $this->withToken($token)->postJson('/api/v1/uae-en/me/email/verify', ['email' => 'late@example.com', 'code' => '123456'])->assertUnprocessable()->assertJsonPath('error.code', 'email_taken');
        expect($customer->refresh()->email)->not->toBe('late@example.com');
    });
});

describe('notifications (G-W29)', function () {
    it('fills the centre from order and return moves, counts unread and marks read', function () {
        [$customer, $token] = customerWithToken();
        $subOrder = subOrderFor(Vendor::factory()->create());
        $subOrder->order->forceFill(['customer_user_id' => $customer->id])->save();

        app(SubOrderStateMachine::class)->transition($subOrder->refresh(), SubOrderStatus::Confirmed, ActorType::Vendor, 9);
        app(SubOrderStateMachine::class)->transition($subOrder->refresh(), SubOrderStatus::Processing, ActorType::Vendor, 9);

        $this->withToken($token)->getJson('/api/v1/uae-en/me/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 1);
        $list = $this->withToken($token)->getJson('/api/v1/uae-en/me/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'order.confirmed')
            ->assertJsonPath('data.0.link', '/account/orders/'.$subOrder->order->number)
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('meta.unread', 1);

        $other = CustomerNotification::query()->create(['user_id' => User::factory()->create()->id, 'type' => 'order.confirmed', 'title' => 'Not yours']);
        $this->withToken($token)->postJson("/api/v1/uae-en/me/notifications/{$other->id}/read")->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/uae-en/me/notifications/'.$list->json('data.0.id').'/read')->assertOk()->assertJsonPath('data.read', true)->assertJsonPath('data.unread', 0);

        $return = ReturnRequest::query()->forceCreate([
            'number' => 'RT-AE-0000001', 'order_id' => $subOrder->order_id, 'sub_order_id' => $subOrder->id, 'vendor_id' => $subOrder->vendor_id, 'customer_user_id' => $customer->id,
            'status' => 'requested', 'reason' => 'defective', 'method' => 'pickup', 'refund_destination' => 'wallet',
        ]);
        app(ReturnRequests::class)->approve($return, ActorType::Vendor, 9);
        $this->withToken($token)->getJson('/api/v1/uae-en/me/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'return.approved');

        $this->withToken($token)->postJson('/api/v1/uae-en/me/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 1)->assertJsonPath('data.unread', 0);
        expect($other->refresh()->read_at)->toBeNull();

        $this->actAsCountry('ksa');
        $this->withToken($token)->getJson('/api/v1/ksa-en/me/notifications')->assertOk()->assertJsonCount(0, 'data');
    });

    it('does not echo a move the customer made themselves', function () {
        [$customer, $token] = customerWithToken();
        $subOrder = subOrderFor(Vendor::factory()->create());
        $subOrder->order->forceFill(['customer_user_id' => $customer->id])->save();

        app(SubOrderStateMachine::class)->transition($subOrder->refresh(), SubOrderStatus::Cancelled, ActorType::Customer, $customer->id, 'Changed my mind');

        expect(CustomerNotification::query()->where('user_id', $customer->id)->count())->toBe(0);
    });
});

describe('signed contracts (G-W29)', function () {
    it('lists the customer\'s own signatures, current and superseded', function () {
        [$customer, $token] = customerWithToken();
        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::CustomerPurchase, 'name' => 'Customer purchase terms']);
        $publisher = app(ContractPublisher::class);
        $v1 = $publisher->publish($publisher->draft($template, ['body_en' => 'Terms v1', 'body_ar' => 'الشروط']), 1);
        app(ContractSigner::class)->sign($v1, SignerType::Customer, $customer->id, $customer->id, 'Sara Ahmed', SignatureMethod::Click);
        app(ContractSigner::class)->sign($v1, SignerType::Customer, 999, 999, 'Someone else', SignatureMethod::Click);

        $this->withToken($token)->getJson('/api/v1/uae-en/me/contracts/signed')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Customer purchase terms')
            ->assertJsonPath('data.0.kind', 'customer_purchase')
            ->assertJsonPath('data.0.version_id', $v1->id)
            ->assertJsonPath('data.0.full_name', 'Sara Ahmed')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('meta.total', 1);
    });
});

describe('help and static pages (G-W25)', function () {
    it('counts one helpful vote per voter and lets the voter change it', function () {
        $collection = HelpCollection::query()->create(['hub' => 'help_center', 'slug' => 'orders', 'title_en' => 'Orders', 'title_ar' => 'الطلبات', 'audience' => 'customer']);
        HelpArticle::query()->create(['collection_id' => $collection->id, 'hub' => 'help_center', 'slug' => 'track', 'title_en' => 'Track', 'title_ar' => 'تتبع', 'body_en' => 'B', 'body_ar' => 'ب', 'is_published' => true]);

        $this->withHeader('X-Visitor-Token', 'v1')->postJson('/api/v1/uae-en/help/help_center/articles/track/votes', ['helpful' => true])->assertOk()->assertJsonPath('data.helpful', ['yes' => 1, 'no' => 0]);
        $this->withHeader('X-Visitor-Token', 'v1')->postJson('/api/v1/uae-en/help/help_center/articles/track/votes', ['helpful' => true])->assertOk()->assertJsonPath('data.helpful', ['yes' => 1, 'no' => 0]);
        $this->withHeader('X-Visitor-Token', 'v1')->postJson('/api/v1/uae-en/help/help_center/articles/track/votes', ['helpful' => false])->assertOk()->assertJsonPath('data.helpful', ['yes' => 0, 'no' => 1]);
        $this->withHeader('X-Visitor-Token', 'v2')->postJson('/api/v1/uae-en/help/help_center/articles/track/votes', ['helpful' => true])->assertOk()->assertJsonPath('data.helpful', ['yes' => 1, 'no' => 1]);
        $this->postJson('/api/v1/uae-en/help/help_center/articles/track/votes', [])->assertUnprocessable();
        $this->postJson('/api/v1/uae-en/help/knowledge_hub/articles/track/votes', ['helpful' => true])->assertNotFound();

        $this->getJson('/api/v1/uae-en/help/help_center/articles/track')->assertOk()
            ->assertJsonPath('data.collection', 'orders')
            ->assertJsonPath('data.collection_detail.title', 'Orders')
            ->assertJsonPath('data.helpful', ['yes' => 1, 'no' => 1]);
    });

    it('gives static pages an SEO title and description, falling back to the title and body', function () {
        StaticPage::query()->create(['country_id' => null, 'slug' => 'about', 'audience' => 'both', 'title_en' => 'About Nawy', 'title_ar' => 'عن ناوي', 'body_en' => '<p>Nawy is a marketplace   for the Gulf.</p>', 'body_ar' => 'ب', 'is_published' => true]);
        StaticPage::query()->create(['country_id' => null, 'slug' => 'terms', 'audience' => 'both', 'title_en' => 'Terms', 'title_ar' => 'الشروط', 'body_en' => 'T', 'body_ar' => 'ت', 'seo_title_en' => 'Nawy terms of use', 'seo_description_en' => 'The rules.', 'is_published' => true]);

        $this->getJson('/api/v1/uae-en/static-pages/about')->assertOk()->assertJsonPath('data.seo', ['title' => 'About Nawy', 'description' => 'Nawy is a marketplace for the Gulf.']);
        $this->getJson('/api/v1/uae-en/static-pages/terms')->assertOk()->assertJsonPath('data.seo', ['title' => 'Nawy terms of use', 'description' => 'The rules.']);
    });
});

describe('non-order payments (G-W31) and shopping extras (G-W30)', function () {
    it('verifies a gift-card payment on return, like an order payment, for its buyer only', function () {
        [$buyer, $token] = customerWithToken();
        $this->getJson('/api/v1/uae-en/gift-cards/designs')->assertOk()->assertJsonPath('data.0.key', 'classic');

        $this->withToken($token)->postJson('/api/v1/uae-en/gift-cards', ['amount' => '150.00', 'payment_method' => 'card', 'recipient_email' => 'friend@example.com', 'design' => 'balloons'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/uae-en/gift-cards', ['amount' => '150.00', 'payment_method' => 'card', 'recipient_email' => 'friend@example.com', 'design' => 'eid'])
            ->assertCreated()->assertJsonPath('data.design', 'eid');
        $payment = Payment::query()->where('payable_type', GiftCard::PAYABLE)->sole();

        $this->get("/api/v1/uae-en/payments/{$payment->reference}/return")->assertRedirect()
            ->assertRedirectContains('/uae-en/checkout/payment?payment='.$payment->reference.'&kind=gift_card');

        $this->withToken($token)->getJson("/api/v1/uae-en/payments/{$payment->reference}")->assertOk()
            ->assertJsonPath('data.purpose', 'gift_card')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.subject.status', 'pending_payment');

        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        $this->withToken($token)->postJson("/api/v1/uae-en/payments/{$payment->reference}/verify")->assertOk()
            ->assertJsonPath('data.payment.status', 'captured')
            ->assertJsonPath('data.subject.status', 'active')
            ->assertJsonPath('data.subject.amount', ['amount' => '150.00', 'currency' => 'AED']);
        expect(GiftCard::query()->sole()->status)->toBe(GiftCardStatus::Active);

        app('auth')->forgetGuards();
        [, $other] = customerWithToken();
        $this->withToken($other)->postJson("/api/v1/uae-en/payments/{$payment->reference}/verify")->assertNotFound();
        app('auth')->forgetGuards();
        $this->flushHeaders()->getJson("/api/v1/uae-en/payments/{$payment->reference}")->assertUnauthorized();
    });

    it('shows membership plans to guests', function () {
        MembershipPlan::query()->create(['code' => 'one-monthly', 'name_en' => 'Nawy One', 'name_ar' => 'ناوي ون', 'interval' => 'monthly', 'price' => '25.00', 'free_shipping' => true]);

        $this->getJson('/api/v1/uae-en/membership/plans')->assertOk()->assertJsonPath('data.0.price', ['amount' => '25.00', 'currency' => 'AED']);
        $this->getJson('/api/v1/uae-en/membership')->assertUnauthorized();
    });
});
