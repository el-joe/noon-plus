<?php

namespace App\Domains\Reviews\Http\Resources;

use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Models\ProductAnswer;
use App\Domains\Reviews\Models\ProductQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product question with its published answers.
 *
 * @mixin ProductQuestion
 */
class QuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => $this->customer_name,
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),
            'answers' => $this->answers
                ->filter(fn (ProductAnswer $answer): bool => $answer->status === ModerationStatus::Approved)
                ->map(fn (ProductAnswer $answer): array => [
                    'id' => $answer->id,
                    'body' => $answer->body,
                    'by' => $answer->author_type,
                    'author' => $answer->author_type === ProductAnswer::AUTHOR_ADMIN ? 'Nawy' : $answer->vendor?->store_name,
                    'created_at' => $answer->created_at->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
