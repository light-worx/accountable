<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A log of every assessment statement emailed (or queued, or tried) to a society.
        Schema::create('statement_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();   // the circuit
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('society_external_id', 40);
            $table->string('society_name');
            $table->text('recipients');                                          // comma-separated
            $table->string('subject');
            $table->string('status', 10)->default('queued');                     // queued | sent | failed | test
            $table->text('error')->nullable();
            $table->bigInteger('balance_cents')->default(0);
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['entity_id', 'year', 'month', 'society_external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_sends');
    }
};