<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPost extends Model
{
    use HasFactory;
    use HasUuids;
    protected $table='userposts';
    protected $primarykey = 'id';
    public $incrementing = false;
    protected $fillable =[
        'user_id',
        'image',
        'predicted_class',
        'confidence',
        'is_verified',
        'reason',
        'cow_name'
];

public function user(){
        return $this->belongsTo(User::class);
    }
}
