<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\UpdateIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Incident;
use App\Models\Workspace;
use App\Services\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class IncidentController extends Controller
{
    public function index(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', [Incident::class, $deployment]);

        $incidents = $deployment->incidents()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return IncidentResource::collection($incidents);
    }

    public function store(
        StoreIncidentRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        IncidentService $incidentService,
    ): JsonResponse {
        $incident = $incidentService->createManual(
            $deployment,
            $request->user(),
            $request->validated(),
        );

        return IncidentResource::make($incident)
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        Incident $incident,
    ): IncidentResource {
        Gate::authorize('view', $incident);

        return IncidentResource::make($incident);
    }

    public function update(
        UpdateIncidentRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        Incident $incident,
        IncidentService $incidentService,
    ): IncidentResource {
        $incident = $incidentService->update($incident, $request->validated());

        return IncidentResource::make($incident);
    }
}
