<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->string('type', 24); // update_page | add_section | new_landing | new_post | refresh
            $table->string('priority', 8)->default('medium');
            $table->unsignedTinyInteger('score')->default(0);
            $table->foreignUlid('page_id')->nullable()->constrained('website_pages')->nullOnDelete();
            $table->foreignUlid('keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->string('title');
            $table->text('why');
            $table->json('steps_json')->nullable();
            $table->json('proposal_json')->nullable();
            $table->string('status', 12)->default('open'); // open | done | dismissed | resolved
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'fingerprint']);
            $table->index(['website_id', 'status', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
