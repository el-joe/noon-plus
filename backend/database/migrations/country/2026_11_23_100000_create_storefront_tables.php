<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront API gaps (WP9 G-W1…G-W12) — country database: product and
 * variant media (vendor-managed, public disk), Arabic product content,
 * rating aggregates, reviews with photos and helpful votes, product Q&A,
 * the customer address book, the wishlist and newsletter subscriptions.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('alt_en')->nullable();
            $table->string('alt_ar')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
            $table->index(['product_variant_id', 'sort_order']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('highlights_ar')->nullable()->after('highlights');
            $table->json('specifications_ar')->nullable()->after('specifications');
            $table->decimal('rating_average', 3, 2)->nullable()->after('lead_days');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_average');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->unsignedInteger('rating_count')->default(0)->after('rating');
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('customer_name', 120);
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body')->nullable();
            $table->string('locale', 2)->default('en');
            $table->string('status', 16)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->unsignedInteger('helpful_count')->default(0);
            $table->timestamp('moderated_at')->nullable();
            $table->unsignedBigInteger('moderated_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status', 'created_at']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('product_review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_review_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_review_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_review_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_review_id', 'user_id']);
        });

        Schema::create('product_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('customer_name', 120);
            $table->string('body', 500);
            $table->string('locale', 2)->default('en');
            $table->string('status', 16)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->unsignedBigInteger('moderated_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status', 'created_at']);
        });

        Schema::create('product_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_question_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 16);
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('author_user_id');
            $table->text('body');
            $table->string('status', 16)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->unsignedBigInteger('moderated_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['product_question_id', 'status']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('label', 16)->default('home');
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('city', 120);
            $table->string('area', 120);
            $table->string('street');
            $table->string('building', 120)->nullable();
            $table->string('apartment', 60)->nullable();
            $table->string('landmark', 255)->nullable();
            $table->foreignId('shipping_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_user_id');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_user_id', 'product_id']);
        });

        Schema::create('newsletter_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('locale', 2)->default('en');
            $table->string('status', 16)->default('pending');
            $table->string('token_hash', 64)->unique();
            $table->string('source', 32)->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscriptions');
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('product_answers');
        Schema::dropIfExists('product_questions');
        Schema::dropIfExists('product_review_votes');
        Schema::dropIfExists('product_review_photos');
        Schema::dropIfExists('product_reviews');

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('rating_count');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['highlights_ar', 'specifications_ar', 'rating_average', 'rating_count']);
        });

        Schema::dropIfExists('product_media');
    }
};
