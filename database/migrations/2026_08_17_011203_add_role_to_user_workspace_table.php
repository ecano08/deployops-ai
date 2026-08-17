<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->string('role')->default('viewer')->after('workspace_id');
        });

        $ownerMembershipIds = DB::table('user_workspace')
            ->join('workspaces', 'workspaces.id', '=', 'user_workspace.workspace_id')
            ->whereColumn('user_workspace.user_id', 'workspaces.owner_id')
            ->pluck('user_workspace.id');

        if ($ownerMembershipIds->isNotEmpty()) {
            DB::table('user_workspace')
                ->whereIn('id', $ownerMembershipIds)
                ->update(['role' => 'owner']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
