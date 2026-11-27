<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_drafts', function (Blueprint $table) {
            $table->string('published_url', 2048)->nullable()->after('exported_at');
            $table->timestamp('publish_checked_at')->nullable()->after('published_url');
            $table->json('publish_check_json')->nullable()->after('publish_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('content_drafts', fn (Blueprint $t) => $t->dropColumn(['published_url', 'publish_checked_at', 'publish_check_json']));
    }
};
