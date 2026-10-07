<?php

namespace App\Http\Controllers;

use App\Mail\ResetPassword;
use App\Models\User;
use App\Traits\HttpResponses;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Validation is done with $request->validate() OUTSIDE the try blocks, so a ValidationException
// reaches Laravel's handler and becomes a proper 422 { message, errors } response.
class AuthController extends Controller
{
    use HttpResponses;

    public function register(Request $request)
    {
        $validateData = $request->validate([
            'fullname' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|same:password_confirmation|min:8',
            'password_confirmation' => 'required|min:8',
        ]);

        try {
            $user = User::create([
                'fullname' => $validateData['fullname'],
                'email' => $validateData['email'],
                'password' => Hash::make($validateData['password']),
            ]);

            if (User::count() == 1) {
                $user->role = 1; // the first account becomes the admin (officer)
                $user->save();
            }

            $token = $user->createToken('token')->plainTextToken;

            return response()->json([
                'user' => $user,
                'token' => $token,
            ], 201);
        } catch (\Throwable $th) {
            report($th);
            return response()->json(['message' => 'Could not create the account.'], 500);
        }
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        try {
            if (!Auth::attempt($credentials)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            /** @var User $user  Auth::user() is typed as the Authenticatable interface; it's our User model */
            $user = Auth::user();

            if ($user->role == 1) {
                $roleArray = ['admin'];
            } elseif ($user->role == 0) {
                $roleArray = ['user'];
            } else {
                return response()->json(['message' => 'Unknown account type'], 403);
            }

            $token = $user->createToken('token')->plainTextToken;

            return response()->json([
                'user' => $user,
                'role' => $roleArray,
                'token' => $token,
            ], 201);
        } catch (\Throwable $th) {
            report($th);
            return response()->json(['message' => 'Could not sign in.'], 500);
        }
    }

    public function logout(Request $request)
    {
        // API tokens only: there is no session on api routes, so just revoke the token in use
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout successful'], 200);
    }

    public function forgot(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users',
        ]);

        try {
            $token = Str::random(64);

            DB::table('password_resets')->insert([
                'email' => $request->email,
                'token' => $token,
                'created_at' => Carbon::now(),
            ]);

            $data = [
                'email' => $request->email,
                'token' => $token,
                'message' => 'New Password Credentials Sent',
            ];

            Mail::to($request->email)->send(new ResetPassword($data));

            // Never return the token itself: it must only reach the account owner by email
            return response()->json(['message' => 'Reset code sent'], 201);
        } catch (\Throwable $th) {
            report($th);
            return response()->json(['message' => 'Could not send the reset email.'], 500);
        }
    }

    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        try {
            $updatePassword = DB::table('password_resets')
                ->where([
                    'email' => $request->email,
                    'token' => $request->token,
                ])
                ->first();

            if (!$updatePassword) {
                return response()->json(['message' => 'Invalid reset code'], 404);
            }

            User::where('email', $request->email)
                ->update(['password' => Hash::make($request->password)]);

            DB::table('password_resets')->where(['email' => $request->email])->delete();

            return response()->json(['message' => 'Password Reset'], 201);
        } catch (\Throwable $th) {
            report($th);
            return response()->json(['message' => 'Could not reset the password.'], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        $validatedData = $request->validate([
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|image|max:2048',
        ]);

        if ($request->has('phone')) {
            $user->phone = $validatedData['phone'];
        }

        if ($request->hasFile('avatar')) {
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        return response()->json(['message' => 'Phone and/or avatar updated successfully', 'user' => $user], 200);
    }
}
