<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy products (StarStellar, StarLink) never expire OTPs - validity lasts
     * until the code is used or replaced by the next send. Allow null expires_at
     * to mirror that behaviour; a window can still be set explicitly per call.
     */
    public function up(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
