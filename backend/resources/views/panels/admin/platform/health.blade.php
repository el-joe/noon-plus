@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M H:i') : '—')
@php($backlog = collect($queues)->sum(fn ($queue) => $queue['size'] ?? 0))
<x-layouts.panel :title="__('System health')" :subtitle="__('Queues, failed jobs, scheduler, databases, mail and storage · checked :at Oman', ['at' => $oman($checkedAt)])" :breadcrumbs="[__('Platform settings'), __('System health')]">
    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card :label="__('Horizon')" :value="ucfirst($horizon['status'])" :delta="trans_choice(':count supervisor|:count supervisors', $horizon['supervisors'])" :trend="$horizon['status'] === 'running' ? 'up' : 'down'" />
        <x-ui.stat-card :label="__('Queue backlog')" :value="trans_choice(':count job|:count jobs', $backlog)" />
        <x-ui.stat-card :label="__('Failed jobs (24 h)')" :value="(string) $failed['last_day']" :delta="__(':count in total', ['count' => $failed['total']])" :trend="$failed['last_day'] > 0 ? 'down' : 'up'" />
        <x-ui.stat-card :label="__('Country databases')" :value="collect($databases)->where('ok', true)->count().' / '.count($databases)" :trend="collect($databases)->every(fn ($db) => $db['ok']) ? 'up' : 'down'" :delta="collect($databases)->every(fn ($db) => $db['ok']) ? __('All reachable') : __('Some unreachable')" />
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <x-ui.card :title="__('Queues (Horizon)')" flush>
            <x-ui.table :headers="[__('Queue'), __('Pending'), __('Status')]">
                @foreach ($queues as $queue)
                    <tr class="border-t border-line" data-queue="{{ $queue['queue'] }}">
                        <td class="px-5 py-3">{{ $queue['queue'] }}</td>
                        <td class="px-5 py-3">{{ $queue['size'] ?? '—' }}</td>
                        <td class="px-5 py-3"><x-ui.badge :tone="$queue['size'] === null ? 'neutral' : ($queue['size'] > 1000 ? 'danger' : 'success')">{{ $queue['size'] === null ? __('Unknown') : ($queue['size'] > 1000 ? __('Backlog') : __('OK')) }}</x-ui.badge></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('Country databases')" flush>
            <x-ui.table :headers="[__('Country'), __('Latency'), __('Status')]">
                @foreach ($databases as $database)
                    <tr class="border-t border-line" data-database="{{ $database['code'] }}">
                        <td class="px-5 py-3">{{ $database['name'] }}</td>
                        <td class="px-5 py-3">{{ $database['ms'] !== null ? $database['ms'].' ms' : '—' }}</td>
                        <td class="px-5 py-3"><x-ui.badge :tone="$database['ok'] ? 'success' : 'danger'">{{ $database['ok'] ? __('Up') : __('Down') }}</x-ui.badge>@if ($database['error'])<span class="block text-xs text-fg-danger">{{ $database['error'] }}</span>@endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Failed jobs')" flush>
        <x-ui.table :headers="[__('Job'), __('Queue'), __('Error'), __('Failed at (Oman)'), '']" :rows="$failed['latest']->isNotEmpty()" :empty="__('No failed jobs.')">
            @foreach ($failed['latest'] as $job)
                <tr class="border-t border-line" data-failed-job="{{ $job->uuid }}">
                    <td class="px-5 py-3 font-medium">{{ class_basename($job->name) }}</td>
                    <td class="px-5 py-3">{{ $job->queue }}</td>
                    <td class="px-5 py-3 text-xs">{{ $job->error }}</td>
                    <td class="px-5 py-3 text-xs">{{ $oman($job->failed_at) }}</td>
                    <td class="px-5 py-3 text-end">
                        @can('system_health.manage')
                            <div class="flex justify-end gap-2">
                                <form method="POST" action="{{ route('admin.health.retry', $job->uuid) }}">@csrf<x-ui.button type="submit" variant="outline" size="sm">{{ __('Retry') }}</x-ui.button></form>
                                <form method="POST" action="{{ route('admin.health.forget', $job->uuid) }}">@csrf @method('DELETE')<x-ui.button type="submit" variant="ghost" size="sm">{{ __('Delete') }}</x-ui.button></form>
                            </div>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Scheduler')" :subtitle="__('Last runs are recorded when the scheduler finishes a task.')" flush>
        <x-ui.table :headers="[__('Task'), __('Cron'), __('Next due (Oman)'), __('Last run (Oman)'), __('Result')]" :rows="count($schedule) > 0" :empty="__('No scheduled tasks found.')">
            @foreach ($schedule as $task)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 font-mono text-xs">{{ \Illuminate\Support\Str::limit($task['command'], 70) }}</td>
                    <td class="px-5 py-3 font-mono text-xs">{{ $task['expression'] }}</td>
                    <td class="px-5 py-3 text-xs">{{ $oman($task['next_due']) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $oman($task['last_run']['at'] ?? null) }}</td>
                    <td class="px-5 py-3">@if ($task['last_run'])<x-ui.badge :tone="$task['last_run']['ok'] ? 'success' : 'danger'">{{ $task['last_run']['ok'] ? __('OK') : __('Failed') }}</x-ui.badge>@else <span class="text-xs text-fg-secondary">{{ __('Not run yet') }}</span> @endif</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <section class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('Mail')">
            <p class="text-sm">{{ $mail['mailer'] }} · {{ $mail['from'] ?? __('no sender address') }}</p>
            <p class="mt-1 text-xs text-fg-secondary">{{ $mail['note'] }}</p>
            <x-ui.badge :tone="$mail['ok'] ? 'success' : 'danger'" class="mt-2">{{ $mail['ok'] ? __('Configured') : __('Missing sender') }}</x-ui.badge>
        </x-ui.card>
        <x-ui.card :title="__('Storage')">
            <ul class="flex flex-col gap-2 text-sm">
                @foreach ($storage as $disk)
                    <li class="flex items-center justify-between gap-2" data-disk="{{ $disk['disk'] }}">{{ $disk['disk'] }} <x-ui.badge :tone="$disk['ok'] ? 'success' : 'danger'">{{ $disk['ok'] ? __('Writable') : __('Failing') }}</x-ui.badge></li>
                @endforeach
            </ul>
        </x-ui.card>
        <x-ui.card :title="__('Cache')">
            <p class="text-sm">{{ $cache['store'] }}</p>
            <x-ui.badge :tone="$cache['ok'] ? 'success' : 'danger'" class="mt-2">{{ $cache['ok'] ? __('OK') : __('Failing') }}</x-ui.badge>
        </x-ui.card>
    </section>
</x-layouts.panel>
