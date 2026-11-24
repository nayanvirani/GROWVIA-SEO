<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->string('trigger')->default('manual');
            $table->string('crawler_job_id')->nullable();
            $table->unsignedInteger('cursor')->default(0);
            $table->unsignedInteger('max_pages')->default(300);
            $table->unsignedTinyInteger('max_depth')->default(5);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('pages_found')->default(0);
            $table->unsignedInteger('pages_crawled')->default(0);
            $table->unsignedInteger('issues_found')->default(0);
            $table->json('error_summary')->nullable();
            $table->json('sitemaps_json')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'created_at']);
        });

        Schema::create('website_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('last_crawl_run_id')->nullable()->constrained('crawl_runs')->nullOnDelete();
            $table->string('canonical_url', 2048);
            $table->char('url_hash', 64);
            $table->string('final_url', 2048)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('content_type')->nullable();
            $table->text('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('h1')->nullable();
            $table->json('headings_json')->nullable();
            $table->string('canonical_url_found', 2048)->nullable();
            $table->string('robots_meta')->nullable();
            $table->string('x_robots_tag')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->char('content_hash', 64)->nullable();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->json('redirect_chain_json')->nullable();
            $table->json('images_json')->nullable();
            $table->text('text_excerpt')->nullable();
            $table->string('fetch_error')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'url_hash']);
            $table->index(['website_id', 'status_code']);
            $table->index(['website_id', 'last_crawled_at']);
        });

        Schema::create('page_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('source_page_id')->constrained('website_pages')->cascadeOnDelete();
            $table->string('target_url', 2048);
            $table->char('target_url_hash', 64);
            $table->text('anchor_text')->nullable();
            $table->string('link_type', 16);
            $table->string('rel')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'link_type']);
            $table->index(['website_id', 'target_url_hash']);
        });

        Schema::create('seo_issues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('page_id')->nullable()->constrained('website_pages')->cascadeOnDelete();
            $table->string('rule_key', 64);
            $table->char('fingerprint', 64);
            $table->string('severity', 16);
            $table->json('evidence_json')->nullable();
            $table->text('recommendation');
            $table->text('ai_explanation')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'fingerprint']);
            $table->index(['website_id', 'status', 'severity']);
            $table->index(['website_id', 'rule_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_issues');
        Schema::dropIfExists('page_links');
        Schema::dropIfExists('website_pages');
        Schema::dropIfExists('crawl_runs');
    }
};
