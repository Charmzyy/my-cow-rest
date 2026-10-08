<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasUuids;

    public const ROLE_FARMER = 'farmer';
    public const ROLE_OFFICER = 'officer';     // verified vet / extension officer: certifies animals
    public const ROLE_ORG_ADMIN = 'org_admin'; // runs a cooperative / organisation account
    public const ROLE_ADMIN = 'admin';         // platform staff
    public const ROLES = [self::ROLE_FARMER, self::ROLE_OFFICER, self::ROLE_ORG_ADMIN, self::ROLE_ADMIN];

    protected $primarykey = 'id';
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     * 'role' is deliberately NOT here: it is only ever set explicitly, so a signup
     * request can never make itself an admin.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fullname',
        'email',
        'password',
        'phone',
        'avatar',
    ];

    protected $attributes = [
        'role' => self::ROLE_FARMER,
    ];

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];




 
  public function posts(){
    return $this->hasMany(UserPost::class);
  }

  public function animals()
  {
    return $this->hasMany(Animal::class, 'owner_id');
  }

  public function officerProfile()
  {
    return $this->hasOne(OfficerProfile::class);
  }

  public function isApprovedOfficer(): bool
  {
    return $this->role === self::ROLE_OFFICER && $this->officerProfile?->status === 'approved';
  }
  
  

  
     
}
