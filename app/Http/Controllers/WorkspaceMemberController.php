<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Requests\StoreWorkspaceMemberRequest;
use App\Http\Requests\UpdateWorkspaceMemberRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class WorkspaceMemberController extends Controller
{
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewMembers', $workspace);

        $members = $workspace->members()
            ->orderBy('name')
            ->get();

        return WorkspaceMemberResource::collection($members);
    }

    public function store(StoreWorkspaceMemberRequest $request, Workspace $workspace): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->firstOrFail();

        $member = $workspace->addMemberWithRole(
            $user,
            WorkspaceRole::from((string) $request->validated('role')),
        );

        return WorkspaceMemberResource::make($member)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateWorkspaceMemberRequest $request, Workspace $workspace, User $member): WorkspaceMemberResource
    {
        $workspace->members()->updateExistingPivot($member->id, [
            'role' => $request->validated('role'),
        ]);

        $updated = $workspace->members()->whereKey($member->id)->firstOrFail();

        return WorkspaceMemberResource::make($updated);
    }

    public function destroy(Workspace $workspace, User $member): Response
    {
        Gate::authorize('removeMember', [$workspace, $member]);

        $workspace->members()->detach($member->id);

        return response()->noContent();
    }
}
