<?php

namespace App\Domains\Reviews\Http\Controllers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Http\Requests\StoreQuestionRequest;
use App\Domains\Reviews\Http\Resources\QuestionResource;
use App\Domains\Reviews\Models\ProductQuestion;
use App\Domains\Reviews\Support\ProductQuestions;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product Q&A API (G-W3): published questions with their published
 * answers; a signed-in customer asks a question (moderated).
 */
class QuestionController extends Controller
{
    public function index(Request $request, string $slug): JsonResponse
    {
        $product = $this->product($slug);
        $page = ProductQuestion::query()
            ->where('product_id', $product->id)
            ->where('status', ModerationStatus::Approved)
            ->with('answers.vendor')
            ->latest('id')
            ->paginate((int) min(50, max(1, (int) $request->integer('per_page', 10))));

        return ApiResponse::success(
            QuestionResource::collection($page->items())->resolve($request),
            ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        );
    }

    public function store(StoreQuestionRequest $request, string $slug, ProductQuestions $questions): JsonResponse
    {
        $question = $questions->ask($request->user(), $this->product($slug), $request->validated('body'));

        return ApiResponse::success((new QuestionResource($question->load('answers')))->resolve($request), status: 201);
    }

    private function product(string $slug): Product
    {
        return Product::query()->where('slug', $slug)->where('status', 'active')->firstOrFail();
    }
}
