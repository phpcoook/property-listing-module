<?php

namespace App\Repositories;

use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PropertyRepository
{
    public function create(array $data): Property
    {
        return Property::create($data);
    }

    public function paginateWithImages(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Property::query()->with('images');

        if (isset($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        if (isset($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        if (isset($filters['country'])) {
            $query->where('country', $filters['country']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function findByIdWithImages(int $id): ?Property
    {
        return Property::with('images')->find($id);
    }
}

