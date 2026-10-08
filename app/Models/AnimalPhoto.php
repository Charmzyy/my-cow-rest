<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AnimalPhoto extends Model
{
    use HasUuids;

    public const ANGLES = ['left', 'right', 'front', 'rear', 'face', 'ear_tag', 'udder'];

    // Only whole-body / head shots go through the breed model; tag and udder close-ups don't
    public const AI_ANGLES = ['left', 'right', 'front', 'rear', 'face'];

    protected $fillable = ['animal_id', 'angle', 'path', 'image_hash', 'ai_breed', 'ai_confidence'];
    protected $hidden = ['image_hash'];
    protected $casts = ['ai_confidence' => 'float'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
