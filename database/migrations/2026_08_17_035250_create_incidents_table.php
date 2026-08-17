<?php

use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
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
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('severity')->default(IncidentSeverity::Medium->value);
            $table->string('status')->default(IncidentStatus::Open->value);
            $table->string('source')->default(IncidentSource::Manual->value);
            $table->string('source_reference')->nullable();
            $table->string('title');
            $table->text('description');
            $table->text('root_cause')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['deployment_id', 'status', 'created_at']);
            $table->index(['source', 'source_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
