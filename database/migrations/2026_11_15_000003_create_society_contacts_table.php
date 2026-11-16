<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The circuit's own contact details for its societies. Overrides what church.net.za has.
        Schema::create('society_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();   // the circuit
            $table->string('society_external_id', 40);                           // the society's id on church.net.za
            $table->string('society_name')->nullable();
            $table->string('email', 500)->nullable();                            // one or more addresses, comma-separated
            $table->timestamps();
            $table->unique(['entity_id', 'society_external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('society_contacts');
    }
};