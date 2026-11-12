<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the money physically is: bank accounts, cash boxes, investments.
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->string('bank_name')->nullable();
            $table->string('account_number', 50)->nullable();
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->date('opening_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['entity_id', 'name']);
        });

        // Whose money it is: General fund, Building Fund, Manse Fund, etc.
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_general')->default(false);
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['entity_id', 'name']);
        });

        // What the money was for. return_line_code links to a line on the 4S/4C
        // (e.g. "3.2.1"); filled in once the official line list is loaded.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);                       // income | expense
            $table->string('name');
            $table->string('return_line_code', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['entity_id', 'kind', 'name']);
        });

        Schema::create('payees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('supplier');
            $table->string('pf_number', 20)->nullable();      // clergy only (needed for 4S Section 8 / page 4c)
            $table->boolean('in_our_circuit')->default(true); // clergy only
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['entity_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payees');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('funds');
        Schema::dropIfExists('money_accounts');
    }
};
