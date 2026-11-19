@php($label = fn ($value) => \App\Shared\Localization\Label::of($value))
@php($S = \App\Domains\Support\Enums\TicketStatus::class)
@php($tz = config('nawy.admin_timezone'))
@php($requester = $people[$ticket->requester_user_id] ?? null)
<x-layouts.panel :title="__('Ticket :number', ['number' => $ticket->number])" :subtitle="$ticket->subject" :breadcrumbs="[__('Support'), __('Tickets'), $ticket->number]">
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <section class="grid gap-4 sm:grid-cols-4">
                <x-ui.stat-card :label="__('Status')" :value="$label($ticket->status)" />
                <x-ui.stat-card :label="__('Priority')" :value="$label($ticket->priority)" />
                <x-ui.stat-card :label="__('Category')" :value="$label($ticket->category)" />
                <x-ui.stat-card :label="__('Rating')" :value="$ticket->rating === null ? '—' : $ticket->rating.' / 5'" />
            </section>

            <x-ui.card :title="__('Conversation')">
                <ol class="flex flex-col gap-3">
                    @foreach ($ticket->messages as $message)
                        <li @class(['rounded-lg border p-3', 'border-line' => ! $message->is_internal, 'border-line-danger bg-subtle' => $message->is_internal]) data-ticket-message="{{ $message->id }}" @if ($message->is_internal) data-internal @endif>
                            <p class="text-xs text-fg-secondary">
                                {{ $people[$message->author_user_id]->name ?? $label($message->author_type) }} · {{ $label($message->author_type) }} · {{ $message->created_at->setTimezone($tz)->format('j M Y, H:i') }}
                                @if ($message->is_internal) · <strong>{{ __('Internal note') }}</strong> @endif
                            </p>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ $message->body }}</p>
                            @foreach (app(\App\Domains\Support\Support\TicketAttachments::class)->present($message) as $file)
                                <a class="mt-1 block text-xs text-fg-link" href="{{ $file['url'] }}" target="_blank" rel="noopener">{{ $file['name'] }}</a>
                            @endforeach
                        </li>
                    @endforeach
                </ol>
                @if ($canManage && $ticket->status !== $S::Closed)
                    <form method="POST" action="{{ route('admin.support.reply', $ticket->id) }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-2">
                        @csrf
                        <x-ui.textarea :label="__('Reply')" name="body" rows="4" required />
                        <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="text-sm">
                        <x-ui.checkbox :label="__('Internal note (support staff only)')" name="internal" />
                        <x-ui.checkbox :label="__('Ask the requester for information (waiting on customer)')" name="wait" />
                        <div><x-ui.button type="submit">{{ __('Send') }}</x-ui.button></div>
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('History')">
                <ol class="flex flex-col gap-1 text-sm">
                    @foreach ($ticket->transitions as $transition)
                        <li>{{ $transition->created_at->setTimezone($tz)->format('j M Y, H:i') }} · {{ $transition->from_status ? $label($transition->from_status).' → ' : '' }}{{ $label($transition->to_status) }} · {{ $label($transition->actor_type) }}@if ($transition->reason) · {{ $transition->reason }}@endif</li>
                    @endforeach
                </ol>
            </x-ui.card>
        </div>

        <div class="flex flex-col gap-6">
            <x-ui.card :title="__('Details')">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-fg-secondary">{{ __('Requester') }}</dt><dd>{{ $requester?->name }}<br><span class="text-xs">{{ $requester?->email }}</span></dd>
                    <dt class="text-fg-secondary">{{ __('Type') }}</dt><dd>{{ $label($ticket->requester_type) }}@if ($tenant) · {{ $tenant }}@endif</dd>
                    <dt class="text-fg-secondary">{{ __('Order') }}</dt><dd>{{ $ticket->order?->number ?? '—' }}</dd>
                    <dt class="text-fg-secondary">{{ __('Shipment') }}</dt><dd>{{ $ticket->subOrder?->number ?? '—' }}</dd>
                    <dt class="text-fg-secondary">{{ __('First reply due') }}</dt><dd>{{ $ticket->first_response_due_at?->setTimezone($tz)->format('j M Y, H:i') }} {{ __('Oman') }}</dd>
                    <dt class="text-fg-secondary">{{ __('First replied') }}</dt><dd>{{ $ticket->first_responded_at?->setTimezone($tz)->format('j M Y, H:i') ?? '—' }}</dd>
                    <dt class="text-fg-secondary">{{ __('Resolution due') }}</dt><dd>{{ $ticket->resolution_due_at?->setTimezone($tz)->format('j M Y, H:i') }} {{ __('Oman') }}</dd>
                    @if ($ticket->isOverdue())
                        <dt class="text-fg-secondary">{{ __('SLA') }}</dt><dd><x-ui.badge tone="danger">{{ __('Overdue') }}</x-ui.badge></dd>
                    @endif
                    @if ($ticket->rating_comment)
                        <dt class="text-fg-secondary">{{ __('Rating comment') }}</dt><dd>{{ $ticket->rating_comment }}</dd>
                    @endif
                </dl>
            </x-ui.card>

            @if ($canManage)
                <x-ui.card :title="__('Actions')">
                    <div class="flex flex-col gap-4">
                        <form method="POST" action="{{ route('admin.support.assign', $ticket->id) }}" class="flex flex-col gap-2">
                            @csrf
                            <x-ui.select :label="__('Assignee')" name="assignee" :selected="(string) $ticket->assigned_admin_id" :placeholder="__('Unassigned')"
                                         :options="$admins->mapWithKeys(fn ($admin) => [(string) $admin->id => $admin->name])->all()" />
                            <div><x-ui.button type="submit" variant="outline">{{ __('Assign') }}</x-ui.button></div>
                        </form>
                        <form method="POST" action="{{ route('admin.support.priority', $ticket->id) }}" class="flex flex-col gap-2">
                            @csrf
                            <x-ui.select :label="__('Priority')" name="priority" :selected="$ticket->priority->value"
                                         :options="collect(\App\Domains\Support\Enums\TicketPriority::cases())->mapWithKeys(fn ($case) => [$case->value => $label($case)])->all()" />
                            <div><x-ui.button type="submit" variant="outline">{{ __('Change priority') }}</x-ui.button></div>
                        </form>
                        @foreach ([$S::InProgress, $S::WaitingCustomer, $S::Resolved, $S::Closed] as $next)
                            @if (in_array($next, $ticket->status->allowedNext(), true))
                                <form method="POST" action="{{ route('admin.support.status', $ticket->id) }}">
                                    @csrf
                                    <input type="hidden" name="status" value="{{ $next->value }}">
                                    <x-ui.button type="submit" :variant="$next === $S::Resolved ? 'primary' : 'outline'">{{ __('Move to :status', ['status' => $label($next)]) }}</x-ui.button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>
</x-layouts.panel>
