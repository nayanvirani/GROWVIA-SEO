<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlink_prospects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('source_url', 2048);
            $table->char('source_url_hash', 64);
            $table->string('source_domain');
            $table->string('target_url', 2048)->nullable();
            $table->string('page_title')->nullable();
            $table->string('anchor_text')->nullable();
            $table->string('prospect_type', 32)->default('resource_page');
            $table->unsignedTinyInteger('score')->default(0);
            $table->json('score_breakdown_json')->nullable();
            $table->json('risk_flags_json')->nullable();
            $table->string('status', 16)->default('discovered');
            $table->json('evidence_json')->nullable();
            $table->string('discovery_source', 32)->default('manual');
            $table->timestamps();
            $table->unique(['website_id', 'source_url_hash']);
            $table->index(['website_id', 'status', 'score']);
        });

        Schema::create('backlinks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('competitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_url', 2048);
            $table->string('source_domain');
            $table->string('target_url', 2048);
            $table->string('anchor_text')->nullable();
            $table->string('rel_attributes')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('provider', 32);
            $table->timestamps();
            $table->index(['website_id', 'source_domain']);
        });

        Schema::create('outreach_drafts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('prospect_id')->constrained('backlink_prospects')->cascadeOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->string('status', 16)->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('encrypted_credentials')->nullable();
            $table->string('status', 16)->default('disconnected');
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'provider']);
        });

        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('page_url', 2048)->nullable();
            $table->string('query')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->decimal('position', 6, 2)->default(0);
            $table->string('source', 32);
            $table->timestamps();
            $table->index(['website_id', 'date']);
            $table->index(['website_id', 'query', 'date']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('automation_key', 64);
            $table->string('trigger', 16)->default('schedule');
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('result_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'automation_key', 'started_at']);
        });

        Schema::create('provider_usage', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('operation', 64);
            $table->unsignedInteger('request_count')->default(1);
            $table->unsignedInteger('units')->default(0);
            $table->decimal('estimated_cost', 10, 4)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'provider', 'created_at']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 26)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['activity_logs', 'provider_usage', 'automation_runs', 'analytics_daily', 'integrations',
            'outreach_drafts', 'backlinks', 'backlink_prospects'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
