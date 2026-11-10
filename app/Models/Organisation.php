<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// The tenant: a co-op, a company, or one farmer's "personal" workspace. It groups farms and
// people; what someone may do on a farm still comes from FarmMember::ABILITIES.
class Organisation extends Model
{
    use HasFactory, HasUuids;

    public const PERSONAL = 'personal';
    public const COOP = 'coop';
    public const COMPANY = 'company';
    public const TYPES = [self::PERSONAL, self::COOP, self::COMPANY];

    protected $fillable = ['name', 'type'];

    protected $attributes = ['type' => self::PERSONAL, 'plan' => 'free'];

    protected $hidden = ['logo_path'];

    protected static function booted()
    {
        static::creating(function (Organisation $org) {
            $org->slug ??= Str::slug($org->name) . '-' . Str::lower(Str::random(6));
        });
    }

    /** The user's own workspace, made the first time they need one (e.g. their first farm). */
    public static function personalFor(User $user): self
    {
        $existing = static::where('type', self::PERSONAL)->where('created_by', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        $org = new static(['name' => trim(($user->fullname ?: 'My') . '’s farms'), 'type' => self::PERSONAL]);
        $org->created_by = $user->id;
        $org->save();
        $org->addMember($user, OrganisationMember::OWNER);

        return $org;
    }

    public function members()
    {
        return $this->hasMany(OrganisationMember::class);
    }

    public function holdings()
    {
        return $this->hasMany(Holding::class);
    }

    /** The user's role here (owner / admin / member), or null. */
    public function roleOf(User $user): ?string
    {
        return $this->members()->where('user_id', $user->id)->value('role');
    }

    public function isManagedBy(User $user): bool
    {
        return in_array($this->roleOf($user), OrganisationMember::MANAGERS, true);
    }

    /** Adds the user if they aren't in yet; an existing role is never changed here. */
    public function addMember(User $user, string $role = OrganisationMember::MEMBER, ?User $by = null): OrganisationMember
    {
        $member = $this->members()->firstOrNew(['user_id' => $user->id]);
        if (!$member->exists) {
            $member->forceFill(['role' => $role, 'added_by' => $by?->id])->save();
        }

        return $member;
    }
}
