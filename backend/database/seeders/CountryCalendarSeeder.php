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
     * Country code => list of [starts_on, ends_on, name_en, name_ar]. Both names are required to
     * save a holiday in the admin panel (browser test BT-ADM-011).
     *
     * @var array<string, array<int, array{0: string, 1: string, 2: string, 3: string}>>
     */
    private const HOLIDAYS = [
        'uae' => [['2026-12-01', '2026-12-03', 'Commemoration Day and National Day', 'يوم الشهيد واليوم الوطني'], ['2027-01-01', '2027-01-01', 'New Year', 'رأس السنة الميلادية']],
        'omn' => [['2026-11-20', '2026-11-21', 'National Day', 'اليوم الوطني'], ['2027-01-06', '2027-01-06', "Isra and Mi'raj", 'الإسراء والمعراج'], ['2027-01-11', '2027-01-11', 'Accession Day', 'يوم تولي السلطان مقاليد الحكم']],
        'ksa' => [['2027-02-22', '2027-02-22', 'Founding Day', 'يوم التأسيس']],
        'egy' => [['2027-01-07', '2027-01-07', 'Coptic Christmas', 'عيد الميلاد المجيد'], ['2027-01-25', '2027-01-25', 'Revolution Day', 'عيد ثورة 25 يناير']],
        'kwt' => [['2027-01-01', '2027-01-01', 'New Year', 'رأس السنة الميلادية'], ['2027-01-06', '2027-01-06', "Isra and Mi'raj", 'الإسراء والمعراج'], ['2027-02-25', '2027-02-26', 'National and Liberation Day', 'العيد الوطني وعيد التحرير']],
        'qat' => [['2026-12-18', '2026-12-18', 'National Day', 'اليوم الوطني']],
        'bhr' => [['2026-12-16', '2026-12-17', 'National Day', 'اليوم الوطني'], ['2027-01-01', '2027-01-01', 'New Year', 'رأس السنة الميلادية']],
        'jor' => [['2026-12-25', '2026-12-25', 'Christmas', 'عيد الميلاد'], ['2027-01-01', '2027-01-01', 'New Year', 'رأس السنة الميلادية']],
    ];

    /**
     * Observed in all 8 countries.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const SHARED = [
        ['2027-03-09', '2027-03-11', 'Eid al-Fitr', 'عيد الفطر'],
        ['2027-05-16', '2027-05-19', 'Eid al-Adha', 'عيد الأضحى'],
    ];

    public function run(): void
    {
        foreach (Country::query()->get() as $country) {
            foreach ([...self::HOLIDAYS[$country->code] ?? [], ...self::SHARED] as [$startsOn, $endsOn, $name, $nameAr]) {
                CountryCalendar::query()->updateOrCreate(
                    ['country_id' => $country->id, 'starts_on' => $startsOn],
                    ['ends_on' => $endsOn, 'name_en' => $name, 'name_ar' => $nameAr, 'is_confirmed' => false],
                );
            }
        }
    }
}
