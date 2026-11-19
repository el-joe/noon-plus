<?php

use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Customers\Events\CustomerNotificationCreated;
use App\Domains\Customers\Support\CustomerNotifications;
use App\Domains\LiveMedia\Contracts\LiveMediaProvider;
use App\Domains\LiveMedia\Events\LiveStreamUpdated;
use App\Domains\LiveMedia\Support\FakeLiveMediaProvider;
use App\Domains\LiveMedia\Support\LiveKitProvider;
use App\Domains\LiveMedia\Support\LiveStreams;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Support\InvoicePdf;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
| D16: backend side of the approved packages — invoice PDF (dompdf), referral
| QR codes (chillerlan/php-qrcode), Reverb broadcasts and channel auth for
| token clients (laravel-echo on the website), and the LiveKit viewer flag
| (livekit-client on the website).
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
});

/**
 * Point broadcasting at Reverb with test keys and register the channels on it.
 */
function useReverbForBroadcasts(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    app(BroadcastManager::class)->purge();
    require base_path('routes/channels.php');
}

describe('invoice PDF', function () {
    it('downloads the paid order invoice as a PDF for its customer only', function () {
        [$order, , $token] = placeOrderVia('cod');

        $this->withToken($token)->getJson("/api/v1/uae-en/orders/{$order->number}/invoice/pdf")
            ->assertStatus(409)->assertJsonPath('error.code', 'invoice_not_ready');
        moveSubOrderTo($order->subOrders->first(), SubOrderStatus::Delivered);

        $response = $this->withToken($token)->get("/api/v1/uae-en/orders/{$order->number}/invoice/pdf")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="invoice-'.$order->number.'.pdf"');
        expect(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');

        [, $other] = customerWithToken();
        app('auth')->forgetGuards();
        $this->withToken($other)->getJson("/api/v1/uae-en/orders/{$order->number}/invoice/pdf")->assertNotFound();
        app('auth')->forgetGuards();
        $this->withoutToken()->getJson("/api/v1/uae-en/orders/{$order->number}/invoice/pdf")->assertUnauthorized();
    });

    it('renders 3-decimal totals and an Arabic right-to-left invoice', function () {
        $this->country = $this->actAsCountry('omn');
        [$order, $customer] = placeOrderVia('cod', '12.345', 'omn-en');
        $order->refresh();

        $html = app(InvoicePdf::class)->orderView($order, $this->country, $customer)->render();
        expect($html)->toContain('OMR 12.345')->toContain($order->number)->toContain('Tax invoice');

        app()->setLocale('ar');
        $arabic = app(InvoicePdf::class)->orderView($order, $this->country, $customer)->render();
        expect($arabic)->toContain('dir="rtl"')->toContain('فاتورة ضريبية')->toContain('text-align: right');
        expect(substr(app(InvoicePdf::class)->forOrder($order, $this->country, $customer), 0, 5))->toBe('%PDF-');
    });

    it('gives the signed app link a PDF variant', function () {
        [$order, , $token] = placeOrderVia('cod');
        moveSubOrderTo($order->subOrders->first(), SubOrderStatus::Delivered);

        $url = $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$order->number}/invoice-link")->assertOk()->json('data.pdf_url');
        app('auth')->forgetGuards();
        $this->flushHeaders();

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(str_replace('format=pdf', 'format=html', $url))->assertForbidden();
    });

    it('lets the seller download its own shipment invoice and the admin the whole order', function () {
        [$seller, $vendor] = sellerAccount('uae');
        [$order] = placeOrderVia('cod', vendor: $vendor);
        $subOrder = $order->subOrders->first();

        $this->actingAs($seller)->get(onPanel('partner', "orders/{$subOrder->id}/invoice.pdf"))->assertStatus(409);
        moveSubOrderTo($subOrder, SubOrderStatus::Delivered);

        $this->actingAs($seller)->get(onPanel('partner', "orders/{$subOrder->id}"))->assertOk()->assertSee('Download invoice (PDF)');
        $this->actingAs($seller)->get(onPanel('partner', "orders/{$subOrder->id}/invoice.pdf"))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="invoice-'.$subOrder->number.'.pdf"');
        expect(app(InvoicePdf::class)->subOrderView($subOrder->refresh(), $this->country)->render())->toContain('AED 100.00')->toContain($subOrder->number);

        [$otherSeller] = sellerAccount('uae');
        $this->actingAs($otherSeller)->get(onPanel('partner', "orders/{$subOrder->id}/invoice.pdf"))->assertNotFound();

        $admin = adminAccount();
        $this->actingAs($admin)->get(onPanel('admin', "orders/{$order->id}"))->assertOk()->assertSee('Download invoice (PDF)');
        $this->actingAs($admin)->get(onPanel('admin', "orders/{$order->id}/invoice.pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    });
});

describe('referral QR codes', function () {
    beforeEach(function () {
        marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'fee_percent' => '10']);
        [$this->user, $this->marketer] = marketerAccount(['affiliate']);
        $this->user->memberships()->update(['country_id' => app(CountryContext::class)->current()->id]);
        $this->invitation = app(Invitations::class)->joinOpen(liveCampaign(Vendor::factory()->create()), $this->marketer);
    });

    it('draws the QR of a referral link in Creator Studio as SVG or PNG', function () {
        $this->actingAs($this->user)->get(onPanel('marketer', 'links'))->assertOk()
            ->assertSee(route('marketer.links.qr', ['invitation' => $this->invitation->id, 'format' => 'png']), false);

        $svg = $this->actingAs($this->user)->get(onPanel('marketer', "links/{$this->invitation->id}/qr"))->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('Content-Disposition', 'attachment; filename="referral-'.$this->invitation->referral_code.'.svg"');
        expect((string) $svg->getContent())->toContain('<svg');

        $png = $this->actingAs($this->user)->get(onPanel('marketer', "links/{$this->invitation->id}/qr?format=png&inline=1"))->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="referral-'.$this->invitation->referral_code.'.png"');
        expect(substr((string) $png->getContent(), 1, 3))->toBe('PNG');

        [$otherUser, $other] = marketerAccount(['affiliate']);
        $otherUser->memberships()->update(['country_id' => app(CountryContext::class)->current()->id]);
        $this->actingAs($otherUser)->get(onPanel('marketer', "links/{$this->invitation->id}/qr"))->assertNotFound();
    });

    it('serves the QR to the marketer app and lists its URL with each link', function () {
        $token = opsLoginForQr($this->user);

        $this->withToken($token)->getJson('/api/v1/uae-en/ops/marketer/links')->assertOk()
            ->assertJsonPath('data.links.0.qr_url', fn (string $url): bool => str_ends_with($url, "/api/v1/uae-en/ops/marketer/links/{$this->invitation->id}/qr"));
        $this->withToken($token)->get("/api/v1/uae-en/ops/marketer/links/{$this->invitation->id}/qr?format=png")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->withToken($token)->getJson("/api/v1/uae-en/ops/marketer/links/{$this->invitation->id}/qr?format=gif")->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/uae-en/ops/marketer/links/999999/qr')->assertNotFound();
    });
});

/**
 * A marketer app token (ops sign-in, role `marketer`).
 */
function opsLoginForQr(User $user): string
{
    app('auth')->forgetGuards();
    $token = test()->postJson('/api/v1/uae-en/ops/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel', 'role' => 'marketer'])
        ->assertOk()->json('data.token');
    app('auth')->forgetGuards();

    return $token;
}

describe('realtime (Reverb)', function () {
    it('authorises a token client on its own private channel only', function () {
        useReverbForBroadcasts();
        [$user, $token] = customerWithToken();

        $this->withToken($token)->postJson('/api/v1/uae-en/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$user->id}"])
            ->assertOk()->assertJsonPath('auth', fn (string $auth): bool => str_starts_with($auth, 'test-key:'));
        $this->withToken($token)->postJson('/api/v1/uae-en/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.($user->id + 1)])
            ->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/uae-en/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-country.uae.ops'])
            ->assertForbidden();
        app('auth')->forgetGuards();
        $this->withoutToken()->postJson('/api/v1/uae-en/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$user->id}"])
            ->assertUnauthorized();
    });

    it('broadcasts visible live comments, likes and customer notifications', function () {
        useReverbForBroadcasts();
        Event::fake([LiveStreamUpdated::class, CustomerNotificationCreated::class]);
        app()->instance(LiveMediaProvider::class, new FakeLiveMediaProvider);
        $stream = app(LiveStreams::class)->createLive(['title_en' => 'Eid live', 'title_ar' => 'بث العيد'], 1);
        app(LiveStreams::class)->goLive($stream, 1);
        [$user, $token] = customerWithToken();
        $base = "/api/v1/uae-en/live-streams/{$stream->slug}";

        $id = $this->withToken($token)->postJson("{$base}/comments", ['body' => 'Love the colours'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("{$base}/comments", ['body' => 'cheap at https://spam.example'])->assertCreated()->assertJsonPath('data.status', 'held');
        $this->postJson("{$base}/likes")->assertOk();

        Event::assertDispatchedTimes(LiveStreamUpdated::class, 2);
        Event::assertDispatched(LiveStreamUpdated::class, fn (LiveStreamUpdated $event): bool => $event->name === 'comment.posted' && $event->payload['id'] === $id
            && $event->broadcastOn()[0]->name === "live-stream.{$stream->slug}");
        Event::assertDispatched(LiveStreamUpdated::class, fn (LiveStreamUpdated $event): bool => $event->name === 'likes.updated' && $event->payload === ['likes' => 1]);

        $notification = app(CustomerNotifications::class)->notify($user->id, 'order.status', 'Shipped', link: '/account/orders/NW-1', data: ['order' => 'NW-1']);
        app(CustomerNotifications::class)->notify($user->id, 'panel.thing', 'Seller bell', audience: 'panel:partner');
        Event::assertDispatchedTimes(CustomerNotificationCreated::class, 1);
        Event::assertDispatched(CustomerNotificationCreated::class, fn (CustomerNotificationCreated $event): bool => $event->broadcastOn()[0]->name === "private-App.Models.User.{$user->id}"
            && $event->broadcastWith() === ['id' => $notification->id, 'type' => 'order.status', 'order' => 'NW-1']);
    });

    it('does not broadcast while broadcasting is off', function () {
        Event::fake([LiveStreamUpdated::class]);
        app()->instance(LiveMediaProvider::class, new FakeLiveMediaProvider);
        $stream = app(LiveStreams::class)->createLive(['title_en' => 'Eid live', 'title_ar' => 'بث العيد'], 1);
        app(LiveStreams::class)->goLive($stream, 1);

        $this->postJson("/api/v1/uae-en/live-streams/{$stream->slug}/likes")->assertOk();
        Event::assertNotDispatched(LiveStreamUpdated::class);
    });

    it('never fails the request when Reverb is unreachable', function () {
        app()->instance(LiveMediaProvider::class, new FakeLiveMediaProvider);
        $stream = app(LiveStreams::class)->createLive(['title_en' => 'Eid live', 'title_ar' => 'بث العيد'], 1);
        app(LiveStreams::class)->goLive($stream, 1);
        useReverbForBroadcasts();
        config(['broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 9, 'scheme' => 'http', 'useTLS' => false]]);
        app(BroadcastManager::class)->purge();
        Exceptions::fake();

        $this->postJson("/api/v1/uae-en/live-streams/{$stream->slug}/likes")->assertOk()->assertJsonPath('data.likes', 1);
        Exceptions::assertReported(BroadcastException::class);
    });
});

describe('LiveKit viewer flag', function () {
    it('tells the website when a browser can join the room with WebRTC', function () {
        app()->instance(LiveMediaProvider::class, new FakeLiveMediaProvider);
        $stream = app(LiveStreams::class)->createLive(['title_en' => 'Eid live', 'title_ar' => 'بث العيد'], 1);
        app(LiveStreams::class)->goLive($stream, 1);
        expect(app(LiveStreams::class)->playback($stream->refresh(), 'guest')['webrtc'])->toBeFalse();

        app()->instance(LiveMediaProvider::class, new LiveKitProvider('https://live.example.test', 'key', 'secret-secret-secret-secret-secret', null));
        $this->getJson("/api/v1/uae-en/live-streams/{$stream->slug}")->assertOk()
            ->assertJsonPath('data.playback.webrtc', true)
            ->assertJsonPath('data.playback.url', 'wss://live.example.test')
            ->assertJsonPath('data.playback.token', fn (string $token): bool => substr_count($token, '.') === 2);
    });
});
