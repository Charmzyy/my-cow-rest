<?php

namespace App\Http\Controllers;

use App\Mail\ResetPassword;
use App\Models\User;
use App\Support\Phone;
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
                $user->role = User::ROLE_ADMIN; // the very first account becomes the platform admin
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

            if (!in_array($user->role, User::ROLES, true)) {
                return response()->json(['message' => 'Unknown account type'], 403);
            }
            $roleArray = [$user->role];

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

    // Farm workers: phone + PIN instead of email + password. Same response shape as login().
    public function loginWithPin(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'pin' => 'required|string|max:6',
        ]);

        $phone = Phone::normalize($data['phone']);
        $user = $phone ? User::where('login_phone', $phone)->first() : null;
        if (!$user || !Hash::check($data['pin'], $user->password)) {
            return response()->json(['message' => 'That phone number and PIN don’t match.'], 401);
        }
        if (!$user->memberships()->exists()) {
            return response()->json(['message' => 'You’re not on any farm’s team now. Ask the farmer to add you again.'], 403);
        }

        return response()->json([
            'user' => $user,
            'role' => [$user->role],
            'token' => $user->createToken('pin')->plainTextToken,
        ], 201);
    }

    // The signed-in user's current record + role, so the app picks up role changes
    // (e.g. an officer application being approved) without signing out.
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json(['user' => $user, 'role' => [$user->role]]);
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
