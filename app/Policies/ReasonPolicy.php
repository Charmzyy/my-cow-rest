<?php

namespace App\Policies;

use App\Models\Reason;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReasonPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Reason  $reason
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, Reason $reason)
    {
        //
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
      if($user()->role==1){
        return true;
      }
      return false;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Reason  $reason
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, Reason $reason)
    {
        if($user()->role==1){
            return true;
        }
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Reason  $reason
     * @return \Illuminate\Auth\Access\Response|bool
     */
   

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Reason  $reason
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Reason $reason)
    {
        if($user()->role==1){
            return true;
        }
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Reason  $reason
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, Reason $reason)
    {
        //
    }
    public function deny(User $user,Reason $reason){
        return response()->json(["Message"=>"You cannot Access this Resource"],403);
    }
}
