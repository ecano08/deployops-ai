<?php

namespace App\Http\Controllers;

use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use App\Http\Requests\StoreAiProposedActionRequest;
use App\Http\Resources\AiProposedActionResource;
use App\Models\AiProposedAction;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Workspace;
use App\Services\AiActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AiProposedActionController extends Controller
{
    public function index(Workspace $workspace, Customer $customer, Deployment $deployment): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [AiProposedAction::class, $deployment]);

        $actions = $deployment->aiProposedActions()
            ->with('auditEvents')
            ->orderByDesc('created_at')
            ->get();

        return AiProposedActionResource::collection($actions);
    }

    public function store(
        StoreAiProposedActionRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        AiActionService $aiActionService,
    ): JsonResponse {
        $action = $aiActionService->propose(
            requester: $request->user(),
            deployment: $deployment,
            actionType: AiActionType::from($request->validated('action_type')),
            payload: $request->validated('payload'),
        );

        return AiProposedActionResource::make($action->load('auditEvents'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        AiProposedAction $aiProposedAction,
    ): AiProposedActionResource {
        Gate::authorize('view', $aiProposedAction);

        return AiProposedActionResource::make($aiProposedAction->load('auditEvents'));
    }

    public function approve(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        AiProposedAction $aiProposedAction,
        AiActionService $aiActionService,
    ): AiProposedActionResource {
        $action = $aiActionService->approve($aiProposedAction, request()->user());

        return AiProposedActionResource::make($action);
    }

    public function reject(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        AiProposedAction $aiProposedAction,
        AiActionService $aiActionService,
    ): AiProposedActionResource {
        $action = $aiActionService->reject($aiProposedAction, request()->user());

        return AiProposedActionResource::make($action);
    }

    public function pending(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', [AiProposedAction::class, $deployment]);

        $actions = $deployment->aiProposedActions()
            ->where('status', AiActionStatus::Pending)
            ->with('auditEvents')
            ->orderBy('created_at')
            ->get();

        return AiProposedActionResource::collection($actions);
    }
}
