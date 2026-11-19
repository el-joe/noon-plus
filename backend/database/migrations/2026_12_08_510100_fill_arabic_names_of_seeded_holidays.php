<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The seeded holiday placeholders had no Arabic name, so the admin panel
 * (which requires both names) could not save them again — e.g. the UAE
 * "Commemoration Day and National Day" 2026 (browser test BT-ADM-011).
 * Fills the Arabic name of those rows where it is still empty.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $arabic = [
        'Commemoration Day and National Day' => 'يوم الشهيد واليوم الوطني',
        'New Year' => 'رأس السنة الميلادية',
        'National Day' => 'اليوم الوطني',
        "Isra and Mi'raj" => 'الإسراء والمعراج',
        'Accession Day' => 'يوم تولي السلطان مقاليد الحكم',
        'Founding Day' => 'يوم التأسيس',
        'Coptic Christmas' => 'عيد الميلاد المجيد',
        'Revolution Day' => 'عيد ثورة 25 يناير',
        'National and Liberation Day' => 'العيد الوطني وعيد التحرير',
        'Christmas' => 'عيد الميلاد',
        'Eid al-Fitr' => 'عيد الفطر',
        'Eid al-Adha' => 'عيد الأضحى',
    ];

    public function up(): void
    {
        foreach ($this->arabic as $english => $arabic) {
            DB::table('country_calendars')->where('name_en', $english)
                ->where(fn ($query) => $query->whereNull('name_ar')->orWhere('name_ar', ''))
                ->update(['name_ar' => $arabic]);
        }
    }

    public function down(): void
    {
        // Data fix only; the names stay.
    }
};
