<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA-032: the footer links to seven policy / legal pages. They are seeded
 * as global placeholder pages (`is_placeholder`) with neutral text that
 * states plainly that legal has not written it yet — no terms are made up.
 * Saving a page in Admin → Content → Static pages clears the flag; a page
 * that already exists is left alone.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private array $pages = [
        'contact-us' => ['Contact us', 'اتصل بنا'],
        'terms-and-conditions' => ['Terms and conditions', 'الشروط والأحكام'],
        'privacy-policy' => ['Privacy policy', 'سياسة الخصوصية'],
        'return-policy' => ['Return policy', 'سياسة الإرجاع'],
        'shipping-policy' => ['Shipping policy', 'سياسة الشحن'],
        'refund-policy' => ['Refund policy', 'سياسة استرداد المبالغ'],
        'cookie-policy' => ['Cookie policy', 'سياسة ملفات تعريف الارتباط'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('static_pages', 'is_placeholder')) {
            Schema::table('static_pages', function (Blueprint $table) {
                $table->boolean('is_placeholder')->default(false)->after('is_published');
            });
        }

        foreach ($this->pages as $slug => [$titleEn, $titleAr]) {
            if (DB::table('static_pages')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('static_pages')->insert([
                'country_id' => null,
                'slug' => $slug,
                'audience' => 'both',
                'title_en' => $titleEn,
                'title_ar' => $titleAr,
                'body_en' => '<p><strong>Placeholder — to be replaced by the legal team.</strong></p>'
                    .'<p>The '.mb_strtolower($titleEn).' for Nawy has not been published yet. This page is a placeholder and does not set out any terms, rights or obligations.</p>'
                    .'<p>For help in the meantime, use the help centre or contact customer support.</p>',
                'body_ar' => '<p><strong>نص مؤقت — سيستبدله الفريق القانوني.</strong></p>'
                    .'<p>لم تُنشر '.$titleAr.' الخاصة بنوي بعد. هذه الصفحة مؤقتة ولا تتضمن أي شروط أو حقوق أو التزامات.</p>'
                    .'<p>للمساعدة في الوقت الحالي، استخدم مركز المساعدة أو تواصل مع خدمة العملاء.</p>',
                'is_published' => true,
                'is_placeholder' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('static_pages')->where('is_placeholder', true)->whereIn('slug', array_keys($this->pages))->delete();

        Schema::table('static_pages', function (Blueprint $table) {
            $table->dropColumn('is_placeholder');
        });
    }
};
