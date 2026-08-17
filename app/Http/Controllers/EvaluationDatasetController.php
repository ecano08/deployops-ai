<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEvaluationCaseRequest;
use App\Http\Requests\StoreEvaluationDatasetRequest;
use App\Http\Requests\UpdateEvaluationCaseRequest;
use App\Http\Requests\UpdateEvaluationDatasetRequest;
use App\Http\Resources\EvaluationCaseResource;
use App\Http\Resources\EvaluationDatasetResource;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\EvaluationCase;
use App\Models\EvaluationDataset;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class EvaluationDatasetController extends Controller
{
    public function index(Workspace $workspace, Customer $customer, Deployment $deployment): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [EvaluationDataset::class, $workspace, $deployment]);

        $datasets = $deployment->evaluationDatasets()
            ->with('cases')
            ->orderBy('name')
            ->get();

        return EvaluationDatasetResource::collection($datasets);
    }

    public function store(
        StoreEvaluationDatasetRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
    ): JsonResponse {
        $dataset = $deployment->evaluationDatasets()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ]);

        if ($request->has('cases')) {
            foreach ($request->validated('cases') as $caseData) {
                $dataset->cases()->create($caseData);
            }
        }

        return EvaluationDatasetResource::make($dataset->load('cases'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        EvaluationDataset $evaluationDataset,
    ): EvaluationDatasetResource {
        Gate::authorize('view', $evaluationDataset);

        return EvaluationDatasetResource::make($evaluationDataset->load('cases'));
    }

    public function update(
        UpdateEvaluationDatasetRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        EvaluationDataset $evaluationDataset,
    ): EvaluationDatasetResource {
        $evaluationDataset->update($request->validated());

        return EvaluationDatasetResource::make($evaluationDataset->load('cases'));
    }

    public function storeCase(
        StoreEvaluationCaseRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        EvaluationDataset $evaluationDataset,
    ): JsonResponse {
        $case = $evaluationDataset->cases()->create($request->validated());

        return EvaluationCaseResource::make($case)
            ->response()
            ->setStatusCode(201);
    }

    public function updateCase(
        UpdateEvaluationCaseRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        EvaluationDataset $evaluationDataset,
        EvaluationCase $evaluationCase,
    ): EvaluationCaseResource {
        if ($evaluationCase->evaluation_dataset_id !== $evaluationDataset->id) {
            abort(404);
        }

        $evaluationCase->update($request->validated());

        return EvaluationCaseResource::make($evaluationCase);
    }

    public function destroyCase(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        EvaluationDataset $evaluationDataset,
        EvaluationCase $evaluationCase,
    ): Response {
        Gate::authorize('update', $evaluationDataset);

        if ($evaluationCase->evaluation_dataset_id !== $evaluationDataset->id) {
            abort(404);
        }

        $evaluationCase->delete();

        return response()->noContent();
    }

    public function destroy(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        EvaluationDataset $evaluationDataset,
    ): Response {
        Gate::authorize('delete', $evaluationDataset);

        $evaluationDataset->delete();

        return response()->noContent();
    }
}
