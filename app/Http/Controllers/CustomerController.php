<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Customer::class, $workspace]);

        $customers = $workspace->customers()
            ->orderBy('name')
            ->get();

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request, Workspace $workspace): JsonResponse
    {
        $customer = $workspace->customers()->create([
            'name' => $request->validated('name'),
            'slug' => Customer::uniqueSlugFor($workspace, $request->validated('name')),
            'description' => $request->validated('description'),
        ]);

        return CustomerResource::make($customer)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Workspace $workspace, Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return CustomerResource::make($customer);
    }

    public function update(UpdateCustomerRequest $request, Workspace $workspace, Customer $customer): CustomerResource
    {
        $attributes = $request->safe()->only(['description']);

        if ($request->has('name')) {
            $attributes['name'] = $request->validated('name');
            $attributes['slug'] = Customer::uniqueSlugFor($workspace, $request->validated('name'), $customer->id);
        }

        $customer->update($attributes);

        return CustomerResource::make($customer);
    }

    public function destroy(Workspace $workspace, Customer $customer): Response
    {
        Gate::authorize('delete', $customer);

        $customer->delete();

        return response()->noContent();
    }
}
