<?php

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
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
        Schema::create('deployment_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default(IntegrationType::RestApi->value);
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->string('endpoint')->nullable();
            $table->string('status')->default(IntegrationStatus::Disconnected->value);
            $table->json('config')->nullable();
            $table->text('secrets')->nullable();
            $table->timestamps();

            $table->index(['deployment_id', 'type']);
            $table->index(['workspace_id', 'deployment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployment_integrations');
    }
};
