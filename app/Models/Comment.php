<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory;
    protected $table = 'comments';
    protected $primarykey = 'id';
  
    protected $fillable = [
        'parent_id',
        'user_id',
        'task_id',
        'comment',
    ];
   
 
    public function parent(){
        return $this->belongsTo(Comment::class,'parent_id');
    }
    public function children(){
        return $this->hasMany(Comment::class,'parent_id');
    }
    public function task(){
        return $this->belongsTo(Task::class);
    }
}
