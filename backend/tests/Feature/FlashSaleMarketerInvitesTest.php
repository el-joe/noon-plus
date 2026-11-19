<?php

use App\Domains\Customers\Models\CustomerNotification;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleInviteStatus;
use App\Domains\FlashSales\Enums\MarketerBonusType;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleMarketerInvite;
use App\Domains\FlashSales\Support\FlashSaleEventMachine;
use App\Domains\FlashSales\Support\FlashSaleMarketerInvites;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
| QC-016: flash-sale marketer invite sub-machine (lifecycle catalogue §4.10).
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
});

it('invites every active marketer when submissions open, and the marketer accepts in Creator Studio', function () {
    [$user, $marketer] = marketerAccount(['influencer']);
    [, $pending] = marketerAccount(['affiliate'], 'pending');
    $event = FlashSaleEvent::factory()->create(['marketer_bonus_type' => MarketerBonusType::Percent, 'marketer_bonus_value' => '3']);

    app(FlashSaleEventMachine::class)->transition($event, FlashSaleEventStatus::SubmissionOpen, ActorType::Admin, 1);

    $invite = FlashSaleMarketerInvite::query()->sole();
    expect($invite->marketer_profile_id)->toBe($marketer->id)
        ->and($invite->status)->toBe(FlashSaleInviteStatus::Pending)
        ->and(FlashSaleMarketerInvite::query()->where('marketer_profile_id', $pending->id)->exists())->toBeFalse()
        ->and(CustomerNotification::query()->where('user_id', $user->id)->where('type', 'flash_sale.invited')->exists())->toBeTrue();

    $this->actingAs($user)->get(onPanel('marketer', 'flash-sales'))->assertOk()->assertSee($event->name);
    $this->post(onPanel('marketer', "flash-sales/{$invite->id}/accept"))->assertSessionHasNoErrors();
    expect($invite->refresh()->status)->toBe(FlashSaleInviteStatus::Accepted)
        ->and($invite->responded_at)->not->toBeNull()
        ->and(StateTransition::query()->where('subject_type', 'flash_sale_marketer_invite')->pluck('to_status')->all())->toBe(['pending', 'accepted']);

    $this->post(onPanel('marketer', "flash-sales/{$invite->id}/decline"))->assertSessionHasErrors('action');
});

it('pays the event bonus only to marketers who accepted, and keeps the country percent for events without one', function () {
    [, $accepted] = marketerAccount(['affiliate']);
    [, $declined] = marketerAccount(['affiliate']);
    [, $notInvited] = marketerAccount(['affiliate']);
    $invites = app(FlashSaleMarketerInvites::class);
    $basis = app(MoneyFactory::class)->of('200', 'AED');

    $percent = FlashSaleEvent::factory()->create(['marketer_bonus_type' => MarketerBonusType::Percent, 'marketer_bonus_value' => '5']);
    $invites->respond($invites->invite($percent, $accepted, ActorType::Admin, 1), true, 1);
    $invites->respond($invites->invite($percent, $declined, ActorType::Admin, 1), false, 1);

    expect((string) $invites->bonusFor($percent, $accepted->id, $basis, 2)->getAmount())->toBe('10.00')
        ->and((string) $invites->bonusFor($percent, $declined->id, $basis, 2)->getAmount())->toBe('0.00')
        ->and((string) $invites->bonusFor($percent, $notInvited->id, $basis, 2)->getAmount())->toBe('0.00');

    $fixed = FlashSaleEvent::factory()->create(['marketer_bonus_type' => MarketerBonusType::Fixed, 'marketer_bonus_value' => '4']);
    $invites->respond($invites->invite($fixed, $accepted, ActorType::Admin, 1), true, 1);
    expect((string) $invites->bonusFor($fixed, $accepted->id, $basis, 3)->getAmount())->toBe('12.00');

    expect($invites->bonusFor(FlashSaleEvent::factory()->create(), $accepted->id, $basis, 1))->toBeNull();
});

it('lets admins invite one or all marketers, but not to a finished event or an inactive marketer', function () {
    [, $marketer] = marketerAccount(['influencer']);
    [, $other] = marketerAccount(['affiliate']);
    [, $suspended] = marketerAccount(['affiliate'], 'suspended');
    $admin = adminAccount();
    $event = FlashSaleEvent::factory()->create();

    $this->actingAs($admin)->get(onPanel('admin', "flash-sales/{$event->id}"))->assertOk()->assertSee(__('Marketer invites'));
    $this->post(onPanel('admin', "flash-sales/{$event->id}/marketer-invites"), ['marketer_profile_id' => (string) $marketer->id])->assertSessionHasNoErrors();
    $this->post(onPanel('admin', "flash-sales/{$event->id}/marketer-invites"), ['marketer_profile_id' => (string) $suspended->id])->assertSessionHasErrors('action');
    $this->post(onPanel('admin', "flash-sales/{$event->id}/marketer-invites"), ['marketer_profile_id' => 'all'])->assertSessionHasNoErrors();
    expect(FlashSaleMarketerInvite::query()->where('flash_sale_event_id', $event->id)->pluck('marketer_profile_id')->sort()->values()->all())->toBe([$marketer->id, $other->id]);

    $ended = FlashSaleEvent::factory()->inStatus(FlashSaleEventStatus::Ended)->create();
    $this->post(onPanel('admin', "flash-sales/{$ended->id}/marketer-invites"), ['marketer_profile_id' => 'all'])->assertSessionHasErrors('action');

    $this->post(onPanel('admin', 'flash-sales'), [
        'name' => 'Bonus sale', 'starts_at' => now()->addDays(3)->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(4)->format('Y-m-d\TH:i'),
        'marketer_bonus_type' => 'fixed', 'marketer_bonus_value' => '2.5',
    ])->assertSessionHasNoErrors();
    expect(FlashSaleEvent::query()->where('name', 'Bonus sale')->sole()->marketer_bonus_type)->toBe(MarketerBonusType::Fixed);
});
