<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                       // society | circuit | district
            $table->foreignId('parent_id')->nullable()->constrained('entities')->restrictOnDelete();
            $table->string('name');
            $table->string('number', 20)->nullable();         // society / circuit number as used by MCO
            $table->boolean('allow_overdrawn')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('entity_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);                       // treasurer | capturer | reviewer | viewer
            $table->timestamps();
            $table->unique(['entity_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_user');
        Schema::dropIfExists('entities');
    }
};
