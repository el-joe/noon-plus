<?php

use App\Domains\Customers\Models\CustomerNotification;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Settings\SettingsService;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Enums\TicketRequesterType;
use App\Domains\Support\Enums\TicketStatus;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Support\SupportTickets;
use App\Domains\Support\Support\TicketRequester;
use App\Models\User;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
| QC-007: support tickets (SRS §15, lifecycle catalogue §9.3).
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('local');
    Notification::fake();
});

/**
 * A customer ticket opened through the service.
 */
function supportTicketFor(User $customer, TicketPriority $priority = TicketPriority::Normal): SupportTicket
{
    return app(SupportTickets::class)->open(TicketRequester::customer($customer->id), TicketCategory::Order, 'Where is my parcel?', 'It has been a week since I ordered.', $priority);
}

function supportOpsToken(User $user): string
{
    app('auth')->forgetGuards();
    $token = test()->postJson('/api/v1/uae-en/ops/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel', 'role' => 'vendor'])
        ->assertOk()->json('data.token');
    app('auth')->forgetGuards();

    return $token;
}

it('QC-007: a customer opens a ticket about their order with an attachment, sees it in a paged list and nobody else can', function () {
    [$order, $customer] = placeOrderVia('card');
    $token = $customer->createToken('test')->plainTextToken;
    $this->travelTo(now()->startOfMinute());

    $this->withToken($token)->getJson('/api/v1/uae-en/support/categories')->assertOk()
        ->assertJsonPath('data.categories.0.value', 'order')
        ->assertJsonMissing(['value' => 'selling']);

    $created = $this->withToken($token)->post('/api/v1/uae-en/support/tickets', [
        'category' => 'order',
        'subject' => 'Parcel is late',
        'message' => 'My parcel has not arrived yet, please check.',
        'order' => $order->number,
        'attachments' => [UploadedFile::fake()->image('receipt.png')],
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.order', $order->number)
        ->assertJsonPath('data.priority', 'normal')
        ->assertJsonPath('data.messages.0.from', 'requester')
        ->assertJsonPath('data.history.0.to', 'open')
        ->assertJsonPath('data.reply_expected_by', now()->addHours(12)->toIso8601String());

    $number = $created->json('data.number');
    expect($number)->toStartWith('TK-AE-');

    $ticket = SupportTicket::query()->where('number', $number)->sole();
    expect($ticket->resolution_due_at->equalTo(now()->addHours(72)))->toBeTrue()
        ->and(StateTransition::query()->where('subject_type', 'support_ticket')->where('subject_id', $ticket->id)->value('actor_type'))->toBe('customer');

    // The attachment is on the private disk with the detected extension; its link is signed and expires.
    $file = $ticket->messages()->sole()->attachments[0];
    Storage::disk('local')->assertExists($file['path']);
    expect($file['path'])->toEndWith('.png');
    $url = $created->json('data.messages.0.attachments.0.url');
    $this->get($url)->assertOk();
    $this->get(strtok($url, '?'))->assertForbidden();

    $this->withToken($token)->getJson('/api/v1/uae-en/support/tickets?status=open')->assertOk()
        ->assertJsonPath('data.0.number', $number)
        ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 20)->assertJsonPath('meta.last_page', 1);

    [, $otherToken] = customerWithToken();
    app('auth')->forgetGuards();
    $this->withToken($otherToken)->getJson("/api/v1/uae-en/support/tickets/{$number}")->assertNotFound();
    $this->withToken($otherToken)->getJson('/api/v1/uae-en/support/tickets')->assertOk()->assertJsonPath('meta.total', 0);
    // Another customer's order cannot be referenced.
    $this->withToken($otherToken)->postJson('/api/v1/uae-en/support/tickets', ['category' => 'order', 'subject' => 'Hello', 'message' => 'Someone else order here.', 'order' => $order->number])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['order']]]);

    // Tickets live in the country database: the Oman market does not see it.
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson("/api/v1/omn-en/support/tickets/{$number}")->assertNotFound();
});

it('QC-007: validates the category, priority and attachments', function () {
    [, $token] = customerWithToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/support/tickets', ['category' => 'selling', 'subject' => 'Sell', 'message' => 'I want to sell on Nawy please.'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'not_allowed');
    $this->withToken($token)->postJson('/api/v1/uae-en/support/tickets', ['category' => 'bogus', 'subject' => 'x', 'message' => 'short', 'priority' => 'urgent'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['category', 'subject', 'message', 'priority']]]);
    $this->withToken($token)->post('/api/v1/uae-en/support/tickets', [
        'category' => 'order', 'subject' => 'Malware', 'message' => 'Please open my attachment.',
        'attachments' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')],
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['attachments.0']]]);
});

it('QC-007: support replies, keeps internal notes private, waits on the customer, resolves, and the customer rates once', function () {
    [$customer, $token] = customerWithToken();
    $ticket = supportTicketFor($customer);
    $agent = adminAccount('uae', 'support');

    $this->actingAs($agent)->get(onPanel('admin', 'support'))->assertOk()->assertSee($ticket->number);
    $this->actingAs($agent)->post(onPanel('admin', "support/{$ticket->id}/reply"), ['body' => 'Refund risk, check the carrier.', 'internal' => '1'])->assertRedirect();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)->and($ticket->first_responded_at)->toBeNull();

    $this->actingAs($agent)->post(onPanel('admin', "support/{$ticket->id}/reply"), ['body' => 'Could you send the tracking photo?', 'wait' => '1'])->assertRedirect()->assertSessionHasNoErrors();
    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::WaitingCustomer)
        ->and($ticket->first_responded_at)->not->toBeNull()
        ->and($ticket->assigned_admin_id)->toBe($agent->id)
        ->and(CustomerNotification::query()->where('user_id', $customer->id)->where('type', 'support_ticket.waiting_customer')->exists())->toBeTrue();

    app('auth')->forgetGuards();
    $show = $this->withToken($token)->getJson("/api/v1/uae-en/support/tickets/{$ticket->number}")->assertOk()
        ->assertJsonPath('data.status', 'waiting_customer')
        ->assertJsonPath('data.assigned', true)
        ->assertJsonCount(2, 'data.messages')
        ->assertJsonPath('data.messages.1.from', 'support');
    expect($show->getContent())->not->toContain('Refund risk');

    $this->withToken($token)->postJson("/api/v1/uae-en/support/tickets/{$ticket->number}/rating", ['rating' => 5])
        ->assertUnprocessable()->assertJsonPath('error.code', 'not_allowed');

    $this->withToken($token)->postJson("/api/v1/uae-en/support/tickets/{$ticket->number}/messages", ['body' => 'Here it is.'])->assertCreated()
        ->assertJsonPath('data.status', 'in_progress');

    $this->actingAs($agent)->post(onPanel('admin', "support/{$ticket->id}/status"), ['status' => 'open'])->assertSessionHasErrors('status');
    $this->actingAs($agent)->post(onPanel('admin', "support/{$ticket->id}/status"), ['status' => 'closed'])->assertSessionHasErrors('action');
    $this->actingAs($agent)->post(onPanel('admin', "support/{$ticket->id}/status"), ['status' => 'resolved'])->assertRedirect()->assertSessionHasNoErrors();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved)
        ->and(CustomerNotification::query()->where('user_id', $customer->id)->where('type', 'support_ticket.resolved')->exists())->toBeTrue();

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson("/api/v1/uae-en/support/tickets/{$ticket->number}/messages", ['body' => 'One more thing'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'not_allowed');
    $this->withToken($token)->postJson("/api/v1/uae-en/support/tickets/{$ticket->number}/rating", ['rating' => 6])->assertUnprocessable();
    $this->withToken($token)->postJson("/api/v1/uae-en/support/tickets/{$ticket->number}/rating", ['rating' => 4, 'comment' => 'Quick help'])->assertOk()
        ->assertJsonPath('data.rating.score', 4)->assertJsonPath('data.can_rate', false);
    $this->withToken($token)->postJson("/api/v1/uae-en/support/tickets/{$ticket->number}/rating", ['rating' => 1])
        ->assertUnprocessable()->assertJsonPath('error.code', 'not_allowed');

    expect(StateTransition::query()->where('subject_type', 'support_ticket')->where('subject_id', $ticket->id)->pluck('to_status')->all())
        ->toBe(['open', 'in_progress', 'waiting_customer', 'in_progress', 'resolved']);
});

it('QC-007: a resolved ticket reopens within the reopen window only, and auto-closes after the auto-close days', function () {
    [$customer, $token] = customerWithToken();
    $admin = adminAccount();
    $tickets = app(SupportTickets::class);

    $recent = supportTicketFor($customer);
    $tickets->assign($recent, $admin->id, $admin->id);
    $tickets->move($recent, TicketStatus::Resolved, $admin->id);

    $old = supportTicketFor($customer);
    $tickets->assign($old, $admin->id, $admin->id);
    $tickets->move($old, TicketStatus::Resolved, $admin->id);
    $old->forceFill(['resolved_at' => now()->subDays(8)])->save();

    $this->withToken($token)->getJson("/api/v1/uae-en/support/tickets/{$recent->number}")->assertJsonPath('data.can_reopen', true);
    $this->withToken($token)->postJson("/api/v1/uae-en/support/tickets/{$old->number}/reopen")
        ->assertUnprocessable()->assertJsonPath('error.code', 'not_allowed');
    $this->withToken($token)->postJson("/api/v1/uae-en/support/tickets/{$recent->number}/reopen", ['message' => 'Still broken.'])->assertOk()
        ->assertJsonPath('data.status', 'open')->assertJsonPath('data.messages.1.body', 'Still broken.');

    $tickets->move($recent->refresh(), TicketStatus::InProgress, $admin->id);
    $tickets->move($recent, TicketStatus::Resolved, $admin->id);
    $recent->forceFill(['resolved_at' => now()->subDays(3)])->save();

    $this->artisan('nawy:support:auto-close')->assertSuccessful();

    expect($old->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($old->closed_at)->not->toBeNull()
        ->and($recent->refresh()->status)->toBe(TicketStatus::Resolved)
        ->and(StateTransition::query()->where('subject_type', 'support_ticket')->where('subject_id', $old->id)->latest('id')->value('actor_type'))->toBe('system');

    // A country can shorten the auto-close window.
    app(SettingsService::class)->set('support.auto_close_days', 2, $this->country);
    $this->artisan('nawy:support:auto-close')->assertSuccessful();
    expect($recent->refresh()->status)->toBe(TicketStatus::Closed);
    $this->withToken($token)->postJson("/api/v1/uae-en/support/tickets/{$recent->number}/messages", ['body' => 'Hello?'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'not_allowed');
});

it('QC-007: SLA due times follow the priority and the country settings, and overdue tickets are flagged in the queue', function () {
    $this->travelTo(now()->startOfMinute());
    [$customer] = customerWithToken();
    app(SettingsService::class)->set('support.first_response_hours', ['urgent' => 1, 'high' => 3, 'normal' => 6, 'low' => 10], $this->country);

    $ticket = supportTicketFor($customer, TicketPriority::High);
    expect($ticket->first_response_due_at->equalTo(now()->addHours(3)))->toBeTrue()
        ->and($ticket->resolution_due_at->equalTo(now()->addHours(48)))->toBeTrue();

    $admin = adminAccount();
    $this->actingAs($admin)->post(onPanel('admin', "support/{$ticket->id}/priority"), ['priority' => 'urgent'])->assertRedirect();
    expect($ticket->refresh()->priority)->toBe(TicketPriority::Urgent)
        ->and($ticket->first_response_due_at->equalTo(now()->addHour()))->toBeTrue();

    $this->travel(2)->hours();
    $fresh = supportTicketFor($customer);
    $this->actingAs($admin)->get(onPanel('admin', 'support?overdue=1'))->assertOk()
        ->assertSee($ticket->number)->assertDontSee($fresh->number);

    $this->actingAs($admin)->post(onPanel('admin', "support/{$fresh->id}/assign"), ['assignee' => $admin->id])->assertRedirect()->assertSessionHasNoErrors();
    expect($fresh->refresh()->status)->toBe(TicketStatus::InProgress)->and($fresh->assigned_admin_id)->toBe($admin->id);
    $this->actingAs($admin)->get(onPanel('admin', 'support?assignee=me'))->assertOk()->assertSee($fresh->number)->assertDontSee($ticket->number);
    $this->actingAs($admin)->post(onPanel('admin', "support/{$fresh->id}/assign"), ['assignee' => User::factory()->create()->id])->assertSessionHasErrors('assignee');
});

it('QC-007: admin queue permissions — tickets.view to read, tickets.manage to act', function () {
    [$customer] = customerWithToken();
    $ticket = supportTicketFor($customer);

    $finance = adminAccount('uae', 'finance');
    $this->actingAs($finance)->get(onPanel('admin', 'support'))->assertForbidden();
    $this->actingAs($finance)->get(onPanel('admin', "support/{$ticket->id}"))->assertForbidden();

    $content = adminAccount('uae', 'content');
    $this->actingAs($content)->get(onPanel('admin', "support/{$ticket->id}"))->assertOk()->assertSee('Where is my parcel?')->assertDontSee('Change priority');
    $this->actingAs($content)->post(onPanel('admin', "support/{$ticket->id}/reply"), ['body' => 'Hello there'])->assertForbidden();
    $this->actingAs($content)->post(onPanel('admin', "support/{$ticket->id}/status"), ['status' => 'resolved'])->assertForbidden();

    $operations = adminAccount('uae', 'operations');
    $this->actingAs($operations)->get(onPanel('admin', 'support'))->assertOk()->assertSee($ticket->number);
    $this->actingAs($operations)->post(onPanel('admin', "support/{$ticket->id}/assign"), ['assignee' => $operations->id])->assertForbidden();
});

it('QC-007: the vendor app opens and follows the store\'s tickets, shared with the team; other stores get a 404', function () {
    [$seller, $vendor] = sellerAccount();
    $seller->forceFill(['password' => bcrypt('password')])->save();
    $subOrder = subOrderFor($vendor, SubOrderStatus::Delivered);
    $token = supportOpsToken($seller);

    $this->withToken($token)->getJson('/api/v1/uae-en/ops/vendor/support/categories')->assertOk()->assertJsonFragment(['value' => 'payouts']);
    $number = $this->withToken($token)->postJson('/api/v1/uae-en/ops/vendor/support/tickets', [
        'category' => 'payouts', 'subject' => 'Payout missing', 'message' => 'Last week payout did not arrive.', 'shipment' => $subOrder->number, 'priority' => 'high',
    ])->assertCreated()->assertJsonPath('data.shipment', $subOrder->number)->json('data.number');

    $ticket = SupportTicket::query()->where('number', $number)->sole();
    expect($ticket->requester_type->value)->toBe('vendor')->and($ticket->vendor_id)->toBe($vendor->id)->and($ticket->tenant_id)->toBe($vendor->id);

    $this->withToken($token)->postJson("/api/v1/uae-en/ops/vendor/support/tickets/{$number}/rating", ['rating' => 3])
        ->assertUnprocessable()->assertJsonPath('error.code', 'transition_refused');

    [$otherSeller, $otherVendor] = sellerAccount();
    $otherSeller->forceFill(['password' => bcrypt('password')])->save();
    $otherToken = supportOpsToken($otherSeller);
    $this->withToken($otherToken)->getJson("/api/v1/uae-en/ops/vendor/support/tickets/{$number}")->assertNotFound();
    $this->withToken($otherToken)->postJson('/api/v1/uae-en/ops/vendor/support/tickets', [
        'category' => 'order', 'subject' => 'Not mine', 'message' => 'Shipment of another store.', 'shipment' => $subOrder->number,
    ])->assertUnprocessable();

    // Seller Center: the same tickets, list, create, show and reply; another store's ticket is a 404.
    $this->actingAs($seller)->get(onPanel('partner', 'support'))->assertOk()->assertSee($number);
    $this->actingAs($seller)->post(onPanel('partner', 'support'), ['category' => 'technical', 'subject' => 'Upload fails', 'message' => 'Images fail to upload on the product form.'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $panelTicket = SupportTicket::query()->where('subject', 'Upload fails')->sole();
    expect($panelTicket->tenant_id)->toBe($vendor->id);
    $this->actingAs($seller)->post(onPanel('partner', "support/{$panelTicket->id}/reply"), ['body' => 'Also on mobile.'])->assertRedirect()->assertSessionHasNoErrors();
    app(SupportTickets::class)->supportReply($panelTicket, adminAccount()->id, 'Internal: known bug', internal: true);
    $this->actingAs($seller)->get(onPanel('partner', "support/{$panelTicket->id}"))->assertOk()->assertSee('Also on mobile.')->assertDontSee('Internal: known bug');
    $this->actingAs($otherSeller)->get(onPanel('partner', "support/{$panelTicket->id}"))->assertNotFound();
});

it('QC-007: account managers see only their vendors\' tickets', function () {
    [, $vendor] = sellerAccount();
    [, $otherVendor] = sellerAccount();
    $manager = adminAccount('uae', 'account_manager');
    $vendor->forceFill(['account_manager_id' => $manager->id])->save();

    $tickets = app(SupportTickets::class);
    $mine = $tickets->open(new TicketRequester(TicketRequesterType::Vendor, $vendor->owner_user_id, 'vendor', $vendor->id, $vendor->id), TicketCategory::Payouts, 'Mine', 'A ticket of my vendor.');
    $theirs = $tickets->open(new TicketRequester(TicketRequesterType::Vendor, $otherVendor->owner_user_id, 'vendor', $otherVendor->id, $otherVendor->id), TicketCategory::Payouts, 'Theirs', 'A ticket of another vendor.');

    $this->actingAs($manager)->get(onPanel('admin', 'support'))->assertOk()->assertSee($mine->number)->assertDontSee($theirs->number);
    $this->actingAs($manager)->get(onPanel('admin', "support/{$theirs->id}"))->assertNotFound();
    $this->actingAs($manager)->post(onPanel('admin', "support/{$mine->id}/reply"), ['body' => 'On it.'])->assertRedirect()->assertSessionHasNoErrors();
});
