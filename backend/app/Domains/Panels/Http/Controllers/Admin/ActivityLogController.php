<?php

namespace App\Domains\Panels\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Http\LikeSearch;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

/**
 * Admin → Overview → Activity log (QC-044, matrix §3.2 `activity_log`):
 * the Spatie activity log filtered by who did it, what it touched and when
 * (dates in Oman time). Entries of the current country (tagged by
 * {@see LogsCountryActivity}) and central entries
 * (settings, countries, memberships, roles…) are shown; other countries'
 * entries are not.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request, CountryContext $countryContext): View
    {
        $country = $countryContext->current();
        $filters = $request->validate([
            'user' => ['nullable', 'string', 'max:120'],
            'log' => ['nullable', 'string', 'max:64'],
            'subject' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'integer'],
            'scope' => ['nullable', Rule::in(['country', 'central'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $zone = config('nawy.admin_timezone');
        $causerIds = filled($filters['user'] ?? null)
            ? User::query()->where('email', 'like', LikeSearch::contains($filters['user']))->orWhere('name', 'like', LikeSearch::contains($filters['user']))->limit(100)->pluck('id')
            : null;

        $entries = Activity::query()
            ->where(fn ($query) => match ($filters['scope'] ?? null) {
                'country' => $query->where('properties->country', $country->code),
                'central' => $query->whereNull('properties->country'),
                default => $query->where('properties->country', $country->code)->orWhereNull('properties->country'),
            })
            ->when($causerIds !== null, fn ($query) => $query->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', $causerIds))
            ->when($filters['log'] ?? null, fn ($query, string $log) => $query->where('log_name', $log))
            ->when($filters['subject'] ?? null, fn ($query, string $subject) => $query->where('subject_type', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $subject)))
            ->when($filters['subject_id'] ?? null, fn ($query, int $id) => $query->where('subject_id', $id))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', CarbonImmutable::parse($from, $zone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<', CarbonImmutable::parse($to, $zone)->addDay()->startOfDay()->utc()))
            ->latest('id')->paginate(50)->withQueryString();

        return view('panels.admin.platform.activity', [
            'entries' => $entries,
            'causers' => User::query()->whereIn('id', $entries->where('causer_type', (new User)->getMorphClass())->pluck('causer_id')->unique())->get(['id', 'name', 'email'])->keyBy('id'),
            'logs' => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name')->filter()->values(),
            'subjects' => Activity::query()->distinct()->whereNotNull('subject_type')->pluck('subject_type')->map(fn (string $type): string => class_basename($type))->unique()->sort()->values(),
            'filters' => $filters,
            'country' => $country,
            'short' => fn (?string $type): string => $type === null ? '—' : Str::headline(class_basename($type)),
        ]);
    }
}
