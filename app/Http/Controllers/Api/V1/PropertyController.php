<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Property\DuplicatePropertyException;
use App\Services\Property\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PropertyController extends Controller
{
    public function __construct(
        protected PropertyService $propertyService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'agent_id' => ['required', 'integer', 'exists:agents,id'],
        ]);

        try {
            $property = $this->propertyService->createProperty(
                data: [
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'price' => $validated['price'],
                    'address' => $validated['address'],
                    'city' => $validated['city'],
                    'country' => $validated['country'],
                ],
                agentId: $validated['agent_id']
            );
        } catch (DuplicatePropertyException $e) {
            return response()->json([
                'message' => 'Duplicate property for this agent at this address in this city.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => $property->load('images'),
        ], Response::HTTP_CREATED);
    }

    public function uploadImages(Request $request, int $id): JsonResponse
    {
        $property = Property::findOrFail($id);

        $validated = $request->validate([
            'images' => ['required', 'array'],
            'images.*' => ['file', 'image', 'max:5120'],
        ]);

        $this->propertyService->queuePropertyImages($property, $validated['images']);

        return response()->json([
            'message' => 'Images are being processed.',
        ], Response::HTTP_ACCEPTED);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['city', 'country', 'agent_id']);
        $perPage = (int) $request->get('per_page', 15);

        $paginator = $this->propertyService->listProperties($filters, $perPage);

        return response()->json($paginator);
    }
}

