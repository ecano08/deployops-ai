<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuildGroundedContextRequest;
use App\Http\Resources\GroundedContextResource;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Workspace;
use App\Services\GroundedContextBuilder;

class GroundedContextController extends Controller
{
    public function store(
        BuildGroundedContextRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        GroundedContextBuilder $builder,
    ): GroundedContextResource {
        $package = $builder->build(
            $request->user(),
            $deployment,
            $request->string('query')->toString(),
        );

        return GroundedContextResource::make($package);
    }
}
