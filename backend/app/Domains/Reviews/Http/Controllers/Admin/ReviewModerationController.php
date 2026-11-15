<?php

namespace App\Domains\Reviews\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Http\Requests\AnswerQuestionRequest;
use App\Domains\Reviews\Models\ProductAnswer;
use App\Domains\Reviews\Models\ProductQuestion;
use App\Domains\Reviews\Models\ProductReview;
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
    public const TABS = ['reviews', 'questions', 'answers'];

    public function __construct(private ContentModeration $moderation, private CountryContext $countryContext) {}

    public function index(Request $request, CountryClock $clock): View
    {
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'status' => ['nullable', Rule::enum(ModerationStatus::class)],
        ]);
        $tab = $filters['tab'] ?? 'reviews';
        $status = $filters['status'] ?? ModerationStatus::Pending->value;

        $items = match ($tab) {
            'questions' => ProductQuestion::query()->with(['product', 'answers.vendor']),
            'answers' => ProductAnswer::query()->with(['question.product', 'vendor']),
            default => ProductReview::query()->with(['product', 'vendor', 'photos']),
        };

        return view('panels.admin.reviews.index', [
            'tab' => $tab,
            'status' => $status,
            'items' => $items->where('status', $status)->oldest('id')->paginate(25)->withQueryString(),
            'pending' => [
                'reviews' => ProductReview::query()->where('status', ModerationStatus::Pending)->count(),
                'questions' => ProductQuestion::query()->where('status', ModerationStatus::Pending)->count(),
                'answers' => ProductAnswer::query()->where('status', ModerationStatus::Pending)->count(),
            ],
            'country' => $this->countryContext->current(),
            'clock' => $clock,
        ]);
    }

    /**
     * Approve or reject; the queue (`reviews`, `questions`, `answers`) is the route's `type` default.
     */
    public function decide(Request $request, int $id): RedirectResponse
    {
        $type = (string) $request->route('type');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'required_if:decision,reject', 'string', 'min:3', 'max:255'],
        ]);
        $subject = $this->subject($type, $id);

        try {
            $data['decision'] === 'approve'
                ? $this->moderation->approve($subject, $request->user()->id)
                : $this->moderation->reject($subject, $request->user()->id, (string) $data['reason']);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', $data['decision'] === 'approve' ? __('Approved and published.') : __('Rejected.'));
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

    private function subject(string $type, int $id): ProductReview|ProductQuestion|ProductAnswer
    {
        /** @var class-string<Model> $model */
        $model = match ($type) {
            'reviews' => ProductReview::class,
            'questions' => ProductQuestion::class,
            'answers' => ProductAnswer::class,
            default => abort(404),
        };

        return $model::query()->findOrFail($id);
    }
}
