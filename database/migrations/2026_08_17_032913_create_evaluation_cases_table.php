<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('evaluation_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_dataset_id')->constrained()->cascadeOnDelete();
            $table->text('input');
            $table->text('expected_behavior');
            $table->json('expected_tools')->nullable();
            $table->json('expected_sources')->nullable();
            $table->timestamps();

            $table->index('evaluation_dataset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_cases');
    }
};
