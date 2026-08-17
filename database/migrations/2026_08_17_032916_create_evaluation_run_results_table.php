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
        Schema::create('evaluation_run_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluation_case_id')->constrained()->cascadeOnDelete();
            $table->boolean('passed');
            $table->unsignedInteger('latency_ms');
            $table->json('tools_used')->nullable();
            $table->json('sources_used')->nullable();
            $table->text('answer')->nullable();
            $table->string('error_message')->nullable();
            $table->json('metrics');
            $table->timestamps();

            $table->index('evaluation_run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_run_results');
    }
};
