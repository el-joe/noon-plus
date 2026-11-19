<?php

namespace App\Domains\Onboarding\Support;

use App\Domains\Onboarding\Models\PortalFunnelEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Portal analytics (FR-POR-8): records the website's funnel events and
 * reads them back for Admin → Portal analytics — unique visitors and views
 * per portal page, and per registration wizard the visitors who reached
 * each step and submitted.
 */
class PortalFunnel
{
    /**
     * @param  array{event: string, page: string, type?: string|null, step?: string|null}  $event
     */
    public function record(array $event, ?string $visitor, ?string $locale): void
    {
        PortalFunnelEvent::query()->create([
            'event' => $event['event'],
            'page' => mb_substr($event['page'], 0, 80),
            'type' => isset($event['type']) ? mb_substr((string) $event['type'], 0, 40) : null,
            'step' => isset($event['step']) ? mb_substr((string) $event['step'], 0, 40) : null,
            'visitor_hash' => $visitor === null || $visitor === '' ? null : hash_hmac('sha256', $visitor, (string) config('app.key')),
            'locale' => $locale,
        ]);
    }

    /**
     * @return array{pages: array<int, array{page: string, views: int, visitors: int}>, wizards: array<string, array{steps: array<int, array{step: string, visitors: int}>, submitted: int}>}
     */
    public function report(CarbonInterface $from): array
    {
        $pages = PortalFunnelEvent::query()->where('event', 'view')->where('created_at', '>=', $from)
            ->select('page', DB::raw('COUNT(*) AS views'), DB::raw('COUNT(DISTINCT visitor_hash) AS visitors'))
            ->groupBy('page')->orderByDesc('views')->get()
            ->map(fn (PortalFunnelEvent $row): array => ['page' => $row->page, 'views' => (int) $row->getAttribute('views'), 'visitors' => (int) $row->getAttribute('visitors')])->all();

        $wizards = [];

        foreach (PortalFunnelEvent::query()->whereIn('event', ['step', 'submitted'])->whereNotNull('type')->where('created_at', '>=', $from)
            ->select('type', 'event', 'step', DB::raw('MIN(id) AS first_id'), DB::raw('COUNT(DISTINCT COALESCE(visitor_hash, id)) AS visitors'))
            ->groupBy('type', 'event', 'step')->orderBy('first_id')->get() as $row) {
            $wizards[$row->type] ??= ['steps' => [], 'submitted' => 0];

            if ($row->event === 'submitted') {
                $wizards[$row->type]['submitted'] += (int) $row->getAttribute('visitors');
            } else {
                $wizards[$row->type]['steps'][] = ['step' => (string) $row->step, 'visitors' => (int) $row->getAttribute('visitors')];
            }
        }

        ksort($wizards);

        return ['pages' => $pages, 'wizards' => $wizards];
    }

    public function prune(int $days): int
    {
        return PortalFunnelEvent::query()->where('created_at', '<', now()->subDays($days))->delete();
    }
}
