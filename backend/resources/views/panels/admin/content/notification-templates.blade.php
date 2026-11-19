<x-layouts.panel :title="__('Notification templates')" :subtitle="__(':country · per event, channel and language; the global text serves countries without their own', ['country' => $country->name_en])" :breadcrumbs="[__('Platform'), __('Notification templates')]">
    @include('panels.admin.content._nav')

    <x-ui.card>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="flex flex-col gap-1.5">
                <label for="event" class="text-sm font-medium text-fg">{{ __('Event') }}</label>
                <select id="event" name="event" class="h-10 max-w-md rounded-lg border border-line bg-surface px-3 text-sm text-fg">
                    @foreach ($grouped as $actor => $actorEvents)
                        <optgroup label="{{ __(\App\Shared\Notifications\Email\EmailCatalogue::ACTORS[$actor]) }}">
                            @foreach ($actorEvents as $key => $item)
                                <option value="{{ $key }}" @selected($key === $event)>{{ __($item['label']) }} ({{ $key }})</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                    <optgroup label="{{ __('Other') }}">
                        @foreach (array_diff_key($events, \App\Shared\Notifications\Email\EmailCatalogue::all()) as $key => $item)
                            <option value="{{ $key }}" @selected($key === $event)>{{ __($item['label']) }} ({{ $key }})</option>
                        @endforeach
                    </optgroup>
                </select>
            </div>
            <x-ui.select :label="__('Channel')" name="channel" :options="array_combine(\App\Domains\Content\Support\NotificationEvents::CHANNELS, \App\Domains\Content\Support\NotificationEvents::CHANNELS)" :selected="$channel" />
            <x-ui.select :label="__('Language')" name="locale" :options="['en' => 'EN', 'ar' => 'AR']" :selected="$locale" />
            <x-ui.button type="submit" size="sm" variant="outline">{{ __('Open') }}</x-ui.button>
        </form>
        @if ($catalogue)
            <dl class="mt-4 grid gap-2 text-xs sm:grid-cols-4">
                <div><dt class="text-fg-secondary">{{ __('Category') }}</dt><dd>{{ $catalogue['category']->label() }} @if ($catalogue['category']->isLocked())<x-ui.badge>{{ __('Always sent') }}</x-ui.badge>@endif</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Recipients') }}</dt><dd>{{ $catalogue['recipients'] }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Channels') }}</dt><dd>{{ implode(', ', $catalogue['channels']) }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Trigger') }}</dt><dd>{{ $catalogue['trigger'] }}</dd></div>
            </dl>
        @endif
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Edit')" :subtitle="match ($scope) { 'country' => __('This country\'s text'), 'global' => __('Using the global text'), default => __('Using the built-in text') }.($stored ? ' · v'.$stored->version : '')">
            <form method="POST" action="{{ route('admin.notification-templates.store') }}" class="flex flex-col gap-4" id="template-form">
                @csrf
                <input type="hidden" name="event" value="{{ $event }}"><input type="hidden" name="channel" value="{{ $channel }}"><input type="hidden" name="locale" value="{{ $locale }}">
                <x-ui.input :label="__('Subject')" name="subject" :value="$text['subject']" :dir="$locale === 'ar' ? 'rtl' : 'ltr'" />
                <x-ui.textarea :label="__('Body')" name="body" :value="$text['body']" rows="10" :dir="$locale === 'ar' ? 'rtl' : 'ltr'" />
                <p class="text-xs text-fg-secondary">{{ __('Variables:') }} @foreach ($definition['variables'] as $variable)<code class="me-1">&#123;&#123; {{ $variable }} &#125;&#125;</code>@endforeach · {{ __('A blank line starts a new paragraph.') }}</p>
                <div class="flex flex-wrap items-end gap-4">
                    <x-ui.select :label="__('Save for')" name="scope" :options="['country' => $country->name_en, 'global' => __('All countries (global)')]" />
                    <input type="hidden" name="is_active" value="0">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($stored?->is_active ?? true) class="size-4 accent-brand"> {{ __('Send on this channel') }}</label>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit">{{ __('Save template') }}</x-ui.button>
                    @if ($previewHtml !== null)
                        <x-ui.button type="submit" variant="outline" formaction="{{ route('admin.notification-templates.preview') }}" formtarget="email-preview">{{ __('Preview changes') }}</x-ui.button>
                        <x-ui.button type="submit" variant="outline" formaction="{{ route('admin.notification-templates.test') }}">{{ __('Send test to me') }}</x-ui.button>
                    @endif
                    @if ($stored)
                        <x-ui.button type="submit" variant="ghost" formaction="{{ route('admin.notification-templates.reset') }}" onclick="return confirm('{{ __('Reset this template to the default text?') }}')">{{ __('Reset to default') }}</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Preview')" :subtitle="__('With sample data')">
            @if ($previewHtml !== null)
                <iframe name="email-preview" title="{{ __('Email preview') }}" class="h-[640px] w-full rounded-lg border border-line bg-white" sandbox srcdoc="{{ $previewHtml }}"></iframe>
            @elseif ($preview)
                <p class="font-medium" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">{{ $preview['subject'] }}</p>
                <p class="mt-2 text-sm whitespace-pre-line" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">{{ $preview['body'] }}</p>
            @else
                <p class="text-sm text-fg-secondary">{{ __('Switched off — nothing is sent.') }}</p>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Version history')" :subtitle="__('Every save, restore and reset of this template.')" flush>
        <x-ui.table :headers="[__('Version'), __('Action'), __('Subject'), __('When'), __('By'), '']" :rows="$versions->isNotEmpty()" :empty="__('No saved versions yet — the built-in text is used.')">
            @foreach ($versions as $version)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">v{{ $version->version }}</td>
                    <td class="px-5 py-3"><x-ui.badge :tone="$version->action === 'reset' ? 'warning' : 'neutral'">{{ __(ucfirst($version->action)) }}</x-ui.badge></td>
                    <td class="px-5 py-3 text-xs" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">{{ $version->subject ?? '—' }}</td>
                    <td class="px-5 py-3 whitespace-nowrap text-xs">{{ $version->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $version->created_by ? \App\Models\User::query()->whereKey($version->created_by)->value('name') : '—' }}</td>
                    <td class="px-5 py-3 text-end">
                        @if ($version->body !== null && (! $stored || $stored->version !== $version->version))
                            <form method="POST" action="{{ route('admin.notification-templates.restore', $version) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Restore') }}</x-ui.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__(':country overrides', ['country' => $country->name_en])" flush>
        <x-ui.table :headers="[__('Event'), __('Channel'), __('Language'), __('Version'), __('Active')]" :rows="$overrides->isNotEmpty()" :empty="__('This country uses the global texts.')">
            @foreach ($overrides as $override)
                <tr class="border-t border-line"><td class="px-5 py-3"><a class="text-fg-brand" href="{{ route('admin.notification-templates', ['event' => $override->event, 'channel' => $override->channel, 'locale' => $override->locale]) }}">{{ $override->event }}</a></td><td class="px-5 py-3">{{ $override->channel }}</td><td class="px-5 py-3 uppercase">{{ $override->locale }}</td><td class="px-5 py-3">v{{ $override->version }}</td><td class="px-5 py-3">{{ $override->is_active ? __('Yes') : __('No') }}</td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
