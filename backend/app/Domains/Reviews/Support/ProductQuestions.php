<?php

namespace App\Domains\Reviews\Support;

use App\Domains\Catalog\Models\Product;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Models\ProductAnswer;
use App\Domains\Reviews\Models\ProductQuestion;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * Product Q&A (G-W3): a signed-in customer asks (moderated); a seller of
 * the product answers an approved question (moderated), Nawy answers in
 * the admin (published at once).
 */
class ProductQuestions
{
    public function __construct(private StateMachine $machine, private ContentModeration $moderation) {}

    public function ask(User $customer, Product $product, string $body): ProductQuestion
    {
        return DB::connection($product->getConnectionName())->transaction(function () use ($customer, $product, $body): ProductQuestion {
            $question = ProductQuestion::query()->create([
                'product_id' => $product->id,
                'customer_user_id' => $customer->id,
                'customer_name' => ProductReviews::displayName((string) $customer->name),
                'body' => trim($body),
                'locale' => app()->getLocale() === 'ar' ? 'ar' : 'en',
                'status' => ModerationStatus::Pending,
            ]);
            $this->machine->created($question, ActorType::Customer, $customer->id);

            return $question;
        });
    }

    public function sells(Vendor $vendor, int $productId): bool
    {
        return Listing::query()
            ->where('vendor_id', $vendor->id)
            ->whereHas('variant', fn ($variant) => $variant->where('product_id', $productId))
            ->exists();
    }

    public function answerAsVendor(Vendor $vendor, int $userId, ProductQuestion $question, string $body): ProductAnswer
    {
        if ($question->status !== ModerationStatus::Approved) {
            throw new TransitionRefused(__('Only published questions can be answered.'));
        }

        if (! $this->sells($vendor, (int) $question->product_id)) {
            throw new TransitionRefused(__('Only sellers of this product can answer.'));
        }

        return $this->answer($question, ProductAnswer::AUTHOR_VENDOR, $vendor->id, $userId, $body, ActorType::Vendor);
    }

    public function answerAsAdmin(int $userId, ProductQuestion $question, string $body): ProductAnswer
    {
        return DB::connection($question->getConnectionName())->transaction(function () use ($userId, $question, $body): ProductAnswer {
            if ($question->status === ModerationStatus::Pending) {
                $this->moderation->approve($question, $userId);
            }

            if ($question->status !== ModerationStatus::Approved) {
                throw new TransitionRefused(__('Only published questions can be answered.'));
            }

            $answer = $this->answer($question, ProductAnswer::AUTHOR_ADMIN, null, $userId, $body, ActorType::Admin);
            $this->moderation->approve($answer, $userId);

            return $answer;
        });
    }

    private function answer(ProductQuestion $question, string $authorType, ?int $vendorId, int $userId, string $body, ActorType $actorType): ProductAnswer
    {
        return DB::connection($question->getConnectionName())->transaction(function () use ($question, $authorType, $vendorId, $userId, $body, $actorType): ProductAnswer {
            $answer = $question->answers()->create([
                'author_type' => $authorType,
                'vendor_id' => $vendorId,
                'author_user_id' => $userId,
                'body' => trim($body),
                'status' => ModerationStatus::Pending,
            ]);
            $this->machine->created($answer, $actorType, $userId);

            return $answer;
        });
    }
}
