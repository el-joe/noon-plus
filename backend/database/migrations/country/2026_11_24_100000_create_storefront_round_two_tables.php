<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Storefront API gaps round 2 (WP9 G-W14…G-W31) — country database:
 * flash-sale slugs, gift-card designs, request types on classified viewing
 * requests (D8 viewing / reserve), customer notifications and help-article
 * helpful votes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('flash_sale_events', function (Blueprint $table) {
            $table->string('slug', 180)->nullable()->unique()->after('display_name');
        });

        DB::table('flash_sale_events')->whereNull('slug')->orderBy('id')->get(['id', 'name', 'display_name'])
            ->each(fn (object $event) => DB::table('flash_sale_events')->where('id', $event->id)
                ->update(['slug' => (Str::slug((string) ($event->display_name ?: $event->name)) ?: 'flash-sale').'-'.$event->id]));

        Schema::table('gift_cards', function (Blueprint $table) {
            $table->string('design', 32)->default('classic')->after('source');
        });

        Schema::table('property_viewings', function (Blueprint $table) {
            $table->string('type', 16)->default('viewing')->after('classified_ad_id');
            $table->index(['customer_user_id', 'id']);
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 64);
            $table->string('title');
            $table->string('body', 1000)->nullable();
            $table->string('link', 255)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'id']);
        });

        Schema::table('help_articles', function (Blueprint $table) {
            $table->unsignedInteger('helpful_count')->default(0)->after('views');
            $table->unsignedInteger('not_helpful_count')->default(0)->after('helpful_count');
        });

        Schema::create('help_article_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->char('voter_key', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_helpful');
            $table->timestamps();

            $table->unique(['help_article_id', 'voter_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('help_article_votes');
        Schema::table('help_articles', fn (Blueprint $table) => $table->dropColumn(['helpful_count', 'not_helpful_count']));
        Schema::dropIfExists('customer_notifications');
        Schema::table('property_viewings', function (Blueprint $table) {
            $table->dropIndex(['customer_user_id', 'id']);
            $table->dropColumn('type');
        });
        Schema::table('gift_cards', fn (Blueprint $table) => $table->dropColumn('design'));
        Schema::table('flash_sale_events', fn (Blueprint $table) => $table->dropColumn('slug'));
    }
};
