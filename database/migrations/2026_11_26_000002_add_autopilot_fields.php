<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->unsignedTinyInteger('opportunity_score')->nullable()->after('cpc');
            $table->json('opportunity_json')->nullable()->after('opportunity_score');
            $table->unsignedTinyInteger('suggest_rank')->nullable()->after('opportunity_json');
            $table->json('sources_json')->nullable()->after('suggest_rank');
            $table->boolean('is_gap')->default(false)->after('sources_json');
            $table->index(['website_id', 'opportunity_score']);
        });
        Schema::table('competitors', function (Blueprint $table) {
            $table->string('source', 16)->default('manual')->after('active');
            $table->unsignedSmallInteger('seen_count')->default(0)->after('source');
        });
        Schema::table('content_briefs', function (Blueprint $table) {
            $table->string('source', 16)->default('manual')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('content_briefs', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('competitors', fn (Blueprint $t) => $t->dropColumn(['source', 'seen_count']));
        Schema::table('keywords', function (Blueprint $table) {
            $table->dropIndex(['website_id', 'opportunity_score']);
            $table->dropColumn(['opportunity_score', 'opportunity_json', 'suggest_rank', 'sources_json', 'is_gap']);
        });
    }
};
