<?php

namespace App\Services\Property;

use App\Jobs\ProcessPropertyImage;
use App\Models\Property;
use App\Repositories\PropertyImageRepository;
use App\Repositories\PropertyRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DuplicatePropertyException extends \RuntimeException
{
    public function __construct(string $message = 'Duplicate property', int $code = Response::HTTP_CONFLICT)
    {
        parent::__construct($message, $code);
    }
}

class PropertyService
{
    public function __construct(
        protected PropertyRepository $propertyRepository,
        protected PropertyImageRepository $propertyImageRepository,
    ) {
    }

    public function createProperty(array $data, int $agentId): Property
    {
        $data['agent_id'] = $agentId;

        DB::beginTransaction();

        try {
            $property = $this->propertyRepository->create($data);

            DB::commit();

            Log::info('property_created', [
                'property_id' => $property->id,
                'agent_id' => $agentId,
                'address' => $property->address,
                'city' => $property->city,
            ]);

            return $property;
        } catch (QueryException $e) {
            DB::rollBack();

            if ($this->isDuplicatePropertyException($e)) {
                Log::warning('duplicate_property_rejected', [
                    'agent_id' => $agentId,
                    'address' => $data['address'] ?? null,
                    'city' => $data['city'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                throw new DuplicatePropertyException();
            }

            throw $e;
        }
    }

    public function listProperties(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->propertyRepository->paginateWithImages($filters, $perPage);
    }

    /**
     * @param Property $property
     * @param UploadedFile[] $files
     */
    public function queuePropertyImages(Property $property, array $files): void
    {
        foreach ($files as $file) {
            $localPath = $file->store('tmp/property-images', 'local');

            ProcessPropertyImage::dispatch(
                propertyId: $property->id,
                localPath: $localPath,
                originalName: $file->getClientOriginalName()
            )->onQueue('property-images');
        }
    }

    protected function isDuplicatePropertyException(QueryException $e): bool
    {
        if ($e->getCode() !== '23505') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'properties_agent_address_city_unique');
    }
}

