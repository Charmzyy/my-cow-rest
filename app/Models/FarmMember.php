<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// A person's role on one farm. The ABILITIES table below is the single place that decides
// what each role may do; policies and controllers ask Holding::allows($user, 'ability').
class FarmMember extends Model
{
    use HasFactory, HasUuids;

    public const OWNER = 'owner';
    public const MANAGER = 'manager';
    public const WORKER = 'worker';
    public const VET = 'vet';

    public const ABILITIES = [
        'herd.view' => [self::OWNER, self::MANAGER, self::WORKER, self::VET],
        'events.write' => [self::OWNER, self::MANAGER, self::WORKER, self::VET],   // health, milk, breeding records
        'animal.manage' => [self::OWNER, self::MANAGER],                           // add, edit, remove animals + photos
        'farm.update' => [self::OWNER, self::MANAGER],
        'team.manage' => [self::OWNER, self::MANAGER],                             // managers: workers only (see FarmMemberController)
        'farm.delete' => [self::OWNER],
        'certify' => [self::OWNER],                                                // paid service, billed to the owner
    ];

    protected $fillable = ['role'];

    public static function allows(?string $role, string $ability): bool
    {
        return $role !== null && in_array($role, self::ABILITIES[$ability] ?? [], true);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function holding()
    {
        return $this->belongsTo(Holding::class);
    }
}
