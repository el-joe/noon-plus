<x-layouts.panel :title="__('Live & videos')" :subtitle="__(':country · admin-only live broadcasts and scheduled audio/video channels (ADR 11) · times typed in :zone', ['country' => $country->name_en, 'zone' => $country->timezone])" :breadcrumbs="[__('Content'), __('Live & videos')]">
    @include('panels.admin.content._nav')
    @can('live_media.manage')
        {{-- QC-063: the home floating player is a setting, not a page block. --}}
        <x-ui.card :title="__('Home floating player')" :subtitle="__('Shown over the storefront home page in :country.', ['country' => $country->name_en])">
            <form method="POST" action="{{ route('admin.media.floating') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <x-ui.select :label="__('Show')" name="mode" :options="['auto' => __('The stream that is live now'), 'stream' => __('One stream'), 'off' => __('Nothing')]" :selected="$floating['mode']" />
                <x-ui.input :label="__('Stream address (slug)')" name="stream" :value="$floating['stream']" />
                <x-ui.button type="submit" size="sm">{{ __('Save') }}</x-ui.button>
            </form>
        </x-ui.card>
        {{-- D18: live broadcasts are direct WebRTC connections from the host's browser; cap them per stream. --}}
        <x-ui.card :title="__('Live viewers per broadcast')" :subtitle="__('Each viewer connects directly to the host browser, so the host upload limits the audience. Beyond the cap viewers see “full, try again”.')">
            <form method="POST" action="{{ route('admin.media.webrtc.settings') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <x-ui.input :label="__('Most viewers at once')" name="max_viewers" type="number" min="1" max="200" :value="$maxViewers" />
                <x-ui.button type="submit" size="sm">{{ __('Save') }}</x-ui.button>
            </form>
        </x-ui.card>
    @endcan
    <x-ui.card flush>
        <x-slot:actions>
            <form method="GET" class="flex items-end gap-2">
                <x-ui.select name="source" :options="['live' => __('Live broadcasts'), 'recorded' => __('Recorded channels')]" :selected="$filters['source'] ?? null" :placeholder="__('All kinds')" />
                <x-ui.select name="status" :options="['scheduled' => __('Scheduled'), 'idle' => __('Idle'), 'live' => __('Live'), 'ended' => __('Ended')]" :selected="$filters['status'] ?? null" :placeholder="__('All statuses')" />
                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Filter') }}</x-ui.button>
            </form>
        </x-slot:actions>
        <x-ui.table :headers="[__('Stream'), __('Kind'), __('Starts (Oman)'), __('Products'), __('Likes'), __('Status')]" :rows="$streams->isNotEmpty()" :empty="__('No streams yet.')">
            @foreach ($streams as $stream)
                <tr class="border-t border-line" data-stream="{{ $stream->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.media.show', $stream->id) }}">{{ $stream->title_en }}</a><span class="block text-xs text-fg-secondary" dir="rtl">{{ $stream->title_ar }}</span></td>
                    <td class="px-5 py-3">{{ $stream->isRecorded() ? __('Recorded channel') : __('Live · :mode', ['mode' => $stream->media_mode]) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $stream->scheduled_at ? $clock->inAdminTime($stream->scheduled_at)->format('j M Y, H:i') : '—' }}</td>
                    <td class="px-5 py-3">{{ $stream->listings_count }}</td>
                    <td class="px-5 py-3">{{ $stream->likes_count }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$stream->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $streams->links() }}

    @can('live_media.manage')
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card :title="__('New live broadcast')" :subtitle="__('Broadcast from the browser studio (WebRTC); leave the time empty to go live when ready')">
                <form method="POST" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" class="flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="source" value="live">
                    <x-ui.input :label="__('Title (English)')" name="title_en" id="live_title_en" required />
                    <x-ui.input :label="__('Title (Arabic)')" name="title_ar" id="live_title_ar" dir="rtl" required />
                    <x-ui.textarea :label="__('Description (English)')" name="description_en" id="live_description_en" />
                    <x-ui.select :label="__('Media')" name="media_mode" :options="['video' => __('Video'), 'audio' => __('Audio'), 'both' => __('Audio + video')]" />
                    <x-ui.datetime-input :label="__('Scheduled for')" name="scheduled_at" id="live_scheduled_at" :country="$country" />
                    <x-ui.input :label="__('Thumbnail')" name="thumbnail" id="live_thumbnail" type="file" accept="image/*" />
                    <x-ui.button type="submit">{{ __('Create broadcast') }}</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card :title="__('New recorded channel')" :subtitle="__('Upload :formats up to :mb MB; plays on its schedule like radio', ['formats' => implode(', ', $formats), 'mb' => config('nawy.streams.recorded_max_mb')])">
                <form method="POST" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" class="flex flex-col gap-4" data-recorded-form>
                    @csrf
                    <input type="hidden" name="source" value="recorded">
                    <x-ui.input :label="__('Title (English)')" name="title_en" id="rec_title_en" required />
                    <x-ui.input :label="__('Title (Arabic)')" name="title_ar" id="rec_title_ar" dir="rtl" required />
                    <x-ui.datetime-input :label="__('Starts at')" name="scheduled_at" id="rec_scheduled_at" :country="$country" required />
                    <x-ui.select :label="__('Playback')" name="playback_mode" :options="['synced' => __('Synced (everyone hears the same moment)'), 'free' => __('Free (each viewer from the start)')]" />
                    <x-ui.input :label="__('Media file')" name="media" type="file" required data-media-file />
                    <input type="hidden" name="duration_seconds" data-media-duration>
                    <x-ui.input :label="__('Thumbnail')" name="thumbnail" id="rec_thumbnail" type="file" accept="image/*" />
                    <x-ui.button type="submit">{{ __('Upload channel') }}</x-ui.button>
                </form>
            </x-ui.card>
        </div>
        <script>
            // FR-LS-7: the duration measured by the browser is the fallback when ffprobe is unavailable.
            document.querySelectorAll('[data-recorded-form]').forEach((form) => {
                form.querySelector('[data-media-file]').addEventListener('change', (event) => {
                    const file = event.target.files[0];
                    if (!file) return;
                    const media = document.createElement(file.type.startsWith('audio') ? 'audio' : 'video');
                    media.preload = 'metadata';
                    media.onloadedmetadata = () => { form.querySelector('[data-media-duration]').value = Math.ceil(media.duration) || ''; URL.revokeObjectURL(media.src); };
                    media.src = URL.createObjectURL(file);
                });
            });
        </script>
    @endcan
</x-layouts.panel>
