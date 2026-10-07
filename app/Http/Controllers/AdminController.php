<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

use Illuminate\Http\Request;
use App\Models\User;

use Illuminate\Support\Facades\Mail;

use App\Mail\RejectedMail;
use App\Mail\AcceptedMail;

use App\Models\UserPost;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

     
     public function users(){
        $users =User::all();
        if ($users->isEmpty()) {
            return response()->json(["Message"=>'No new users'],404);
        } 
        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'id'=>$user->id,
                'name' => $user->fullname, // column was renamed name -> fullname
                'email'=>$user->email,
                'role'=>$user->role,
                
               
                
            ];
        }
return response()->json($data,200);
        
        
     }
     
     public function getPosts($status = null)
{
    try {
        $query = UserPost::query();
        
        // Filter posts based on status
        if ($status === 'unverified') {
            $query->whereNull('is_verified');
        } elseif ($status === 'verified') {
            $query->where('is_verified', 1);
        } elseif ($status !== null) {
            return response()->json(['message' => 'Invalid status provided'], 400);
        }
        
        $posts = $query->get(); // Fetch all items without pagination
        
        if ($posts->isEmpty()) {
            return response()->json(['message' => 'No Posts'], 404);
        }
        
        $data = $posts->map(function ($post) {
            return [
                'id' => $post->id,
                'cow_name' => $post->cow_name,
                'predicted_class' => $post->predicted_class,
                'confidence' => $post->confidence,
                'image' => $post->image,
                'owner' => $post->user->fullname,
            ];
        });
        
        return response()->json([
            'posts' => $data,
            'message' => 'Posts retrieved successfully'
        ], 200);
    } catch (\Throwable $th) {
        return response()->json(['message' => $th->getMessage()], 500);
    }
}
     
     
   

    
    
   
    public function accept($id){
        //first selection
try {
    //code...
    $post = UserPost::find($id);
    if(!$post){
        return response()->json(['Message'=>'Post Not Found'],404);
    }
    $post->is_verified =true;
    $post->save();
    $data =[
'post'=>$post,
'image' => $post->image,
'Message'=>"Verified Cattle "

    ];

    // The decision is already saved; a mail outage must not turn it into an error
    try {
        Mail::to($post->user->email)->send(new AcceptedMail($data));
    } catch (\Throwable $mailError) {
        report($mailError);
    }
    return response()->json(
        $data
    ,201);
   
 
} catch (\Throwable $th) {
    //throw $th;
    return response()->json([ $th->getMessage()],500);
   
}
       
    }
    public function reject(Request $request, $id)
    {
        try {
            $post = UserPost::findOrFail($id);
            
            if (!$post) {
                return response()->json([
                    'Message' => 'Post Not Found'
                ], 404);
            }
            
            $post->is_verified = false;
            $post->reason = $request->input('reason');
            $post->save();
            
            $data = [
                'post' => $post,
                'Message' => "Rejected Cow"
            ];
            
            try {
                Mail::to($post->user->email)->send(new RejectedMail($data));
            } catch (\Throwable $mailError) {
                report($mailError);
            }
            
            return response()->json($data, 201);
        } catch (\Throwable $th) {
            return response()->json([$th->getMessage()], 500);
        }
    }
    public function delete($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();

            return response()->json(['message' => 'User deleted successfully'], 200);
            
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    

    
    
}
    

        



    


