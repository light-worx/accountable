<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('version');
            $table->string('status', 12)->default('draft');        // draft | approved | superseded
            $table->string('note')->nullable();                     // e.g. "Circuit Quarterly Meeting, 18 Feb 2026"
            $table->text('change_reason')->nullable();              // why a new version was made
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['entity_id', 'year', 'version']);
        });

        // One row per income / payment type, with one column per month (all in cents).
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            foreach (range(1, 12) as $month) {
                $table->bigInteger("m{$month}")->default(0);
            }

            $table->unique(['budget_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};