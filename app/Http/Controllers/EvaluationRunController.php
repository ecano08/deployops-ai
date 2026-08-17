<?php

namespace App\Http\Controllers;

use App\Http\Resources\EvaluationRunResource;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\Workspace;
use App\Services\EvaluationRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EvaluationRunController extends Controller
{
    public function index(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
    ): AnonymousResourceCollection {
        Gate::authorize('view', $deployment);

        $runs = EvaluationRun::query()
            ->where('deployment_id', $deployment->id)
            ->with(['results.evaluationCase'])
            ->orderByDesc('created_at')
            ->get();

        return EvaluationRunResource::collection($runs);
    }

    public function store(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        EvaluationDataset $evaluationDataset,
        EvaluationRunnerService $runner,
    ): JsonResponse {
        Gate::authorize('run', $evaluationDataset);

        $run = $runner->run($evaluationDataset, request()->user());

        return EvaluationRunResource::make($run)
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        EvaluationRun $evaluationRun,
    ): EvaluationRunResource {
        Gate::authorize('view', $deployment);

        if ($evaluationRun->deployment_id !== $deployment->id) {
            abort(404);
        }

        return EvaluationRunResource::make($evaluationRun->load(['results.evaluationCase']));
    }
}
