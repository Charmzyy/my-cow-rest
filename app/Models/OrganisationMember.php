<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A person's role in an organisation. Owners and admins run the workspace (name, branding,
// people, which farms are in it); members just work in it.
class OrganisationMember extends Model
{
    use HasUuids;

    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const MEMBER = 'member';
    public const ROLES = [self::OWNER, self::ADMIN, self::MEMBER];
    public const MANAGERS = [self::OWNER, self::ADMIN];

    protected $fillable = ['user_id', 'role'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }
}
