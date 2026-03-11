<?php

namespace App\Jobs;

use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessPropertyImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $propertyId,
        public string $localPath,
        public string $originalName
    ) {
        $this->queue = 'property-images';
    }

    public function handle(): void
    {
        $property = Property::find($this->propertyId);

        if (! $property) {
            Log::warning('property_image_upload_property_missing', [
                'property_id' => $this->propertyId,
                'local_path' => $this->localPath,
            ]);

            return;
        }

        try {
            $disk = Storage::disk('s3');

            $s3Path = $disk->putFileAs(
                "properties/{$this->propertyId}",
                Storage::disk('local')->path($this->localPath),
                $this->originalName
            );

            PropertyImage::create([
                'property_id' => $this->propertyId,
                'image_path' => $s3Path,
            ]);

            Storage::disk('local')->delete($this->localPath);
        } catch (\Throwable $e) {
            Log::error('property_image_upload_failed', [
                'property_id' => $this->propertyId,
                'local_path' => $this->localPath,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

