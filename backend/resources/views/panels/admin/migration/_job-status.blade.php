<x-ui.badge :tone="match ($job->status) { 'done' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'warning' }" data-job-status="{{ $job->status }}">{{ match ($job->status) { 'done' => __('Done'), 'failed' => __('Failed'), 'running' => __('Running'), default => __('Queued') } }}</x-ui.badge>
@if ($job->dry_run)<x-ui.badge tone="neutral">{{ __('Dry run') }}</x-ui.badge>@endif
