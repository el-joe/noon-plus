<?php

namespace App\Domains\Reviews\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Enums\ReplyStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Http\Requests\AnswerQuestionRequest;
use App\Domains\Reviews\Models\ProductAnswer;
use App\Domains\Reviews\Models\ProductQuestion;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Reviews\Models\ProductReviewReply;
use App\Domains\Reviews\Support\ContentModeration;
use App\Domains\Reviews\Support\ProductQuestions;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Reviews & Q&A (SRS §7.8, matrix `reviews` / `product_qa`):
 * queues of reviews, questions and seller answers in the selected country
 * (pending first); approve, reject with a reason, and answer a question
 * as Nawy. Times are shown in Oman time.
 */
class ReviewModerationController extends Controller
{
    public const TABS = ['reviews', 'replies', 'questions', 'answers'];

    public function __construct(private ContentModeration $moderation, private CountryContext $countryContext) {}

    public function index(Request $request, CountryClock $clock): View
    {
        $tab = $request->validate(['tab' => ['nullable', Rule::in(self::TABS)]])['tab'] ?? 'reviews';
        $statuses = $this->statuses($tab);
        $status = $request->validate(['status' => ['nullable', Rule::in(array_keys($statuses))]])['status'] ?? array_key_first($statuses);

        $items = match ($tab) {
            'questions' => ProductQuestion::query()->with(['product', 'answers.vendor']),
            'answers' => ProductAnswer::query()->with(['question.product', 'vendor']),
            'replies' => ProductReviewReply::query()->with(['review.product', 'vendor']),
            default => ProductReview::query()->with(['product', 'vendor', 'photos'])->withCount('reports'),
        };

        return view('panels.admin.reviews.index', [
            'tab' => $tab,
            'status' => $status,
            'statuses' => $statuses,
            'items' => $items->whereIn('status', $statuses[$status])->oldest('id')->paginate(25)->withQueryString(),
            'pending' => [
                'reviews' => ProductReview::query()->whereIn('status', ReviewStatus::queue())->count(),
                'replies' => ProductReviewReply::query()->where('status', ReplyStatus::Pending)->count(),
                'questions' => ProductQuestion::query()->where('status', ModerationStatus::Pending)->count(),
                'answers' => ProductAnswer::query()->where('status', ModerationStatus::Pending)->count(),
            ],
            'country' => $this->countryContext->current(),
            'clock' => $clock,
        ]);
    }

    /**
     * Status filters per queue: key → statuses. Reviews open on the whole
     * moderation queue (pending, reported and auto-flagged; QC-012).
     *
     * @return array<string, array<int, string>>
     */
    private function statuses(string $tab): array
    {
        $cases = match ($tab) {
            'reviews' => ReviewStatus::cases(),
            'replies' => ReplyStatus::cases(),
            default => ModerationStatus::cases(),
        };
        $filters = collect($cases)->mapWithKeys(fn ($case): array => [$case->value => [$case->value]])->all();

        return $tab === 'reviews'
            ? ['queue' => array_map(fn (ReviewStatus $case): string => $case->value, ReviewStatus::queue())] + $filters
            : $filters;
    }

    /**
     * Approve or reject; the queue (`reviews`, `questions`, `answers`) is the route's `type` default.
     */
    public function decide(Request $request, int $id): RedirectResponse
    {
        $type = (string) $request->route('type');
        $data = $request->validate([
            'decision' => ['required', Rule::in($type === 'reviews' ? ['approve', 'reject', 'hide'] : ['approve', 'reject'])],
            'reason' => ['nullable', 'required_if:decision,reject,hide', 'string', 'min:3', 'max:255'],
        ]);
        $subject = $this->subject($type, $id);

        try {
            match ($data['decision']) {
                'approve' => $this->moderation->approve($subject, $request->user()->id),
                'hide' => $this->moderation->hide($subject, $request->user()->id, (string) $data['reason']),
                default => $this->moderation->reject($subject, $request->user()->id, (string) $data['reason']),
            };
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', match ($data['decision']) {
            'approve' => __('Approved and published.'),
            'hide' => __('Hidden from the site.'),
            default => __('Rejected.'),
        });
    }

    public function answer(AnswerQuestionRequest $request, int $question, ProductQuestions $questions): RedirectResponse
    {
        try {
            $questions->answerAsAdmin($request->user()->id, ProductQuestion::query()->findOrFail($question), $request->validated('body'));
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Answer published.'));
    }

    private function subject(string $type, int $id): ProductReview|ProductReviewReply|ProductQuestion|ProductAnswer
    {
        /** @var class-string<Model> $model */
        $model = match ($type) {
            'reviews' => ProductReview::class,
            'replies' => ProductReviewReply::class,
            'questions' => ProductQuestion::class,
            'answers' => ProductAnswer::class,
            default => abort(404),
        };

        return $model::query()->findOrFail($id);
    }
}
