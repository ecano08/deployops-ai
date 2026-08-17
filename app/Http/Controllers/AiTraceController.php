<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiTraceResource;
use App\Models\CopilotRequestLog;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Workspace;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AiTraceController extends Controller
{
    public function index(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', [CopilotRequestLog::class, $deployment]);

        $traces = $deployment->copilotRequestLogs()
            ->where('workspace_id', $workspace->id)
            ->with('toolCallTraces')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return AiTraceResource::collection($traces);
    }

    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        CopilotRequestLog $copilotRequestLog,
    ): AiTraceResource {
        Gate::authorize('view', $copilotRequestLog);

        return AiTraceResource::make($copilotRequestLog->load('toolCallTraces'));
    }
}
