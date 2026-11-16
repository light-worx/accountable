<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per society in a circuit budget, with one column per month (in cents).
        Schema::create('budget_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->string('society_external_id', 40);     // the society's id on church.net.za
            $table->string('society_slug')->nullable();
            $table->string('society_name');
            $table->string('society_email')->nullable();   // as at the time it was loaded; used for statements later

            foreach (range(1, 12) as $month) {
                $table->bigInteger("m{$month}")->default(0);
            }

            $table->unique(['budget_id', 'society_external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_assessments');
    }
};