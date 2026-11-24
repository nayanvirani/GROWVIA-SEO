<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('plan')->default('mvp');
            $table->string('timezone')->default('UTC');
            $table->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner');
            $table->timestamps();
            $table->primary(['workspace_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('current_workspace_id')->nullable()->after('remember_token')
                ->constrained('workspaces')->nullOnDelete();
        });

        Schema::create('websites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('base_url', 2048);
            $table->string('normalized_host');
            $table->string('status')->default('active');
            $table->string('website_type')->nullable();
            $table->string('language', 10)->default('en');
            $table->string('target_country', 2)->nullable();
            $table->boolean('crawl_enabled')->default(true);
            $table->boolean('ownership_confirmed')->default(false);
            $table->json('settings_json')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'normalized_host']);
        });

        Schema::create('website_verifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->string('token_hash');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('competitors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['website_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
        Schema::dropIfExists('website_verifications');
        Schema::dropIfExists('websites');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_workspace_id');
        });
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }
};
