<?php
// app/Http/Controllers/Admin/AdminAuthController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'login'    => ['required','string','max:190'], // email or username
            'password' => ['required','string'],
            'device'   => ['nullable','string','max:100'],
        ]);

        $admin = filter_var($request->login, FILTER_VALIDATE_EMAIL)
            ? Admin::where('email', $request->login)->first()
            : Admin::where('username', $request->login)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        }
        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        // Abilities: super admins get admin:manage, others get admin:basic
        $abilities = ['admin:basic'];
        if ($admin->is_super_admin) { $abilities[] = 'admin:manage'; }
        $token = $admin->createToken($request->input('device','admin-api'), $abilities)->plainTextToken;

        // dd($token);

        return response()->json([
            'token' => $token,
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'username' => $admin->username,
                'email' => $admin->email,
                'is_super_admin' => $admin->is_super_admin,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $a = $request->user(); // via sanctum
        return response()->json([
            'id' => $a->id,
            'name' => $a->name,
            'username' => $a->username,
            'email' => $a->email,
            'is_super_admin' => $a->is_super_admin,
            'last_login_at' => $a->last_login_at,
            'last_login_ip' => $a->last_login_ip,
        ]);
    }

    public function logout(Request $request)
    {
        /** @var \Laravel\Sanctum\PersonalAccessToken|null $token */
        $token = $request->user()->currentAccessToken();
        if ($token) {
            $token->delete(); // revoke current token
        }
        return response()->json(['message' => 'Token revoked']);
    }

    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete(); // revoke all tokens for this admin
        return response()->json(['message' => 'All tokens revoked']);
    }
}
