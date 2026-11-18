<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->nullable()->after('is_active');
        });

        // Corrections made on screen. They win over the translation files, so wording can be fixed during beta without a deployment.
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 8);
            $table->text('source');                     // the English text
            $table->char('source_hash', 64);
            $table->text('translation');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['locale', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};