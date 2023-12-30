<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;

use Illuminate\Support\Str;
use App\Traits\HttpResponses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\ResetPassword;
use App\Mail\NewPassword;
use PhpParser\Node\Stmt\Return_;
use Stringable;

class AuthController extends Controller
{
    use HttpResponses;


    
    public function register(Request $request){
        try {
            //code...
            $validateData = $request->validate([
                'fullname'=> 'required',
                'email'=> 'required|email|unique:users',
                'password'=>'required|same:password_confirmation|min:8',
                'password_confirmation' => 'required|min:8'
            ]);
          $user = User::create([
            'fullname'=>$validateData['fullname'],
            'email'=>$validateData['email'],
            'password'=>Hash::make($validateData['password'])
          ]);
          if (User::count() == 1) {
            $user->role = 1; // Set default role to 1 for the first user added
            $user->save();
        }
          $token = $user->createToken('token')->plainTextToken;
    
          
          
          return response()->json([
              'user' => $user,
              'token' => $token
              
    
          ],201);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json([$th->getMessage()]);
        }
       
    }
    public function login(Request $request)
{//change error code to give unauthorized 401 error not 500
    try {
        //code...
        $credentials = $request->only(['email', 'password']);
        error_log(json_encode($credentials));
        if (Auth::attempt($credentials)) {
             $user = auth()->user();
             $token = $user->createToken('token')->plainTextToken;
             if(Auth::user()->role ==1){
             
              $roleArray = ['admin'];
             }
             elseif(Auth::user()->role==0){
               
                $roleArray = ['user'];
             }
             
             else{
                return response()->json([
                    "Message"=>"Not Sure which user type you are"
                ],403);
             }
             
             return response()->json([ 'user' => $user,
             'role'=>$roleArray,
             'token' => $token],201);
        }
return response()->json(['Message'=>'invalid credentails'],401);

       
    } catch (\Throwable $th) {
        //throw $th;
        return response()->json([$th->getMessage()],500);
    }
   

  

   
}

public function remember($id){
    try {
        $user = User::find($id);
        if(!$user){
            return response()->json(['Message'=>'No such user'],403);
        }
        $user->setRememberToken(Str::random(60)); // Generate a new remember token
        $user->save();
        return response()->json(['Message'=>'Remember token set successfully']);
    } catch (\Throwable $th) {
        return response()->json([$th->getMessage()]);
    }
}

    public function logout(Request $request)
    {
        try {
            Auth::user()->currentAccessToken()->delete();
            Auth::user()->tokens()->delete();
            session()->invalidate();
            
               
                return response()->json(['message' => 'Logout successful'], 200);
            
            
        } catch (\Throwable $th) {
            return response()->json([$th->getMessage()]);
        }
    }
    
    

  
public function forgot(Request $request)
{
    try {
        //code...
        $request->validate([
            'email' => 'required|email|exists:users',
        ]);
    
        $token = Str::random(64);
    
        DB::table('password_resets')->insert([
            'email' => $request->email, 
            'token' => $token, 
            'created_at' => Carbon::now()
          ]);
        
       
        $data =[
            'email'=>$request->email,
'token'=>$token,
'message'=>"New Password Credentials Sent",

        ];

        Mail::to($request->email)->send(new ResetPassword($data)); 
    
        return response()->json([$data],201);
      
    } catch (\Throwable $th) {
        //throw $th;
        return response()->json([
            $th->getMessage()
        ],500);
    }
    
}
public function reset(Request $request)
      {
        try {
            //code...
            $request->validate([
                'email' => 'required|email|exists:users',
                'password' => 'required|string|min:8|confirmed',
                'password_confirmation' => 'required'
            ]);
    
            $updatePassword = DB::table('password_resets')
                                ->where([
                                  'email' => $request->email, 
                                  'token' => $request->token
                                ])
                                ->first();
    
            if(!$updatePassword){
                return response()->json(["Message"=>"Error in password Rest"],404);
            }
    
            $user = User::where('email', $request->email)
                        ->update(['password' => Hash::make($request->password)]);
   
            DB::table('password_resets')->where(['email'=> $request->email])->delete();
    
           return response()->json([
              "Message"=>"Password Reset"
           ],201);
        
        } catch (\Throwable $th) {
            //throw $th;
return response()->json([$th->getMessage()],500);
        }
         
 }
 
 public function updateProfile(Request $request)
{
    $user = Auth::user(); // Get the authenticated user
    
    // Validate incoming request data for phone and avatar
    $validatedData = $request->validate([
        'phone' => 'nullable|string|max:20',
        'avatar' => 'nullable|image|max:2048', // Validate avatar as an image file
    ]);

    // Update user profile with phone if provided
    if ($request->has('phone')) {
        $user->phone = $validatedData['phone'];
    }

    // Handle avatar upload if provided
    if ($request->hasFile('avatar')) {
        $avatar = $request->file('avatar');
        $avatarPath = $avatar->store('avatars', 'public'); // Store the avatar
        $user->avatar = $avatarPath; // Save avatar path to the user
    }

    // Save changes to the user profile
    $user->save();

    return response()->json(['message' => 'Phone and/or avatar updated successfully', 'user' => $user], 200);
}





}






