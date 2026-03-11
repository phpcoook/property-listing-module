<?php

namespace App\Repositories;

use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Support\Collection;

class PropertyImageRepository
{
    /**
     * @param Property $property
     * @param array<string> $paths
     * @return Collection<int, PropertyImage>
     */
    public function createManyForProperty(Property $property, array $paths): Collection
    {
        $records = collect($paths)->map(function (string $path) use ($property) {
            return [
                'property_id' => $property->id,
                'image_path' => $path,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();

        PropertyImage::insert($records);

        return $property->images()->whereIn('image_path', $paths)->get();
    }
}

