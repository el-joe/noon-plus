<x-layouts.panel :title="__('Leads pipeline')" :subtitle="__(':open open leads · :due follow-ups due today', ['open' => collect($columns)->except('closed')->flatten()->count(), 'due' => $dueToday->count()])" :breadcrumbs="[__('Leads'), __('Pipeline')]">
    <div class="grid gap-4 lg:grid-cols-5">
        @foreach (['new' => __('New'), 'contacted' => __('Contacted'), 'viewing_scheduled' => __('Viewing'), 'negotiating' => __('Offer'), 'closed' => __('Won / lost')] as $key => $label)
            <section class="flex flex-col gap-3 rounded-xl bg-subtle p-3" aria-label="{{ $label }}">
                <h2 class="flex items-center justify-between text-sm font-semibold">{{ $label }} <x-ui.badge>{{ $columns[$key]->count() }}</x-ui.badge></h2>
                @foreach ($columns[$key] as $lead)
                    <article class="grid gap-2 rounded-lg border border-line bg-surface p-3 text-sm" data-lead="{{ $lead->id }}">
                        <span class="font-semibold">{{ $lead->name }}</span>
                        <span class="text-xs text-fg-secondary">{{ str_replace('_', ' ', $lead->source) }}@if ($lead->ad) · {{ $lead->ad->title }}@endif</span>
                        @if ($lead->budget)<span class="text-xs">{{ __('Budget') }} {{ number_format((float) $lead->budget) }}</span>@endif
                        @if ($lead->next_follow_up_at)<x-ui.badge tone="warning">{{ __('Follow up :at', ['at' => $lead->next_follow_up_at->format('D j M H:i')]) }}</x-ui.badge>@endif
                        @if ($lead->lost_reason)<span class="text-xs text-fg-danger">{{ __('Reason: :r', ['r' => $lead->lost_reason]) }}</span>@endif
                        @if ($lead->status->isOpen())
                            <form method="POST" action="{{ route('marketer.broker.leads.move', $lead->id) }}" class="flex flex-wrap gap-1">
                                @csrf
                                <select name="status" class="h-8 rounded-lg border border-line bg-surface px-1 text-xs" aria-label="{{ __('Move to') }}">
                                    @foreach ($lead->status->allowedNext() as $next)<option value="{{ $next->value }}">{{ str_replace('_', ' ', ucfirst($next->value)) }}</option>@endforeach
                                </select>
                                <input name="reason" placeholder="{{ __('Reason if lost') }}" class="h-8 w-24 rounded-lg border border-line bg-surface px-1 text-xs" aria-label="{{ __('Reason') }}">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Move') }}</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('marketer.broker.leads.follow-up', $lead->id) }}" class="flex flex-wrap gap-1">
                                @csrf
                                <select name="channel" class="h-8 rounded-lg border border-line bg-surface px-1 text-xs" aria-label="{{ __('Channel') }}"><option value="call">{{ __('Call') }}</option><option value="whatsapp">WhatsApp</option><option value="meeting">{{ __('Meeting') }}</option><option value="email">{{ __('Email') }}</option></select>
                                <input type="datetime-local" name="due_at" required class="h-8 rounded-lg border border-line bg-surface px-1 text-xs" aria-label="{{ __('Due') }}">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Follow-up') }}</x-ui.button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </section>
        @endforeach
    </div>

    <x-ui.card :title="__('Follow-ups due today (:n)', ['n' => $dueToday->count()])">
        <ul class="grid gap-2 text-sm">
            @forelse ($dueToday as $followUp)
                <li class="flex items-center justify-between gap-3">
                    <span>{{ $followUp->due_at->format('H:i') }} · {{ ucfirst($followUp->channel) }} {{ $followUp->lead->name }} @if ($followUp->note)— {{ $followUp->note }}@endif</span>
                    <form method="POST" action="{{ route('marketer.broker.follow-ups.done', $followUp->id) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Done') }}</x-ui.button></form>
                </li>
            @empty
                <li class="text-fg-secondary">{{ __('Nothing due today.') }}</li>
            @endforelse
        </ul>
    </x-ui.card>

    <x-ui.card :title="__('Add lead')">
        <form method="POST" action="{{ route('marketer.broker.leads.store') }}" class="grid gap-3 sm:grid-cols-5">
            @csrf
            <x-ui.input :label="__('Name')" name="name" required />
            <x-ui.input :label="__('Phone')" name="phone" />
            <x-ui.input :label="__('Email')" name="email" type="email" />
            <x-ui.input :label="__('Budget')" name="budget" type="number" step="any" min="0" />
            <div class="flex items-end"><x-ui.button type="submit">{{ __('Add lead') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
