<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiHealthSummaryResource;
use App\Models\CopilotRequestLog;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Workspace;
use App\Services\AiHealthMetricsService;
use Illuminate\Support\Facades\Gate;

class AiHealthController extends Controller
{
    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        AiHealthMetricsService $aiHealthMetrics,
    ): AiHealthSummaryResource {
        Gate::authorize('viewAny', [CopilotRequestLog::class, $deployment]);

        return AiHealthSummaryResource::make($aiHealthMetrics->summarize($deployment));
    }
}
