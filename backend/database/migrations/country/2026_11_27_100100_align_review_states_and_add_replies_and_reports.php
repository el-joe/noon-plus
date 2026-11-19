<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QC-012: review states follow lifecycle catalogue §2.9 — `pending`,
 * `published`, `flagged`, `auto_flagged`, `hidden`, `rejected`. Existing
 * `approved` reviews (and their history rows) become `published`. Adds
 * moderated vendor replies and customer / vendor review reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->unsignedInteger('report_count')->default(0)->after('helpful_count');
            $table->string('flag_reason', 255)->nullable()->after('rejection_reason');
        });

        DB::table('product_reviews')->where('status', 'approved')->update(['status' => 'published']);

        if (Schema::hasTable('state_transitions')) {
            foreach (['from_status', 'to_status'] as $column) {
                DB::table('state_transitions')->where('subject_type', 'product_review')->where($column, 'approved')->update([$column => 'published']);
            }
        }

        Schema::create('product_review_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_review_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('author_user_id');
            $table->text('body');
            $table->string('status', 16)->default('pending');
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->unsignedBigInteger('moderated_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('product_review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_review_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('reporter_user_id');
            $table->string('reporter_type', 16); // customer | vendor
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 24);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['product_review_id', 'reporter_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_review_reports');
        Schema::dropIfExists('product_review_replies');
        DB::table('product_reviews')->where('status', 'published')->update(['status' => 'approved']);
        DB::table('product_reviews')->whereIn('status', ['flagged', 'auto_flagged'])->update(['status' => 'pending']);
        DB::table('product_reviews')->where('status', 'hidden')->update(['status' => 'rejected']);
        Schema::table('product_reviews', fn (Blueprint $table) => $table->dropColumn(['report_count', 'flag_reason']));
    }
};
