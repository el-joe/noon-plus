<?php

use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Models\ProductAnswer;
use App\Domains\Reviews\Models\ProductQuestion;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Reviews\Support\ContentModeration;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| G-W3 reviews & ratings and product Q&A: verified buyers of delivered
| items only, photos, moderation (pending → approved / rejected) in the
| admin, product and seller rating summaries, helpful votes; questions,
| seller / Nawy answers and their moderation.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->actAsCountry('uae');
    Storage::fake('public');
});

/**
 * A customer's order item in a sub-order at `$status`, with a token.
 *
 * @return array{0: OrderItem, 1: User, 2: string}
 */
function boughtItem(SubOrderStatus $status = SubOrderStatus::Delivered, ?Vendor $vendor = null, ?User $customer = null): array
{
    $customer ??= User::factory()->create(['name' => 'Sara Ahmed', 'email_verified_at' => now()]);
    $subOrder = subOrderFor($vendor ?? Vendor::factory()->create(), $status);
    $subOrder->order->forceFill(['customer_user_id' => $customer->id])->save();

    return [$subOrder->items()->firstOrFail(), $customer, $customer->createToken('test')->plainTextToken];
}

function reviewProductSlug(OrderItem $item): string
{
    return $item->listing->variant->product->slug;
}

it('lets the buyer of a delivered item review it once, with photos, pending moderation', function () {
    [$item, $customer, $token] = boughtItem();

    $this->withToken($token)->getJson('/api/v1/uae-en/me/reviewable-items')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_item_id', $item->id);

    $this->withToken($token)->post('/api/v1/uae-en/reviews', [
        'order_item_id' => $item->id,
        'rating' => 4,
        'title' => 'Great value',
        'body' => 'Works as described.',
        'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.author', 'Sara A.')
        ->assertJsonCount(2, 'data.photos');

    $review = ProductReview::query()->sole();
    Storage::disk('public')->assertExists($review->photos->first()->path);
    expect($review->vendor_id)->toBe($item->subOrder->vendor_id)
        ->and(StateTransition::query()->where('subject_type', 'product_review')->where('to_status', 'pending')->exists())->toBeTrue();

    $this->withToken($token)->postJson('/api/v1/uae-en/reviews', ['order_item_id' => $item->id, 'rating' => 5])
        ->assertUnprocessable()->assertJsonPath('error.code', 'review_not_allowed');
    $this->withToken($token)->getJson('/api/v1/uae-en/me/reviewable-items')->assertJsonCount(0, 'data');
    $this->withToken($token)->getJson('/api/v1/uae-en/me/reviews')->assertJsonPath('data.0.status', 'pending');

    // Not public until approved.
    $this->getJson('/api/v1/uae-en/products/'.reviewProductSlug($item).'/reviews')->assertOk()->assertJsonCount(0, 'data.reviews')->assertJsonPath('data.summary.count', 0);
});

it('refuses reviews from someone who did not receive the item', function (SubOrderStatus $status, bool $sameCustomer) {
    [$item, , $buyerToken] = boughtItem($status);
    [, $otherToken] = customerWithToken();

    $this->withToken($sameCustomer ? $buyerToken : $otherToken)->postJson('/api/v1/uae-en/reviews', ['order_item_id' => $item->id, 'rating' => 5])
        ->assertUnprocessable()->assertJsonPath('error.code', 'review_not_allowed');
})->with([
    'shipped, not delivered' => [SubOrderStatus::Shipped, true],
    'cancelled' => [SubOrderStatus::Cancelled, true],
    'another customer' => [SubOrderStatus::Delivered, false],
]);

it('validates the rating and the photos', function () {
    [$item, , $token] = boughtItem();

    $this->withToken($token)->postJson('/api/v1/uae-en/reviews', ['order_item_id' => $item->id, 'rating' => 6])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonValidationErrors(['rating'], 'error.fields');
    $this->withToken($token)->post('/api/v1/uae-en/reviews', ['order_item_id' => $item->id, 'rating' => 3, 'photos' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['photos.0'], 'error.fields');
    app('auth')->forgetGuards();
    $this->withoutToken()->postJson('/api/v1/uae-en/reviews', ['order_item_id' => $item->id, 'rating' => 3])->assertUnauthorized();
});

it('publishes on approval and keeps the product and seller rating summaries in step with moderation', function () {
    $vendor = Vendor::factory()->create(['slug' => 'gadget-hub']);
    [$first, , $token] = boughtItem(vendor: $vendor);
    [$second, , $secondToken] = boughtItem(SubOrderStatus::Completed, vendor: $vendor);
    $second->forceFill(['listing_id' => $first->listing_id])->save();
    $this->withToken($token)->postJson('/api/v1/uae-en/reviews', ['order_item_id' => $first->id, 'rating' => 5, 'body' => 'Love it'])->assertCreated();
    app('auth')->forgetGuards();
    $this->withToken($secondToken)->postJson('/api/v1/uae-en/reviews', ['order_item_id' => $second->id, 'rating' => 2, 'body' => 'Meh'])->assertCreated();
    [$five, $two] = ProductReview::query()->orderBy('id')->get()->all();
    $admin = adminAccount();
    $moderation = app(ContentModeration::class);

    $moderation->approve($five, $admin->id);
    $moderation->approve($two, $admin->id);
    $slug = reviewProductSlug($first);

    $this->getJson("/api/v1/uae-en/products/{$slug}/reviews")->assertOk()
        ->assertJsonPath('data.summary.average', '3.50')
        ->assertJsonPath('data.summary.count', 2)
        ->assertJsonPath('data.summary.histogram.5', 1)
        ->assertJsonPath('data.summary.histogram.2', 1)
        ->assertJsonCount(2, 'data.reviews');
    $this->getJson("/api/v1/uae-en/products/{$slug}/reviews?rating=5")->assertJsonCount(1, 'data.reviews')->assertJsonPath('data.reviews.0.verified_purchase', true);
    $this->getJson("/api/v1/uae-en/products/{$slug}")->assertJsonPath('data.rating', ['average' => '3.50', 'count' => 2]);
    $this->getJson('/api/v1/uae-en/sellers/gadget-hub/reviews')->assertOk()->assertJsonPath('data.seller.slug', 'gadget-hub')->assertJsonPath('data.summary.count', 2);
    expect((string) $vendor->fresh()->rating)->toBe('3.50')->and($vendor->fresh()->rating_count)->toBe(2);

    $moderation->reject($two, $admin->id, 'Off-topic');

    expect($two->fresh()->status)->toBe(ModerationStatus::Rejected)->and($two->fresh()->rejection_reason)->toBe('Off-topic');
    $this->getJson("/api/v1/uae-en/products/{$slug}")->assertJsonPath('data.rating', ['average' => '5.00', 'count' => 1]);
    expect((string) $vendor->fresh()->rating)->toBe('5.00');
});

it('counts one helpful vote per customer, never on an own review, and undoes it', function () {
    [$item, , $authorToken] = boughtItem();
    $this->withToken($authorToken)->postJson('/api/v1/uae-en/reviews', ['order_item_id' => $item->id, 'rating' => 4])->assertCreated();
    $review = ProductReview::query()->sole();
    app('auth')->forgetGuards();
    [, $voterToken] = customerWithToken();

    $this->withToken($voterToken)->postJson("/api/v1/uae-en/reviews/{$review->id}/helpful")->assertNotFound();
    app(ContentModeration::class)->approve($review, null);

    $this->withToken($voterToken)->postJson("/api/v1/uae-en/reviews/{$review->id}/helpful")->assertOk()->assertJsonPath('data.helpful_count', 1);
    $this->withToken($voterToken)->postJson("/api/v1/uae-en/reviews/{$review->id}/helpful")->assertOk()->assertJsonPath('data.helpful_count', 1);
    $this->withToken($voterToken)->getJson('/api/v1/uae-en/products/'.reviewProductSlug($item).'/reviews?sort=helpful')
        ->assertJsonPath('data.reviews.0.voted_helpful', true)->assertJsonPath('data.reviews.0.helpful_count', 1);
    $this->withToken($voterToken)->deleteJson("/api/v1/uae-en/reviews/{$review->id}/helpful")->assertOk()->assertJsonPath('data.helpful_count', 0);
    app('auth')->forgetGuards();
    $this->withToken($authorToken)->postJson("/api/v1/uae-en/reviews/{$review->id}/helpful")->assertUnprocessable()->assertJsonPath('error.code', 'vote_not_allowed');
});

it('moderates reviews in the admin with a reason on rejection, per permission', function () {
    [$item, , $token] = boughtItem();
    $this->withToken($token)->postJson('/api/v1/uae-en/reviews', ['order_item_id' => $item->id, 'rating' => 1, 'body' => 'Broken on arrival'])->assertCreated();
    $review = ProductReview::query()->sole();
    $admin = adminAccount('uae', 'support');

    $this->actingAs($admin)->get(onPanel('admin', 'reviews?country=uae'))->assertOk()->assertSee('data-review="'.$review->id.'"', false)->assertSee('Broken on arrival');
    $this->post(onPanel('admin', "reviews/reviews/{$review->id}"), ['decision' => 'reject'])->assertSessionHasErrors('reason');
    $this->post(onPanel('admin', "reviews/reviews/{$review->id}"), ['decision' => 'approve'])->assertSessionHasNoErrors();

    expect($review->fresh()->status)->toBe(ModerationStatus::Approved)
        ->and($review->fresh()->moderated_by_user_id)->toBe($admin->id)
        ->and(StateTransition::query()->where('subject_type', 'product_review')->where('to_status', 'approved')->value('actor_user_id'))->toBe($admin->id);

    $this->actingAs(adminAccount('uae', 'finance'))->post(onPanel('admin', "reviews/reviews/{$review->id}"), ['decision' => 'reject', 'reason' => 'Spam'])->assertForbidden();
});

it('takes customer questions, seller answers (moderated) and Nawy answers (published at once)', function () {
    [$sellerUser, $vendor] = sellerAccount('uae', ['store_name' => 'Gadget Hub']);
    $listing = sellableListing('50.00', vendor: $vendor);
    $slug = $listing->variant->product->slug;
    [, $token] = customerWithToken();

    $this->withToken($token)->postJson("/api/v1/uae-en/products/{$slug}/questions", ['body' => 'Hi'])->assertUnprocessable()->assertJsonValidationErrors(['body'], 'error.fields');
    $this->withToken($token)->postJson("/api/v1/uae-en/products/{$slug}/questions", ['body' => 'Does it support dual SIM?'])->assertCreated()->assertJsonPath('data.status', 'pending');
    $question = ProductQuestion::query()->sole();
    $this->getJson("/api/v1/uae-en/products/{$slug}/questions")->assertOk()->assertJsonCount(0, 'data');

    $admin = adminAccount();
    $this->actingAs($admin)->get(onPanel('admin', 'reviews?country=uae&tab=questions'))->assertOk()->assertSee('data-question="'.$question->id.'"', false);
    $this->post(onPanel('admin', "reviews/questions/{$question->id}"), ['decision' => 'approve'])->assertSessionHasNoErrors();

    // A seller of the product answers from Seller Center; the answer waits for moderation.
    $this->actingAs($sellerUser)->get(onPanel('partner', 'reviews'))->assertOk()->assertSee('Does it support dual SIM?');
    $this->post(onPanel('partner', "reviews/questions/{$question->id}/answer"), ['body' => 'Yes, two nano SIMs.'])->assertSessionHasNoErrors();
    $answer = ProductAnswer::query()->sole();
    expect($answer->status)->toBe(ModerationStatus::Pending)->and($answer->vendor_id)->toBe($vendor->id);
    $this->getJson("/api/v1/uae-en/products/{$slug}/questions")->assertJsonCount(1, 'data')->assertJsonCount(0, 'data.0.answers');

    $this->actingAs($admin)->post(onPanel('admin', "reviews/answers/{$answer->id}"), ['decision' => 'approve'])->assertSessionHasNoErrors();
    $this->post(onPanel('admin', "reviews/questions/{$question->id}/answer"), ['body' => 'It also supports eSIM.'])->assertSessionHasNoErrors();

    $this->getJson("/api/v1/uae-en/products/{$slug}/questions")
        ->assertJsonCount(2, 'data.0.answers')
        ->assertJsonPath('data.0.answers.0', fn ($first) => $first['by'] === 'vendor' && $first['author'] === 'Gadget Hub')
        ->assertJsonPath('data.0.answers.1.author', 'Nawy');
});

it('lets only sellers of the product answer', function () {
    [$sellerUser] = sellerAccount('uae');
    $question = ProductQuestion::query()->create([
        'product_id' => sellableListing()->variant->product_id, 'customer_user_id' => 1, 'customer_name' => 'Ali', 'body' => 'Warranty?', 'status' => ModerationStatus::Approved,
    ]);

    $this->actingAs($sellerUser)->post(onPanel('partner', "reviews/questions/{$question->id}/answer"), ['body' => 'Two years.'])
        ->assertSessionHasErrors(['action' => 'Only sellers of this product can answer.']);
    expect(ProductAnswer::query()->count())->toBe(0);
});

it('keeps reviews to the country where the item was bought', function () {
    [$item, , $token] = boughtItem();

    $this->actAsCountry('omn');
    $this->withToken($token)->postJson('/api/v1/omn-en/reviews', ['order_item_id' => $item->id, 'rating' => 5])->assertUnprocessable();
    $this->actAsCountry('uae');
    expect(ProductReview::query()->count())->toBe(0);
});
