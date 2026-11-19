<x-layouts.panel :title="__('Reviews')" :subtitle="__('What customers say about your store, and their questions on your products')"
                 :breadcrumbs="[__('Growth'), __('Reviews')]">
    @error('action')<div role="alert" class="rounded-lg bg-danger-subtle px-4 py-3 text-sm text-fg-danger">{{ $message }}</div>@enderror

    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card :title="__('Store rating')" :subtitle="trans_choice(':count published review|:count published reviews', $summary['count'], ['count' => $summary['count']])">
            <p class="text-3xl font-semibold">{{ $summary['average'] ?? '—' }} <span class="text-base text-fg-secondary">/ 5</span></p>
            <ul class="mt-4 flex flex-col gap-1 text-sm">
                @foreach ($summary['histogram'] as $stars => $total)
                    <li class="flex justify-between"><span>{{ str_repeat('★', $stars) }}</span><span>{{ $total }}</span></li>
                @endforeach
            </ul>
        </x-ui.card>

        <div class="flex flex-col gap-6 xl:col-span-2">
            <x-ui.card :title="__('Customer questions')" :subtitle="__('Answer questions on products you sell; Nawy reviews answers before they show.')" flush>
                <x-ui.table :headers="[__('Product'), __('Question'), __('Your answer')]" :rows="$questions->isNotEmpty()" :empty="__('No questions yet.')">
                    @foreach ($questions as $question)
                        <tr class="border-t border-line align-top" data-question="{{ $question->id }}">
                            <td class="px-5 py-3 text-sm">{{ $question->product?->title() }}</td>
                            <td class="px-5 py-3 text-sm">{{ $question->body }}<span class="block text-xs text-fg-secondary">{{ $question->customer_name }}</span></td>
                            <td class="px-5 py-3 text-sm">
                                @forelse ($question->answers as $answer)
                                    <span class="block">{{ $answer->body }} <x-ui.status-badge :status="$answer->status" /></span>
                                @empty
                                    <form method="POST" action="{{ route('partner.reviews.questions.answer', $question->id) }}" class="flex gap-2">
                                        @csrf
                                        <label class="sr-only" for="answer-{{ $question->id }}">{{ __('Your answer') }}</label>
                                        <input id="answer-{{ $question->id }}" name="body" required minlength="2" class="h-8 flex-1 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <x-ui.button type="submit" size="sm">{{ __('Answer') }}</x-ui.button>
                                    </form>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
            {{ $questions->links() }}

            <x-ui.card :title="__('Published reviews')" flush>
                <x-ui.table :headers="[__('Product'), __('Rating'), __('Review'), __('Your reply')]" :rows="$reviews->isNotEmpty()" :empty="__('No published reviews yet.')">
                    @foreach ($reviews as $review)
                        <tr class="border-t border-line align-top" data-review="{{ $review->id }}">
                            <td class="px-5 py-3 text-sm">{{ $review->product?->title() }}</td>
                            <td class="px-5 py-3 text-sm">{{ str_repeat('★', $review->rating) }}</td>
                            <td class="px-5 py-3 text-sm"><span class="font-medium">{{ $review->title }}</span> {{ $review->body }}<span class="block text-xs text-fg-secondary">{{ $review->customer_name }}</span>
                                <form method="POST" action="{{ route('partner.reviews.report', $review->id) }}" class="mt-2 flex gap-2">
                                    @csrf
                                    <label class="sr-only" for="report-{{ $review->id }}">{{ __('Report reason') }}</label>
                                    <select id="report-{{ $review->id }}" name="reason" class="h-8 rounded-lg border border-line bg-surface px-2 text-xs">
                                        @foreach (\App\Domains\Reviews\Models\ProductReviewReport::REASONS as $reason)
                                            <option value="{{ $reason }}">{{ __(\Illuminate\Support\Str::headline($reason)) }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Report') }}</x-ui.button>
                                </form>
                            </td>
                            <td class="px-5 py-3 text-sm">
                                @if ($review->reply && $review->reply->status !== \App\Domains\Reviews\Enums\ReplyStatus::Rejected)
                                    <span class="block">{{ $review->reply->body }} <x-ui.status-badge :status="$review->reply->status" /></span>
                                @else
                                    @if ($review->reply)
                                        <span class="block text-xs text-fg-danger">{{ __('Rejected: :reason', ['reason' => $review->reply->rejection_reason]) }}</span>
                                    @endif
                                    <form method="POST" action="{{ route('partner.reviews.reply', $review->id) }}" class="flex gap-2">
                                        @csrf
                                        <label class="sr-only" for="reply-{{ $review->id }}">{{ __('Your reply') }}</label>
                                        <input id="reply-{{ $review->id }}" name="body" required minlength="2" maxlength="1000" class="h-8 flex-1 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <x-ui.button type="submit" size="sm">{{ __('Reply') }}</x-ui.button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
            {{ $reviews->links() }}
        </div>
    </div>
</x-layouts.panel>
