<?php

namespace App\Domains\Reviews\Http\Controllers\Seller;

use App\Domains\Listings\Models\Listing;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Http\Requests\AnswerQuestionRequest;
use App\Domains\Reviews\Models\ProductQuestion;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Reviews\Support\ProductQuestions;
use App\Domains\Reviews\Support\RatingAggregates;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Seller Center → Reviews (G-W3): the store's rating summary and published
 * reviews, and published customer questions on products the store sells,
 * which it can answer (answers are moderated by Nawy before they show).
 */
class SellerReviewController extends Controller
{
    public function __construct(private VendorWorkspace $workspace) {}

    public function index(RatingAggregates $aggregates): View
    {
        $vendor = $this->workspace->vendor();
        $productIds = Listing::query()->where('vendor_id', $vendor->id)
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->distinct()->pluck('product_variants.product_id');

        return view('panels.partner.reviews.index', [
            'summary' => $aggregates->summary(ProductReview::query()->where('vendor_id', $vendor->id)),
            'reviews' => ProductReview::query()->where('vendor_id', $vendor->id)->where('status', ModerationStatus::Approved)
                ->with(['product', 'photos'])->latest('id')->paginate(20, pageName: 'reviews_page'),
            'questions' => ProductQuestion::query()->whereIn('product_id', $productIds)->where('status', ModerationStatus::Approved)
                ->with(['product', 'answers' => fn ($answers) => $answers->where('vendor_id', $vendor->id)])
                ->latest('id')->paginate(20, pageName: 'questions_page'),
        ]);
    }

    public function answer(AnswerQuestionRequest $request, int $question, ProductQuestions $questions): RedirectResponse
    {
        try {
            $questions->answerAsVendor($this->workspace->vendor(), $request->user()->id, ProductQuestion::query()->findOrFail($question), $request->validated('body'));
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Answer sent; it shows once Nawy approves it.'));
    }
}
