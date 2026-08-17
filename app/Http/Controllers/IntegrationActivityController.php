<?php

namespace App\Http\Controllers;

use App\Http\Resources\IntegrationActivityResource;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\DeploymentIntegration;
use App\Models\Workspace;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class IntegrationActivityController extends Controller
{
    public function index(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        DeploymentIntegration $deploymentIntegration,
    ): AnonymousResourceCollection {
        Gate::authorize('viewActivities', $deploymentIntegration);

        $activities = $deploymentIntegration->activities()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return IntegrationActivityResource::collection($activities);
    }
}
