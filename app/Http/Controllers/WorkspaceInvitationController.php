<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Requests\AcceptWorkspaceInvitationRequest;
use App\Http\Requests\StoreWorkspaceInvitationRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceInvitationResource;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WorkspaceInvitationController extends Controller
{
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewInvitations', $workspace);

        $invitations = $workspace->invitations()
            ->pending()
            ->orderBy('email')
            ->get();

        return WorkspaceInvitationResource::collection($invitations);
    }

    public function store(StoreWorkspaceInvitationRequest $request, Workspace $workspace): JsonResponse
    {
        $email = $request->validated('email');
        $role = WorkspaceRole::from((string) $request->validated('role'));
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser) {
            $member = $workspace->addMemberWithRole($existingUser, $role);

            return WorkspaceMemberResource::make($member)
                ->response()
                ->setStatusCode(201);
        }

        $inviter = $request->user();

        if (! $inviter instanceof User) {
            abort(403);
        }

        $invitation = WorkspaceInvitation::inviteTo(
            $workspace,
            $email,
            $role,
            $inviter,
        );

        return WorkspaceInvitationResource::make($invitation)
            ->response()
            ->setStatusCode(201);
    }

    public function show(WorkspaceInvitation $workspaceInvitation): WorkspaceInvitationResource
    {
        abort_unless($workspaceInvitation->isAcceptable(), 410, 'This invitation is no longer valid.');

        return WorkspaceInvitationResource::make($workspaceInvitation->load('workspace'));
    }

    public function accept(AcceptWorkspaceInvitationRequest $request, WorkspaceInvitation $workspaceInvitation): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $workspaceInvitation): User {
            $invitation = WorkspaceInvitation::query()
                ->whereKey($workspaceInvitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($invitation->isAcceptable(), 410, 'This invitation is no longer valid.');
            abort_if($invitation->role === WorkspaceRole::Owner, 410, 'This invitation is no longer valid.');

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['An account with this email already exists.'],
                ]);
            }

            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $invitation->email,
                'password' => $request->validated('password'),
            ]);

            $invitation->workspace->addMemberWithRole($user, $invitation->role);

            return $user;
        });

        return UserResource::make($user)
            ->additional(['token' => $user->createToken('auth')->plainTextToken])
            ->response()
            ->setStatusCode(201);
    }
}
