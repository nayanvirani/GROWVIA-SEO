<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            // Ready-to-paste content for this keyword's action: section text or new title, plus placement.
            $table->json('content_json')->nullable()->after('is_gap');
        });
    }

    public function down(): void
    {
        Schema::table('keywords', fn (Blueprint $t) => $t->dropColumn('content_json'));
    }
};
