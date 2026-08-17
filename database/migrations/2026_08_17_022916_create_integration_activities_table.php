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
        Schema::create('integration_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_integration_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['deployment_integration_id', 'created_at'], 'ia_integration_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_activities');
    }
};
