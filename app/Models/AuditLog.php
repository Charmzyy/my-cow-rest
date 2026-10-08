<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;   // append-only

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'data'];
    protected $casts = ['data' => 'array'];

    public static function record(string $action, Model $subject, array $data = []): void
    {
        static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => class_basename($subject),
            'subject_id' => $subject->getKey(),
            'data' => $data ?: null,
        ]);
    }
}
