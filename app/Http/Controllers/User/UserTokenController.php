<?php
// app/Http/Controllers/User/UserTokenController.php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserTokenController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'login'    => ['required','string','max:190'], // email OR username
            'password' => ['required','string'],
            'device'   => ['nullable','string','max:100'],
        ]);

        // 1) Pull candidate users by login (email or username), across ALL customers
        $candidates = filter_var($request->login, FILTER_VALIDATE_EMAIL)
            ? User::where('email', $request->login)->get()
            : User::where('username', $request->login)->get();

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        }

        // 2) Keep only those with matching password
        $matched = $candidates->filter(fn(User $u) => Hash::check($request->password, $u->password))->values();

        if ($matched->isEmpty()) {
            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        }

        if ($matched->count() > 1) {
            // Same login+password exists under multiple customers → ambiguous
            return response()->json([
                'message' => 'Multiple accounts found for this login. Please contact support or use a unique login.',
                'hint'    => 'The same email/username exists in more than one customer.',
            ], 422);
        }

        /** @var User $user */
        $user = $matched->first();

        // 3) Ensure the user’s customer is valid (exists & not soft-deleted)
        if (!$user->customer || ($user->customer->deleted_at ?? null) !== null) {
            return response()->json(['message' => 'Customer is not available for login.'], 403);
        }
        // Optional: if packages are soft-deletable and must be active, also check:
        if ($user->customer->package && method_exists($user->customer->package, 'trashed') && $user->customer->package->trashed()) {
            return response()->json(['message' => 'Customer package is not available for login.'], 403);
        }

        // 4) Build role-based abilities
        $abilities = ['user:screens:read'];

        if ($user->role === 'manager') {
            $abilities = array_merge($abilities, [
                'user:screens:assign',      // per-screen + bulk (company or selected)
                'user:screens:broadcast',   // refresh WS per-screen + bulk
                'user:playlist:write',      // (optional) if you let managers CRUD playlists/items
                'user:playlist:items:write',
                'user:playlist:reorder',
            ]);
        }

        if ($user->role === 'supervisor') {
            // only for screens assigned to that supervisor
            $abilities = array_merge($abilities, [
                'user:screens:assign',
                'user:screens:broadcast',
            ]);
        }

        $token = $user->createToken($request->input('device','user-api'), $abilities);

        // telemetry (optional)
        $user->forceFill(['last_login_at'=>now(),'last_login_ip'=>$request->ip()])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => [
                'id' => $user->id,
                'customer_id' => $user->customer_id,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'abilities' => $abilities,
        ]);
    }

    public function me(Request $request)
    {
        $u = $request->user();
        return response()->json([
            'id'=>$u->id,'customer_id'=>$u->customer_id,'username'=>$u->username,
            'email'=>$u->email,'role'=>$u->role,'last_login_at'=>$u->last_login_at,'last_login_ip'=>$u->last_login_ip,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Token revoked']);
    }

    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'All tokens revoked']);
    }
}
