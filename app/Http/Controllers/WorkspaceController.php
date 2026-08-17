<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspaces = $request->user()
            ->workspaces()
            ->with('owner')
            ->latest()
            ->get();

        return WorkspaceResource::collection($workspaces);
    }

    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $workspace = DB::transaction(function () use ($request): Workspace {
            $workspace = Workspace::create([
                'name' => $request->validated('name'),
                'slug' => Workspace::uniqueSlugFor($request->validated('name')),
                'owner_id' => $request->user()->id,
            ]);

            $workspace->members()->attach($request->user());

            return $workspace;
        });

        return WorkspaceResource::make($workspace->load('owner'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Workspace $workspace): WorkspaceResource
    {
        Gate::authorize('view', $workspace);

        return WorkspaceResource::make($workspace->load('owner'));
    }
}
