<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_submissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('status', 16)->default('todo'); // todo | submitted | live | skipped
            $table->string('listing_url', 2048)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('check_result', 300)->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_submissions');
    }
};
