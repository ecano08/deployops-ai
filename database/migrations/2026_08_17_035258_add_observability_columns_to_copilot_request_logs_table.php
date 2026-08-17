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
        Schema::table('copilot_request_logs', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->nullable()->after('tool_names');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            $table->boolean('rag_used')->default(false)->after('output_tokens');
            $table->unsignedInteger('rag_result_count')->default(0)->after('rag_used');
            $table->decimal('estimated_cost_usd', 12, 6)->nullable()->after('rag_result_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('copilot_request_logs', function (Blueprint $table) {
            $table->dropColumn([
                'input_tokens',
                'output_tokens',
                'rag_used',
                'rag_result_count',
                'estimated_cost_usd',
            ]);
        });
    }
};
