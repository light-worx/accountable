<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payees', function (Blueprint $table) {
            $table->string('society_external_id', 40)->nullable()->after('type'); // the society's id on church.net.za
            $table->unique(['entity_id', 'society_external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payees', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'society_external_id']);
            $table->dropColumn('society_external_id');
        });
    }
};