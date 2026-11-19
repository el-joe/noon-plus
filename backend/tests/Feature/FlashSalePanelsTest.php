<?php

use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\FlashSales\Support\FlashSaleEventMachine;
use App\Domains\FlashSales\Support\FlashSaleSubmissionMachine;
use App\Domains\Orders\Enums\ActorType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    [$this->seller, $this->vendor] = sellerAccount('uae');
    $this->actAsCountry('uae');
});

function openFlashSale(): FlashSaleEvent
{
    return FlashSaleEvent::factory()->inStatus(FlashSaleEventStatus::SubmissionOpen)->create(['name' => 'Yellow Week']);
}

it('creates a draft event from times typed in the country\'s local time', function () {
    $admin = adminAccount('uae', 'marketing');

    $this->actingAs($admin)->get(onPanel('admin', 'flash-sales'))->assertOk()->assertSee('data-country-datetime', false)->assertSee('Asia/Dubai');
    $this->post(onPanel('admin', 'flash-sales'), [
        'name' => 'Yellow Week',
        'submission_starts_at' => '2026-11-20T09:00',
        'submission_ends_at' => '2026-11-25T23:00',
        'starts_at' => '2026-11-27T00:00',
        'ends_at' => '2026-11-29T23:59',
        'max_qty_per_customer' => 3,
    ])->assertRedirect();

    $event = FlashSaleEvent::query()->sole();
    expect($event->status)->toBe(FlashSaleEventStatus::Draft)
        ->and($event->starts_at->toDateTimeString())->toBe('2026-11-26 20:00:00')
        ->and($event->submission_starts_at->toDateTimeString())->toBe('2026-11-20 05:00:00')
        ->and($event->max_qty_per_customer)->toBe(3)
        ->and($event->created_by)->toBe($admin->id);
});

it('refuses an event that ends before it starts or has malformed times', function () {
    $this->actingAs(adminAccount());

    $this->post(onPanel('admin', 'flash-sales'), ['name' => 'Backwards', 'starts_at' => '2026-11-27T00:00', 'ends_at' => '2026-11-26T00:00'])
        ->assertSessionHasErrors(['action' => 'A flash sale must end after it starts.']);
    $this->post(onPanel('admin', 'flash-sales'), ['name' => 'Bad', 'starts_at' => 'tomorrow', 'ends_at' => ''])->assertSessionHasErrors(['starts_at', 'ends_at']);

    expect(FlashSaleEvent::query()->count())->toBe(0);
});

it('moves an event through its lifecycle and needs a reason to cancel', function () {
    $event = FlashSaleEvent::factory()->create();
    $this->actingAs(adminAccount());

    $this->post(onPanel('admin', "flash-sales/{$event->id}/status"), ['to' => 'submission_open'])->assertSessionHasNoErrors();
    expect($event->fresh()->status)->toBe(FlashSaleEventStatus::SubmissionOpen);

    $this->post(onPanel('admin', "flash-sales/{$event->id}/status"), ['to' => 'live'])->assertSessionHasErrors('action');
    $this->post(onPanel('admin', "flash-sales/{$event->id}/status"), ['to' => 'cancelled'])->assertSessionHasErrors('reason');
    $this->post(onPanel('admin', "flash-sales/{$event->id}/status"), ['to' => 'cancelled', 'reason' => 'Budget cut'])->assertSessionHasNoErrors();

    expect($event->fresh()->status)->toBe(FlashSaleEventStatus::Cancelled)
        ->and($event->statusHistory()->get()->last()->actor_type)->toBe(ActorType::Admin);
    $this->get(onPanel('admin', "flash-sales/{$event->id}"))->assertOk()->assertSee('Budget cut');
});

it('lets a seller offer an own active listing to an open event and withdraw it', function () {
    $event = openFlashSale();
    $listing = sellableListing('100.00', stock: 10, vendor: $this->vendor);

    $this->actingAs($this->seller)->get(onPanel('partner', 'flash-sales'))->assertOk()->assertSee('Yellow Week');
    $this->post(onPanel('partner', "flash-sales/{$event->id}/submissions"), ['listing_id' => $listing->id, 'flash_price' => '79.00', 'quantity_cap' => 5])
        ->assertSessionHasNoErrors();

    $submission = FlashSaleSubmission::query()->sole();
    expect($submission->status)->toBe(FlashSaleSubmissionStatus::Submitted)
        ->and($submission->vendor_id)->toBe($this->vendor->id)
        ->and((string) $submission->flash_price)->toBe('79.0000');

    $this->post(onPanel('partner', "flash-sales/submissions/{$submission->id}/withdraw"))->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe(FlashSaleSubmissionStatus::Withdrawn);
});

it('refuses seller offers that break the submission rules', function (array $payload, string $error) {
    $event = openFlashSale();
    $listing = sellableListing('100.00', stock: 10, vendor: $this->vendor);

    $this->actingAs($this->seller)->post(onPanel('partner', "flash-sales/{$event->id}/submissions"), $payload + ['listing_id' => $listing->id])
        ->assertSessionHasErrors($error);

    expect(FlashSaleSubmission::query()->count())->toBe(0);
})->with([
    'price not below the current price' => [['flash_price' => '100.00', 'quantity_cap' => 2], 'action'],
    'more units than in stock' => [['flash_price' => '80.00', 'quantity_cap' => 11], 'action'],
    'no price' => [['quantity_cap' => 2], 'flash_price'],
]);

it('never lets a seller offer or withdraw another store\'s listing', function () {
    $event = openFlashSale();
    [, $rival] = sellerAccount('uae');
    $this->actAsCountry('uae');
    $theirs = sellableListing('100.00', stock: 10, vendor: $rival);
    $theirSubmission = app(FlashSaleSubmissionMachine::class)->submit($event, $theirs, '70.00', 2);

    $this->actingAs($this->seller)->post(onPanel('partner', "flash-sales/{$event->id}/submissions"), ['listing_id' => $theirs->id, 'flash_price' => '70.00', 'quantity_cap' => 1])
        ->assertSessionHasErrors('listing_id');
    $this->post(onPanel('partner', "flash-sales/submissions/{$theirSubmission->id}/withdraw"))->assertNotFound();

    expect($theirSubmission->fresh()->status)->toBe(FlashSaleSubmissionStatus::Submitted);
});

it('approves and rejects seller offers under review', function () {
    $event = openFlashSale();
    $machine = app(FlashSaleSubmissionMachine::class);
    $first = $machine->submit($event, sellableListing('100.00', stock: 5, vendor: $this->vendor), '80.00', 2);
    $second = $machine->submit($event, sellableListing('50.00', stock: 5, vendor: $this->vendor), '45.00', 2);
    $events = app(FlashSaleEventMachine::class);
    $events->transition($event, FlashSaleEventStatus::SubmissionClosed, ActorType::System);
    $events->transition($event, FlashSaleEventStatus::UnderReview, ActorType::System);
    $this->actingAs(adminAccount('uae', 'marketing'));

    $this->get(onPanel('admin', "flash-sales/{$event->id}"))->assertOk()->assertSee('data-submission="'.$first->id.'"', false)->assertSee('Approve');
    $this->post(onPanel('admin', "flash-sales/submissions/{$first->id}/approve"))->assertSessionHasNoErrors();
    $this->post(onPanel('admin', "flash-sales/submissions/{$second->id}/reject"))->assertSessionHasErrors('reason');
    $this->post(onPanel('admin', "flash-sales/submissions/{$second->id}/reject"), ['reason' => 'Discount too small'])->assertSessionHasNoErrors();

    expect($first->fresh()->status)->toBe(FlashSaleSubmissionStatus::Approved)
        ->and($second->fresh())->status->toBe(FlashSaleSubmissionStatus::Rejected)->rejection_reason->toBe('Discount too small');

    $this->actingAs($this->seller)->get(onPanel('partner', 'flash-sales'))->assertSee('Discount too small');
});

it('lets operations view flash sales but only marketing manage them', function () {
    $event = FlashSaleEvent::factory()->create();

    $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', "flash-sales/{$event->id}"))->assertOk()->assertDontSee('Move event');
    $this->post(onPanel('admin', "flash-sales/{$event->id}/status"), ['to' => 'submission_open'])->assertForbidden();
    $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'flash-sales'))->assertForbidden();

    expect($event->fresh()->status)->toBe(FlashSaleEventStatus::Draft);
});

it('leaves a withdrawn listing out of the offer form for that event, says why, and still refuses it on the server', function () {
    $event = openFlashSale();
    $other = FlashSaleEvent::factory()->inStatus(FlashSaleEventStatus::SubmissionOpen)->create(['name' => 'Blue Week']);
    $listing = sellableListing('100.00', stock: 10, vendor: $this->vendor);
    $submission = app(FlashSaleSubmissionMachine::class)->submit($event, $listing, '79.00', 5, $this->seller->id);
    app(FlashSaleSubmissionMachine::class)->transition($submission, FlashSaleSubmissionStatus::Withdrawn, ActorType::Vendor, $this->seller->id);

    $page = $this->actingAs($this->seller)->get(onPanel('partner', 'flash-sales'))->assertOk();
    $html = $page->getContent();
    $formFor = fn (int $eventId): string => (string) preg_replace('/^.*?(<form[^>]*data-event="'.$eventId.'".*?<\/form>).*$/s', '$1', $html);

    expect($formFor($event->id))->not->toContain('value="'.$listing->id.'"')
        // Another open event still offers it.
        ->and($formFor($other->id))->toContain('value="'.$listing->id.'"');
    $page->assertSee('data-withdrawn-note', false)->assertSee('a withdrawal is final');

    $this->post(onPanel('partner', "flash-sales/{$event->id}/submissions"), ['listing_id' => $listing->id, 'flash_price' => '75.00', 'quantity_cap' => 2])
        ->assertSessionHasErrors(['action' => __('You withdrew this listing from this flash sale; a withdrawal is final, so it cannot be offered to the same event again.')]);
    expect($submission->fresh()->status)->toBe(FlashSaleSubmissionStatus::Withdrawn);
});
