<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Auth;

class RolePolicy
{
    use HandlesAuthorization;

    /**
     * Create a new policy instance.
     *
     * @return void
     */
   
    public function create(User $user){
     if($user()->role==1){
        return true;
     }
     return false;
       
    }
    public function delete(User $user,Role $role){
     if($user()->role==1){
        return true;
     }
     return false;
    }
     public function update(User $user){
        if($user()->role==1){
            return true;
         }
         return false;
     }
     public function deny(User $user ,Role $role){
        return response()->json(["Message"=>"Cannot Access this reasource"],403);
     }

}
