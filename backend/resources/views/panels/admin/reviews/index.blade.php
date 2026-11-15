<x-layouts.panel :title="__('Reviews & Q&A')" :subtitle="__('Customer content waiting for moderation in :country · times in Oman time', ['country' => $country->name_en])"
                 :breadcrumbs="[__('Commerce'), __('Reviews & Q&A')]">
    <nav class="flex flex-wrap items-center gap-4 border-b border-line text-sm" aria-label="{{ __('Queues') }}">
        @foreach (['reviews' => __('Reviews'), 'questions' => __('Questions'), 'answers' => __('Seller answers')] as $key => $label)
            <a href="{{ route('admin.reviews', ['tab' => $key, 'status' => $status]) }}"
               @class(['pb-2', 'border-b-2 border-brand font-semibold' => $tab === $key, 'text-fg-secondary' => $tab !== $key])>{{ $label }} ({{ $pending[$key] }})</a>
        @endforeach
        <span class="ms-auto flex gap-2 pb-2">
            @foreach (\App\Domains\Reviews\Enums\ModerationStatus::cases() as $option)
                <a href="{{ route('admin.reviews', ['tab' => $tab, 'status' => $option->value]) }}"
                   @class(['rounded-full px-3 py-0.5 text-xs', 'bg-brand text-fg-on-brand' => $status === $option->value, 'bg-subtle' => $status !== $option->value])>{{ ucfirst($option->value) }}</a>
            @endforeach
        </span>
    </nav>

    @error('action')<div role="alert" class="rounded-lg bg-danger-subtle px-4 py-3 text-sm text-fg-danger">{{ $message }}</div>@enderror
    @error('reason')<p class="text-sm text-fg-danger">{{ $message }}</p>@enderror

    <x-ui.card flush>
        <x-ui.table :headers="[__('Content'), __('Product'), __('By'), __('Received (Oman)'), __('Decision')]" :rows="$items->isNotEmpty()" :empty="__('Nothing here.')">
            @foreach ($items as $item)
                @php($product = $tab === 'answers' ? $item->question?->product : $item->product)
                <tr class="border-t border-line align-top" data-{{ \Illuminate\Support\Str::singular($tab) }}="{{ $item->id }}">
                    <td class="max-w-md px-5 py-3">
                        @if ($tab === 'reviews')
                            <span class="font-medium">{{ str_repeat('★', $item->rating) }}{{ str_repeat('☆', 5 - $item->rating) }} {{ $item->title }}</span>
                            <span class="block text-sm">{{ $item->body }}</span>
                            @if ($item->photos->isNotEmpty())
                                <span class="mt-2 flex gap-2">
                                    @foreach ($item->photos as $photo)
                                        <a href="{{ $photo->url() }}" target="_blank" rel="noopener"><img src="{{ $photo->url() }}" alt="{{ __('Review photo') }}" class="size-12 rounded object-cover"></a>
                                    @endforeach
                                </span>
                            @endif
                        @elseif ($tab === 'questions')
                            <span class="text-sm">{{ $item->body }}</span>
                            @foreach ($item->answers as $answer)
                                <span class="mt-1 block text-xs text-fg-secondary">↳ {{ $answer->author_type === 'admin' ? 'Nawy' : $answer->vendor?->store_name }} ({{ $answer->status->value }}): {{ $answer->body }}</span>
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
                    <td class="px-5 py-3 text-sm">{{ $tab === 'answers' ? ($item->vendor?->store_name ?? 'Nawy') : $item->customer_name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $clock->inAdminTime($item->created_at)->format('j M H:i') }}</td>
                    <td class="px-5 py-3">
                        <div class="flex flex-col gap-2">
                            @if ($item->status !== \App\Domains\Reviews\Enums\ModerationStatus::Approved)
                                <form method="POST" action="{{ route('admin.reviews.'.$tab.'.decide', $item->id) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
                                    <x-ui.button type="submit" size="sm">{{ __('Approve') }}</x-ui.button>
                                </form>
                            @endif
                            @if ($item->status !== \App\Domains\Reviews\Enums\ModerationStatus::Rejected)
                                <form method="POST" action="{{ route('admin.reviews.'.$tab.'.decide', $item->id) }}" class="flex gap-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="reject">
                                    <label class="sr-only" for="reason-{{ $tab }}-{{ $item->id }}">{{ __('Reject reason') }}</label>
                                    <input id="reason-{{ $tab }}-{{ $item->id }}" name="reason" required minlength="3" placeholder="{{ __('Reason (required)') }}"
                                           class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                    <x-ui.button type="submit" size="sm" variant="outline">{{ $item->status === \App\Domains\Reviews\Enums\ModerationStatus::Approved ? __('Hide') : __('Reject') }}</x-ui.button>
                                </form>
                            @endif
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
