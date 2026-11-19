<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal analytics (FR-POR-2 / FR-POR-8, QC-042): one row per portal page
 * view, registration-wizard step reached and application submitted, per
 * country. Visitors are an anonymous hash; raw rows are pruned with the
 * other raw analytics (`nawy:analytics:prune`, daily 04:30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_funnel_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 12);
            $table->string('page', 80);
            $table->string('type', 40)->nullable();
            $table->string('step', 40)->nullable();
            $table->char('visitor_hash', 64)->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event', 'created_at']);
            $table->index(['type', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_funnel_events');
    }
};
