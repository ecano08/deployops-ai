<?php

namespace App\Http\Controllers;

use App\Exceptions\CopilotException;
use App\Http\Requests\CopilotAskRequest;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Workspace;
use App\Services\CopilotContext;
use App\Services\CopilotService;
use Illuminate\Http\JsonResponse;

class CopilotController extends Controller
{
    public function store(
        CopilotAskRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        CopilotService $copilot,
    ): JsonResponse {
        try {
            $result = $copilot->ask(
                new CopilotContext(
                    user: $request->user(),
                    workspace: $workspace,
                    customer: $customer,
                    deployment: $deployment,
                ),
                $request->validated('message'),
                $request->validated('history', []),
            );
        } catch (CopilotException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reference' => $exception->reference,
            ], $exception->statusCode);
        }

        return response()->json([
            'data' => [
                'answer' => $result['answer'],
                'tools_used' => $result['tools_used'],
            ],
        ]);
    }
}
