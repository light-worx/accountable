<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('external_id', 40)->nullable()->after('id');   // id on church.net.za
            $table->string('external_slug')->nullable()->after('external_id');
            $table->timestamp('synced_at')->nullable();
            $table->unique(['type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropUnique(['type', 'external_id']);
            $table->dropColumn(['external_id', 'external_slug', 'synced_at']);
        });
    }
};