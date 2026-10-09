<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Where some of a day's milk went: delivered to a co-op, sold locally, kept for the home, or fed to calves.
class MilkDelivery extends Model
{
    use HasUuids;

    public const KINDS = ['coop', 'sale', 'home', 'calves'];
    public const SOLD = ['coop', 'sale'];   // the kinds that earn money

    protected $fillable = ['day', 'session', 'kind', 'buyer_id', 'litres', 'rejected_litres', 'price_per_litre', 'paid', 'note'];
    protected $casts = [
        'day' => 'date:Y-m-d',
        'litres' => 'float',
        'rejected_litres' => 'float',
        'price_per_litre' => 'float',
        'paid' => 'boolean',
    ];
    protected $appends = ['amount'];

    public function buyer()
    {
        return $this->belongsTo(MilkBuyer::class);
    }

    /** KES earned: accepted litres × price. Null when there's no price (home, calves, or not yet known). */
    public function getAmountAttribute(): ?float
    {
        return $this->price_per_litre === null ? null : round(($this->litres - $this->rejected_litres) * $this->price_per_litre, 2);
    }
}
