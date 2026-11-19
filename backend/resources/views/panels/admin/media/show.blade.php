@php($S = \App\Domains\LiveMedia\Enums\StreamStatus::class)
<x-layouts.panel :title="$stream->title_en" :subtitle="($stream->isRecorded() ? __('Recorded channel') : __('Live broadcast')).' · '.$stream->slug" :breadcrumbs="[__('Content'), __('Live & videos')]">
    @include('panels.admin.content._nav')
    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('Stream')" class="lg:col-span-2">
            <dl class="mb-4 grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$stream->status" /></dd></div>
                <div><dt class="text-fg-secondary">{{ __('Starts (Oman)') }}</dt><dd>{{ $stream->scheduled_at ? $clock->inAdminTime($stream->scheduled_at)->format('j M Y, H:i') : '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ $stream->isRecorded() ? __('Ends (Oman)') : __('Started / ended (Oman)') }}</dt>
                    <dd>{{ $stream->isRecorded() ? ($stream->scheduled_end_at ? $clock->inAdminTime($stream->scheduled_end_at)->format('j M Y, H:i') : '—') : (($stream->started_at ? $clock->inAdminTime($stream->started_at)->format('H:i') : '—').' / '.($stream->ended_at ? $clock->inAdminTime($stream->ended_at)->format('H:i') : '—')) }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Viewers now') }}</dt><dd>{{ $viewersNow }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Viewers (peak / total)') }}</dt><dd>{{ $stream->peak_viewers }} / {{ $stream->total_viewers }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Likes') }}</dt><dd>{{ $stream->likes_count }}</dd></div>
                @if ($stream->isRecorded())<div><dt class="text-fg-secondary">{{ __('Duration') }}</dt><dd>{{ gmdate('H:i:s', (int) $stream->duration_seconds) }} · {{ $stream->playback_mode }}</dd></div>@endif
            </dl>

            @can('live_media.manage')
                <form method="POST" action="{{ route('admin.media.update', $stream->id) }}" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
                    @csrf @method('PUT')
                    <x-ui.input :label="__('Title (English)')" name="title_en" :value="$stream->title_en" required />
                    <x-ui.input :label="__('Title (Arabic)')" name="title_ar" :value="$stream->title_ar" dir="rtl" required />
                    <x-ui.textarea :label="__('Description (English)')" name="description_en" :value="$stream->description_en" />
                    <x-ui.textarea :label="__('Description (Arabic)')" name="description_ar" :value="$stream->description_ar" dir="rtl" />
                    <x-ui.datetime-input :label="__('Scheduled for')" name="scheduled_at" :country="$country" :value="$stream->scheduled_at" />
                    @if ($stream->isRecorded())
                        <x-ui.select :label="__('Playback')" name="playback_mode" :options="['synced' => __('Synced'), 'free' => __('Free')]" :selected="$stream->playback_mode" />
                    @endif
                    <x-ui.input :label="__('Thumbnail')" name="thumbnail" type="file" accept="image/*" />
                    <div class="flex items-end"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
                </form>
            @endcan
        </x-ui.card>

        <x-ui.card :title="__('Comments')" :subtitle="__('Held comments matched the blocked words or a link; only shown comments are public.')" class="lg:col-span-2 lg:row-start-2">
            <ul class="flex flex-col divide-y divide-line text-sm">
                @forelse ($comments as $comment)
                    <li class="flex items-start justify-between gap-3 py-2">
                        <div>
                            <p><span class="font-medium">{{ $comment->author_name }}</span> · <span class="text-fg-secondary">{{ $clock->inAdminTime($comment->created_at)->format('j M H:i') }}</span>
                                @if ($comment->status->value !== 'visible')<x-ui.badge tone="warning">{{ \App\Shared\Localization\Label::of($comment->status) }}</x-ui.badge>@endif</p>
                            <p>{{ $comment->body }}</p>
                            @if ($comment->moderation_reason)<p class="text-xs text-fg-secondary">{{ $comment->moderation_reason }}</p>@endif
                        </div>
                        @can('live_media.manage')
                            <form method="POST" action="{{ route('admin.media.comments.moderate', [$stream->id, $comment->id]) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $comment->status->value === 'visible' ? 'hidden' : 'visible' }}">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ $comment->status->value === 'visible' ? __('Hide') : __('Show') }}</x-ui.button>
                            </form>
                        @endcan
                    </li>
                @empty
                    <li class="py-2 text-fg-secondary">{{ __('No comments yet.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>

        <div class="flex flex-col gap-6 lg:col-start-3 lg:row-start-1 lg:row-span-2">
            @can('live_media.manage')
                @if (! $stream->isRecorded())
                    <x-ui.card :title="__('Studio')" :subtitle="__('Publish from the browser studio with this room and token')">
                        <div class="flex flex-wrap gap-2">
                            @if (in_array($stream->status, [$S::Scheduled, $S::Idle], true))
                                <form method="POST" action="{{ route('admin.media.live', $stream->id) }}">@csrf<x-ui.button type="submit">{{ __('Go live') }}</x-ui.button></form>
                            @endif
                            @if ($stream->status === $S::Live)
                                <form method="POST" action="{{ route('admin.media.end', $stream->id) }}">@csrf<x-ui.button type="submit" variant="danger">{{ __('End stream') }}</x-ui.button></form>
                            @endif
                        </div>
                        @if ($studio)
                            <dl class="mt-3 flex flex-col gap-2 text-xs">
                                <div><dt class="text-fg-secondary">{{ __('Server') }}</dt><dd class="font-mono">{{ $studio['url'] }}</dd></div>
                                <div><dt class="text-fg-secondary">{{ __('Room') }}</dt><dd class="font-mono">{{ $studio['room'] }}</dd></div>
                                <div><dt class="text-fg-secondary">{{ __('Publisher token') }}</dt><dd><textarea readonly rows="3" class="w-full rounded border border-line p-1 font-mono text-[10px]">{{ $studio['token'] }}</textarea></dd></div>
                            </dl>
                        @endif
                    </x-ui.card>
                @endif

                <x-ui.card :title="__('Linked products')" :subtitle="__('Listing ids in display order')">
                    <ul class="mb-3 flex flex-col gap-1 text-sm">
                        @forelse ($stream->listings as $listing)
                            <li>#{{ $listing->id }} · {{ $listing->variant?->product?->title_en }}</li>
                        @empty
                            <li class="text-fg-secondary">{{ __('None yet.') }}</li>
                        @endforelse
                    </ul>
                    <form method="POST" action="{{ route('admin.media.products', $stream->id) }}" class="flex flex-col gap-2">
                        @csrf
                        <x-ui.input :label="__('Listing ids')" name="listing_ids" :value="$stream->listings->pluck('id')->implode(', ')" />
                        <x-ui.button type="submit" size="sm">{{ __('Save products') }}</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan

            <x-ui.state-timeline :title="__('History')" :steps="$history->map(fn ($row) => ['state' => 'done', 'label' => ucfirst($row->to_status), 'meta' => ucfirst((string) $row->actor_type).($row->actor_user_id ? ' '.app(\App\Shared\Support\UserNames::class)->name($row->actor_user_id) : '').' · '.$clock->inAdminTime($row->created_at)->format('j M H:i')])->all()" />
        </div>
    </div>
</x-layouts.panel>
