<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStage;
use App\Http\Requests\StoreDeploymentRequest;
use App\Http\Requests\UpdateDeploymentRequest;
use App\Http\Requests\UpdateDeploymentStageRequest;
use App\Http\Resources\DeploymentResource;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeploymentController extends Controller
{
    public function index(Workspace $workspace, Customer $customer): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Deployment::class, $workspace]);

        $deployments = $customer->deployments()
            ->orderBy('name')
            ->get();

        return DeploymentResource::collection($deployments);
    }

    public function store(StoreDeploymentRequest $request, Workspace $workspace, Customer $customer): JsonResponse
    {
        $deployment = $customer->deployments()->create([
            'workspace_id' => $workspace->id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'stage' => $request->validated('stage', DeploymentStage::Discovery->value),
        ]);

        return DeploymentResource::make($deployment)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Workspace $workspace, Customer $customer, Deployment $deployment): DeploymentResource
    {
        Gate::authorize('view', $deployment);

        return DeploymentResource::make($deployment);
    }

    public function update(UpdateDeploymentRequest $request, Workspace $workspace, Customer $customer, Deployment $deployment): DeploymentResource
    {
        $deployment->update($request->safe()->only(['name', 'description']));

        return DeploymentResource::make($deployment);
    }

    public function destroy(Workspace $workspace, Customer $customer, Deployment $deployment): Response
    {
        Gate::authorize('delete', $deployment);

        $deployment->delete();

        return response()->noContent();
    }

    public function updateStage(UpdateDeploymentStageRequest $request, Workspace $workspace, Customer $customer, Deployment $deployment): DeploymentResource
    {
        $deployment->update([
            'stage' => $request->validated('stage'),
        ]);

        return DeploymentResource::make($deployment);
    }
}
