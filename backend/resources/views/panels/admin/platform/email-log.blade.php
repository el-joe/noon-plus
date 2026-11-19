@php
    $tones = ['queued' => 'info', 'sent' => 'success', 'failed' => 'danger', 'bounced' => 'danger', 'complained' => 'danger', 'suppressed' => 'warning', 'skipped' => 'neutral'];
    $canManage = auth()->user()->can('system_health.manage');
@endphp
<x-layouts.panel :title="__('Email log')" :subtitle="__('Every email the platform sends, with delivery status. Addresses are masked.')" :breadcrumbs="[__('Platform'), __('Email log')]">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
        @foreach (\App\Shared\Notifications\Email\Models\EmailLog::STATUSES as $status)
            <x-ui.stat-card :label="__(ucfirst($status)).' · 24h'" :value="(string) ($counts[$status] ?? 0)" />
        @endforeach
    </div>

    <x-ui.card>
        <form method="GET" class="grid gap-3 sm:grid-cols-3 lg:grid-cols-7 items-end">
            <x-ui.select :label="__('Event')" name="event" :use-old="false" :placeholder="__('All events')" :options="collect($events)->mapWithKeys(fn ($e, $key) => [$key => $key.' — '.$e['label']])->all()" :selected="$filters['event'] ?? null" />
            <x-ui.select :label="__('Status')" name="status" :use-old="false" :placeholder="__('Any')" :options="array_combine(\App\Shared\Notifications\Email\Models\EmailLog::STATUSES, array_map(fn ($s) => __(ucfirst($s)), \App\Shared\Notifications\Email\Models\EmailLog::STATUSES))" :selected="$filters['status'] ?? null" />
            <x-ui.select :label="__('Recipient type')" name="actor" :use-old="false" :placeholder="__('Any')" :options="array_map(fn ($label) => __($label), \App\Shared\Notifications\Email\EmailCatalogue::ACTORS)" :selected="$filters['actor'] ?? null" />
            <x-ui.select :label="__('Category')" name="category" :use-old="false" :placeholder="__('Any')" :options="collect(\App\Shared\Notifications\Email\EmailCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" :selected="$filters['category'] ?? null" />
            <x-ui.input :label="__('Recipient email')" name="recipient" type="email" :value="$filters['recipient'] ?? null" />
            <x-ui.input :label="__('From')" name="from" type="date" :value="$filters['from'] ?? null" />
            <div class="flex gap-2">
                <x-ui.input :label="__('To')" name="to" type="date" :value="$filters['to'] ?? null" />
            </div>
            <div class="flex gap-2"><x-ui.button type="submit" size="sm">{{ __('Filter') }}</x-ui.button><x-ui.button :href="route('admin.email-log')" size="sm" variant="outline">{{ __('Reset') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card flush>
        <x-ui.table :headers="[__('When'), __('Event'), __('Recipient'), __('Language'), __('Country'), __('Status'), __('Template'), __('Details')]" :rows="$logs->isNotEmpty()" :empty="__('No emails match these filters.')">
            @foreach ($logs as $log)
                <tr class="border-t border-line align-top">
                    <td class="px-5 py-3 whitespace-nowrap">{{ $log->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3"><code class="text-xs">{{ $log->event }}</code><p class="text-xs text-fg-secondary">{{ __($events[$log->event]['label'] ?? '') }}</p></td>
                    <td class="px-5 py-3 font-mono text-xs">{{ $log->recipient_masked }}</td>
                    <td class="px-5 py-3 uppercase">{{ $log->locale }}</td>
                    <td class="px-5 py-3 uppercase">{{ $log->country_code ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.badge :tone="$tones[$log->status] ?? 'neutral'">{{ __(ucfirst($log->status)) }}</x-ui.badge></td>
                    <td class="px-5 py-3 text-xs">{{ $log->template_version === null ? '—' : ($log->template_version === 0 ? __('Built-in') : 'v'.$log->template_version) }}</td>
                    <td class="px-5 py-3 text-xs">
                        <details>
                            <summary class="cursor-pointer text-fg-brand">{{ __('Open') }}</summary>
                            <dl class="mt-2 grid gap-1">
                                <div><dt class="inline text-fg-secondary">{{ __('Subject') }}:</dt> <dd class="inline">{{ $log->subject ?? '—' }}</dd></div>
                                <div><dt class="inline text-fg-secondary">{{ __('About') }}:</dt> <dd class="inline">{{ $log->entity_type ? $log->entity_type.' #'.$log->entity_id : '—' }}</dd></div>
                                <div><dt class="inline text-fg-secondary">{{ __('Attempts') }}:</dt> <dd class="inline">{{ $log->attempts }}</dd></div>
                                <div><dt class="inline text-fg-secondary">{{ __('Provider message id') }}:</dt> <dd class="inline font-mono break-all">{{ $log->provider_message_id ?? '—' }}</dd></div>
                                <div><dt class="inline text-fg-secondary">{{ __('Sent') }}:</dt> <dd class="inline">{{ $log->sent_at?->setTimezone(config('nawy.admin_timezone'))->format('j M, H:i') ?? '—' }}</dd></div>
                                @if ($log->failure_reason)<div><dt class="inline text-fg-secondary">{{ __('Reason') }}:</dt> <dd class="inline text-fg-danger">{{ $log->failure_reason }}</dd></div>@endif
                                @if ($log->resent_from_id)<div><dt class="inline text-fg-secondary">{{ __('Resent from') }}:</dt> <dd class="inline">#{{ $log->resent_from_id }}</dd></div>@endif
                            </dl>
                            @if ($canManage && $log->canResend())
                                <form method="POST" action="{{ route('admin.email-log.resend', $log) }}" class="mt-2">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Resend') }}</x-ui.button></form>
                            @elseif ($log->payload === null)
                                <p class="mt-2 text-fg-secondary">{{ __('Sensitive email (codes, links): cannot be resent.') }}</p>
                            @endif
                        </details>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="px-5 py-3">{{ $logs->links() }}</div>
    </x-ui.card>

    <x-ui.card :title="__('Suppression list')" :subtitle="__('Hard bounces and complaints from the provider webhook, guest unsubscribes and manual blocks.')" flush>
        @if ($canManage)
            <form method="POST" action="{{ route('admin.email-log.suppress') }}" class="flex flex-wrap items-end gap-3 px-5 pb-4">
                @csrf
                <x-ui.input :label="__('Email address')" name="email" type="email" />
                <x-ui.input :label="__('Note')" name="detail" />
                <x-ui.button type="submit" size="sm">{{ __('Block address') }}</x-ui.button>
            </form>
        @endif
        <x-ui.table :headers="[__('Address'), __('Topic'), __('Reason'), __('Source'), __('Since'), '']" :rows="$suppressions->isNotEmpty()" :empty="__('No suppressed addresses.')">
            @foreach ($suppressions as $suppression)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 font-mono text-xs">{{ $suppression->email_masked }}</td>
                    <td class="px-5 py-3 text-xs">{{ $suppression->topic === '*' ? __('All') : $suppression->topic }}</td>
                    <td class="px-5 py-3"><x-ui.badge :tone="in_array($suppression->reason, ['hard_bounce', 'complaint'], true) ? 'danger' : 'warning'">{{ __(str_replace('_', ' ', ucfirst($suppression->reason))) }}</x-ui.badge></td>
                    <td class="px-5 py-3 text-xs">{{ $suppression->source ?? '—' }}</td>
                    <td class="px-5 py-3 whitespace-nowrap text-xs">{{ $suppression->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') }}</td>
                    <td class="px-5 py-3 text-end">
                        @if ($canManage)
                            <form method="POST" action="{{ route('admin.email-log.suppressions.lift', $suppression) }}">@csrf @method('DELETE')<x-ui.button type="submit" size="sm" variant="ghost">{{ __('Remove') }}</x-ui.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
