<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content (WP7, SRS §15, ADR 16; docs/design/page-builder-blocks.md):
     * page builder pages per locale with append-only revisions and saved
     * layout templates, app contexts, blog, help centre and knowledge hub.
     * Status history goes to `state_transitions`.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('template', 24);
            $table->string('slug', 120);
            $table->string('locale', 5);
            $table->string('title');
            $table->string('status', 12)->default('draft');
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->unsignedBigInteger('published_revision_id')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['slug', 'locale']);
            $table->index(['status', 'publish_at']);
        });

        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('zones');
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['page_id', 'number']);
        });

        Schema::create('page_layout_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('template', 24);
            $table->json('zones');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('app_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 24)->unique();
            $table->string('name_en', 60);
            $table->string('name_ar', 60);
            $table->string('icon', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('theme');
            $table->json('tabs');
            $table->foreignId('home_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->string('slug', 160)->unique();
            $table->string('title_en');
            $table->string('title_ar');
            $table->string('excerpt_en', 500)->nullable();
            $table->string('excerpt_ar', 500)->nullable();
            $table->longText('body_en');
            $table->longText('body_ar');
            $table->string('cover_path')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('status', 12)->default('draft');
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('help_collections', function (Blueprint $table) {
            $table->id();
            $table->string('hub', 16);
            $table->string('audience', 12)->default('customer');
            $table->string('slug', 80);
            $table->string('title_en');
            $table->string('title_ar');
            $table->string('description_en', 500)->nullable();
            $table->string('description_ar', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['hub', 'slug']);
        });

        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('help_collections')->cascadeOnDelete();
            $table->string('hub', 16);
            $table->string('slug', 160);
            $table->string('title_en');
            $table->string('title_ar');
            $table->longText('body_en');
            $table->longText('body_ar');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_published')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->unique(['hub', 'slug']);
            $table->index(['hub', 'is_published', 'views']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('help_collections');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_categories');
        Schema::dropIfExists('app_contexts');
        Schema::dropIfExists('page_layout_templates');
        Schema::dropIfExists('page_revisions');
        Schema::dropIfExists('pages');
    }
};
