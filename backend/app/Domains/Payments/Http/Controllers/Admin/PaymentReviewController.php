<?php

namespace App\Domains\Payments\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentReviews;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Finance → Payments under review (QA-009 follow-up): payments the
 * gateway captured for another amount. Finance accepts the captured amount,
 * marks it paid after manual reconciliation, refunds it, or fails it.
 */
class PaymentReviewController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(): View
    {
        return view('panels.admin.refunds.payments-review', [
            'payments' => Payment::query()->with('order:id,number')->where('status', PaymentStatus::UnderReview)->oldest('id')->paginate(25),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(int $payment): View
    {
        return view('panels.admin.refunds.payment', [
            'payment' => Payment::query()->with(['order', 'statusHistory', 'refunds'])->findOrFail($payment),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function resolve(Request $request, int $payment, PaymentReviews $reviews): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(PaymentReviews::ACTIONS)],
            'note' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        $reviews->resolve(Payment::query()->findOrFail($payment), $data['action'], $request->user()->id, $data['note']);

        return redirect()->route('admin.finance.payments-review')->with('status', __('Payment resolved.'));
    }
}
