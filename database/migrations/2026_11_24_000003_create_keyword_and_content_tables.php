<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_seeds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('seed_text');
            $table->string('source')->default('user');
            $table->timestamps();
        });

        Schema::create('keywords', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('locale', 10)->default('us');
            $table->string('language', 10)->default('en');
            $table->string('intent', 20)->nullable();
            $table->string('priority', 10)->nullable();
            $table->unsignedInteger('volume')->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->decimal('cpc', 8, 2)->nullable();
            $table->boolean('is_estimate')->default(true);
            $table->string('provider', 32);
            $table->date('metric_date')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'keyword', 'locale', 'language']);
            $table->index(['website_id', 'metric_date']);
        });

        Schema::create('keyword_clusters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignUlid('primary_keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('keyword_cluster_items', function (Blueprint $table) {
            $table->foreignUlid('keyword_cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('keyword_id')->constrained()->cascadeOnDelete();
            $table->primary(['keyword_cluster_id', 'keyword_id']);
        });

        Schema::create('page_keyword_targets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('page_id')->constrained('website_pages')->cascadeOnDelete();
            $table->foreignUlid('keyword_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 16)->default('primary');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['page_id', 'keyword_id']);
        });

        Schema::create('content_briefs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('page_id')->nullable()->constrained('website_pages')->nullOnDelete();
            $table->foreignUlid('primary_keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->string('content_type', 32)->default('blog_post');
            $table->string('title');
            $table->string('audience')->nullable();
            $table->string('intent', 20)->nullable();
            $table->text('source_notes')->nullable();
            $table->json('outline_json')->nullable();
            $table->string('status', 24)->default('idea');
            $table->timestamps();
        });

        Schema::create('content_drafts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('brief_id')->nullable()->constrained('content_briefs')->nullOnDelete();
            $table->string('type', 32);
            $table->string('title');
            $table->longText('body_markdown')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('slug_suggestion')->nullable();
            $table->json('faq_json')->nullable();
            $table->json('internal_links_json')->nullable();
            $table->json('quality_flags_json')->nullable();
            $table->json('source_pages_json')->nullable();
            $table->string('status', 24)->default('content_draft');
            $table->string('provider', 32)->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'status']);
        });

        Schema::create('content_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_draft_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body_markdown')->nullable();
            $table->string('change_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('image_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->text('brief')->nullable();
            $table->text('prompt');
            $table->string('alt_text')->nullable();
            $table->string('aspect_ratio', 8)->default('16:9');
            $table->string('placement')->nullable();
            $table->string('suggested_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('provider', 32);
            $table->string('status', 24)->default('brief');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['image_assets', 'content_revisions', 'content_drafts', 'content_briefs', 'page_keyword_targets',
            'keyword_cluster_items', 'keyword_clusters', 'keywords', 'keyword_seeds'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
