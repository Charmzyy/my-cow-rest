<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class County extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $fillable = ['id', 'name'];
}
