<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PropertyImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'image_path',
    ];

    protected $appends = ['image_url'];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image_path)) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl(
            $this->image_path,
            Carbon::now()->addHour()
        );
    }
}

