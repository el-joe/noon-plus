<x-layouts.panel :title="__('Reviews & Q&A')" :subtitle="__('Customer content waiting for moderation in :country · times in Oman time', ['country' => $country->name_en])"
                 :breadcrumbs="[__('Commerce'), __('Reviews & Q&A')]">
    <nav class="flex flex-wrap items-center gap-4 border-b border-line text-sm" aria-label="{{ __('Queues') }}">
        @foreach (['reviews' => __('Reviews'), 'replies' => __('Seller replies'), 'questions' => __('Questions'), 'answers' => __('Seller answers')] as $key => $label)
            <a href="{{ route('admin.reviews', ['tab' => $key, 'status' => $status]) }}"
               @class(['pb-2', 'border-b-2 border-brand font-semibold' => $tab === $key, 'text-fg-secondary' => $tab !== $key])>{{ $label }} ({{ $pending[$key] }})</a>
        @endforeach
        <span class="ms-auto flex gap-2 pb-2">
            @foreach (array_keys($statuses) as $option)
                <a href="{{ route('admin.reviews', ['tab' => $tab, 'status' => $option]) }}"
                   @class(['rounded-full px-3 py-0.5 text-xs', 'bg-brand text-fg-on-brand' => $status === $option, 'bg-subtle' => $status !== $option])>{{ $option === 'queue' ? __('To moderate') : \App\Shared\Localization\Label::of($option) }}</a>
            @endforeach
        </span>
    </nav>

    @error('action')<div role="alert" class="rounded-lg bg-danger-subtle px-4 py-3 text-sm text-fg-danger">{{ $message }}</div>@enderror

    <x-ui.card flush>
        <x-ui.table :headers="[__('Content'), __('Product'), __('By'), __('Received (Oman)'), __('Decision')]" :rows="$items->isNotEmpty()" :empty="__('Nothing here.')">
            @foreach ($items as $item)
                @php($product = match ($tab) { 'answers' => $item->question?->product, 'replies' => $item->review?->product, default => $item->product })
                <tr class="border-t border-line align-top" data-{{ \Illuminate\Support\Str::singular($tab) }}="{{ $item->id }}">
                    <td class="max-w-md px-5 py-3">
                        @if ($tab === 'reviews')
                            <span class="font-medium">{{ str_repeat('★', $item->rating) }}{{ str_repeat('☆', 5 - $item->rating) }} {{ $item->title }}</span>
                            <span class="block text-sm">{{ $item->body }}</span>
                            <span class="mt-1 block"><x-ui.status-badge :status="$item->status" />
                                @if ($item->reports_count > 0)<span class="text-xs text-fg-secondary">{{ trans_choice(':count report|:count reports', $item->reports_count, ['count' => $item->reports_count]) }}</span>@endif</span>
                            @if ($item->flag_reason)
                                <span class="mt-1 block text-xs text-fg-warning">{{ __('Flagged: :reason', ['reason' => $item->flag_reason]) }}</span>
                            @endif
                            @if ($item->photos->isNotEmpty())
                                <span class="mt-2 flex gap-2">
                                    @foreach ($item->photos as $photo)
                                        <a href="{{ $photo->url() }}" target="_blank" rel="noopener"><img src="{{ $photo->url() }}" alt="{{ __('Review photo') }}" class="size-12 rounded object-cover"></a>
                                    @endforeach
                                </span>
                            @endif
                        @elseif ($tab === 'replies')
                            <span class="block text-xs text-fg-secondary">{{ __('Review: :review', ['review' => \Illuminate\Support\Str::limit((string) ($item->review?->title ?: $item->review?->body), 80)]) }}</span>
                            <span class="text-sm">{{ $item->body }}</span>
                        @elseif ($tab === 'questions')
                            <span class="text-sm">{{ $item->body }}</span>
                            @foreach ($item->answers as $answer)
                                <span class="mt-1 block text-xs text-fg-secondary">↳ {{ $answer->author_type === 'admin' ? 'Nawy' : $answer->vendor?->store_name }} ({{ \App\Shared\Localization\Label::of($answer->status) }}): {{ $answer->body }}</span>
                            @endforeach
                        @else
                            <span class="block text-xs text-fg-secondary">{{ __('Q: :question', ['question' => $item->question?->body]) }}</span>
                            <span class="text-sm">{{ $item->body }}</span>
                        @endif
                        @if ($item->rejection_reason)
                            <span class="mt-1 block text-xs text-fg-danger">{{ __('Rejected: :reason', ['reason' => $item->rejection_reason]) }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-sm">{{ $product?->title() }}</td>
                    <td class="px-5 py-3 text-sm">{{ in_array($tab, ['answers', 'replies'], true) ? ($item->vendor?->store_name ?? 'Nawy') : $item->customer_name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $clock->inAdminTime($item->created_at)->format('j M H:i') }}</td>
                    <td class="px-5 py-3">
                        <div class="flex flex-col gap-2">
                            @php($next = array_map(fn ($case) => $case->value, $item->status->allowedNext()))
                            @if (array_intersect($next, ['approved', 'published']))
                                <form method="POST" action="{{ route('admin.reviews.'.$tab.'.decide', $item->id) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
                                    <x-ui.button type="submit" size="sm">{{ $tab === 'reviews' || $tab === 'replies' ? __('Publish') : __('Approve') }}</x-ui.button>
                                </form>
                            @endif
                            @foreach (array_filter(['hide' => $tab === 'reviews' && in_array('hidden', $next, true), 'reject' => in_array('rejected', $next, true)]) as $decision => $allowed)
                                <form method="POST" action="{{ route('admin.reviews.'.$tab.'.decide', $item->id) }}" class="flex gap-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="{{ $decision }}">
                                    <label class="sr-only" for="{{ $decision }}-{{ $tab }}-{{ $item->id }}">{{ __('Reason') }}</label>
                                    <input id="{{ $decision }}-{{ $tab }}-{{ $item->id }}" name="reason" required minlength="3" placeholder="{{ __('Reason (required)') }}"
                                           class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                    <x-ui.button type="submit" size="sm" variant="outline">{{ $decision === 'hide' ? __('Hide') : __('Reject') }}</x-ui.button>
                                </form>
                            @endforeach
                            @if ($tab === 'questions' && $item->status !== \App\Domains\Reviews\Enums\ModerationStatus::Rejected)
                                <form method="POST" action="{{ route('admin.reviews.questions.answer', $item->id) }}" class="flex gap-2">
                                    @csrf
                                    <label class="sr-only" for="answer-{{ $item->id }}">{{ __('Answer as Nawy') }}</label>
                                    <input id="answer-{{ $item->id }}" name="body" required minlength="2" placeholder="{{ __('Answer as Nawy') }}"
                                           class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                    <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Answer') }}</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $items->links() }}
</x-layouts.panel>
