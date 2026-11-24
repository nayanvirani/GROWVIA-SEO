<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The app is for personal use: no emails are sent and Search Console is not used. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_digest', 'email_reminders']);
        });
        Schema::dropIfExists('analytics_daily');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_digest')->default(true);
            $table->boolean('email_reminders')->default(true);
        });
    }
};
