<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_briefs', function (Blueprint $table) {
            $table->string('last_error', 500)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('content_briefs', fn (Blueprint $t) => $t->dropColumn('last_error'));
    }
};
