<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeploymentIntegrationRequest;
use App\Http\Requests\UpdateDeploymentIntegrationRequest;
use App\Http\Resources\DeploymentIntegrationResource;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\DeploymentIntegration;
use App\Models\Workspace;
use App\Services\IntegrationConnectionTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeploymentIntegrationController extends Controller
{
    public function index(Workspace $workspace, Customer $customer, Deployment $deployment): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [DeploymentIntegration::class, $workspace]);

        $integrations = $deployment->integrations()
            ->orderBy('name')
            ->get();

        return DeploymentIntegrationResource::collection($integrations);
    }

    public function store(
        StoreDeploymentIntegrationRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
    ): JsonResponse {
        $integration = DeploymentIntegration::query()->create(
            $request->integrationAttributes($workspace, $deployment),
        );

        return DeploymentIntegrationResource::make($integration)
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        DeploymentIntegration $deploymentIntegration,
    ): DeploymentIntegrationResource {
        Gate::authorize('view', $deploymentIntegration);

        return DeploymentIntegrationResource::make($deploymentIntegration);
    }

    public function update(
        UpdateDeploymentIntegrationRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        DeploymentIntegration $deploymentIntegration,
    ): DeploymentIntegrationResource {
        $deploymentIntegration->update($request->integrationAttributes());

        return DeploymentIntegrationResource::make($deploymentIntegration->fresh());
    }

    public function destroy(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        DeploymentIntegration $deploymentIntegration,
    ): Response {
        Gate::authorize('delete', $deploymentIntegration);

        $deploymentIntegration->delete();

        return response()->noContent();
    }

    public function test(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        DeploymentIntegration $deploymentIntegration,
        IntegrationConnectionTester $tester,
    ): JsonResponse {
        Gate::authorize('test', $deploymentIntegration);

        $result = $tester->test($deploymentIntegration);

        return response()->json([
            'data' => [
                'success' => $result['success'],
                'status' => $result['status']->value,
                'metadata' => $result['metadata'],
                'message' => $result['message'],
            ],
        ]);
    }
}
