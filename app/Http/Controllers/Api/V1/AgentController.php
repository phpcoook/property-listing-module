<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Agent\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgentController extends Controller
{
    public function __construct(
        protected AgentService $agentService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:agents,email'],
        ]);

        $agent = $this->agentService->createAgent($validated);

        return response()->json([
            'data' => $agent,
        ], Response::HTTP_CREATED);
    }
}
