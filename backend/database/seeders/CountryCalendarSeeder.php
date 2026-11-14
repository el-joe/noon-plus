<?php

namespace Database\Seeders;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\CountryCalendar;
use Illuminate\Database\Seeder;

/**
 * Holiday placeholders from country-reference.md §6 (to confirm by Ops, CQ1).
 * Islamic dates are approximate (±1–2 days). Seeded unconfirmed.
 */
class CountryCalendarSeeder extends Seeder
{
    /**
     * Country code => list of [starts_on, ends_on, name_en].
     *
     * @var array<string, array<int, array{0: string, 1: string, 2: string}>>
     */
    private const HOLIDAYS = [
        'uae' => [['2026-12-01', '2026-12-03', 'Commemoration Day and National Day'], ['2027-01-01', '2027-01-01', 'New Year']],
        'omn' => [['2026-11-20', '2026-11-21', 'National Day'], ['2027-01-06', '2027-01-06', "Isra and Mi'raj"], ['2027-01-11', '2027-01-11', 'Accession Day']],
        'ksa' => [['2027-02-22', '2027-02-22', 'Founding Day']],
        'egy' => [['2027-01-07', '2027-01-07', 'Coptic Christmas'], ['2027-01-25', '2027-01-25', 'Revolution Day']],
        'kwt' => [['2027-01-01', '2027-01-01', 'New Year'], ['2027-01-06', '2027-01-06', "Isra and Mi'raj"], ['2027-02-25', '2027-02-26', 'National and Liberation Day']],
        'qat' => [['2026-12-18', '2026-12-18', 'National Day']],
        'bhr' => [['2026-12-16', '2026-12-17', 'National Day'], ['2027-01-01', '2027-01-01', 'New Year']],
        'jor' => [['2026-12-25', '2026-12-25', 'Christmas'], ['2027-01-01', '2027-01-01', 'New Year']],
    ];

    /**
     * Observed in all 8 countries.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const SHARED = [
        ['2027-03-09', '2027-03-11', 'Eid al-Fitr'],
        ['2027-05-16', '2027-05-19', 'Eid al-Adha'],
    ];

    public function run(): void
    {
        foreach (Country::query()->get() as $country) {
            foreach ([...self::HOLIDAYS[$country->code] ?? [], ...self::SHARED] as [$startsOn, $endsOn, $name]) {
                CountryCalendar::query()->updateOrCreate(
                    ['country_id' => $country->id, 'starts_on' => $startsOn],
                    ['ends_on' => $endsOn, 'name_en' => $name, 'is_confirmed' => false],
                );
            }
        }
    }
}
