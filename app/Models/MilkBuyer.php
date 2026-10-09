<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A co-operative / dairy or local buyer a farm sells milk to, with their usual price (KES per litre).
class MilkBuyer extends Model
{
    use HasUuids;

    public const KINDS = ['coop', 'local'];

    protected $fillable = ['name', 'kind', 'price_per_litre', 'active'];
    protected $casts = ['price_per_litre' => 'float', 'active' => 'boolean'];
}
