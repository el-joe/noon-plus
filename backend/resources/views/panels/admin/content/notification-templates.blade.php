<x-layouts.panel :title="__('Notification templates')" :subtitle="__(':country · per event, channel and language; the global text serves countries without their own', ['country' => $country->name_en])" :breadcrumbs="[__('Platform'), __('Notification templates')]">
    @include('panels.admin.content._nav')

    <x-ui.card>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <x-ui.select :label="__('Event')" name="event" :options="collect($events)->mapWithKeys(fn ($e, $key) => [$key => $e['label']])->all()" :selected="$event" />
            <x-ui.select :label="__('Channel')" name="channel" :options="array_combine(\App\Domains\Content\Support\NotificationEvents::CHANNELS, \App\Domains\Content\Support\NotificationEvents::CHANNELS)" :selected="$channel" />
            <x-ui.select :label="__('Language')" name="locale" :options="['en' => 'EN', 'ar' => 'AR']" :selected="$locale" />
            <x-ui.button type="submit" size="sm" variant="outline">{{ __('Open') }}</x-ui.button>
        </form>
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('Edit')" :subtitle="match ($scope) { 'country' => __('This country\'s text'), 'global' => __('Using the global text'), default => __('Using the built-in text') }" class="lg:col-span-2">
            <form method="POST" action="{{ route('admin.notification-templates.store') }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="event" value="{{ $event }}"><input type="hidden" name="channel" value="{{ $channel }}"><input type="hidden" name="locale" value="{{ $locale }}">
                <x-ui.input :label="__('Subject')" name="subject" :value="$text['subject']" :dir="$locale === 'ar' ? 'rtl' : 'ltr'" />
                <x-ui.textarea :label="__('Body')" name="body" :value="$text['body']" rows="6" :dir="$locale === 'ar' ? 'rtl' : 'ltr'" />
                <p class="text-xs text-fg-secondary">{{ __('Variables:') }} @foreach ($definition['variables'] as $variable)<code class="me-1">&#123;&#123; {{ $variable }} &#125;&#125;</code>@endforeach</p>
                <div class="flex flex-wrap items-end gap-4">
                    <x-ui.select :label="__('Save for')" name="scope" :options="['country' => $country->name_en, 'global' => __('All countries (global)')]" />
                    <input type="hidden" name="is_active" value="0">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($stored?->is_active ?? true) class="size-4 accent-brand"> {{ __('Send on this channel') }}</label>
                    <x-ui.button type="submit">{{ __('Save template') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Preview')">
            @if ($preview)
                <p class="font-medium" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">{{ $preview['subject'] }}</p>
                <p class="mt-2 text-sm whitespace-pre-line" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">{{ $preview['body'] }}</p>
            @else
                <p class="text-sm text-fg-secondary">{{ __('Switched off — nothing is sent.') }}</p>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card :title="__(':country overrides', ['country' => $country->name_en])" flush>
        <x-ui.table :headers="[__('Event'), __('Channel'), __('Language'), __('Active')]" :rows="$overrides->isNotEmpty()" :empty="__('This country uses the global texts.')">
            @foreach ($overrides as $override)
                <tr class="border-t border-line"><td class="px-5 py-3">{{ $override->event }}</td><td class="px-5 py-3">{{ $override->channel }}</td><td class="px-5 py-3 uppercase">{{ $override->locale }}</td><td class="px-5 py-3">{{ $override->is_active ? __('Yes') : __('No') }}</td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
