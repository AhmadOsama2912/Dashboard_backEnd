<?php
// app/Http/Controllers/User/UserTokenController.php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserTokenController extends Controller
{
    private function metaArray($meta): array
    {
        if (is_array($meta)) return $meta;

        if (is_object($meta)) return (array) $meta;

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function saveMeta(User $u, array $meta): void
    {
        // If User model has cast for meta, store as array. Otherwise store JSON string.
        $casts = method_exists($u, 'getCasts') ? $u->getCasts() : [];
        $metaCast = $casts['meta'] ?? null;

        if (in_array($metaCast, ['array', 'json', 'object', 'collection'], true)) {
            $u->meta = $meta;
        } else {
            $u->meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'login'    => ['required', 'string', 'max:190'], // email OR username
            'password' => ['required', 'string'],
            'device'   => ['nullable', 'string', 'max:100'],
        ]);

        $login = trim((string) $request->input('login'));
        $password = (string) $request->input('password');
        $deviceName = (string) $request->input('device', 'user-api');

        // Find candidates by email OR username
        $query = User::query();

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $query->where('email', $login);
        } else {
            $query->where('username', $login);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        }

        // Match password
        $matched = $candidates
            ->filter(fn (User $u) => Hash::check($password, (string) $u->password))
            ->values();

        if ($matched->isEmpty()) {
            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        }

        // Prevent ambiguous login across tenants
        if ($matched->count() > 1) {
            return response()->json([
                'message' => 'Multiple accounts found for this login. Please contact support or use a unique login.',
                'hint'    => 'The same email/username exists in more than one customer.',
            ], 422);
        }

        /** @var User $user */
        $user = $matched->first();

        // Ensure tenant/package is valid
        $user->loadMissing(['customer', 'customer.package']);

        if (!$user->customer || ($user->customer->deleted_at ?? null) !== null) {
            return response()->json(['message' => 'Customer is not available for login.'], 403);
        }

        if ($user->customer->package
            && method_exists($user->customer->package, 'trashed')
            && $user->customer->package->trashed()
        ) {
            return response()->json(['message' => 'Customer package is not available for login.'], 403);
        }

        /**
         * Abilities are derived from model policy:
         * - role defaults + DB overrides + legacy mapping
         */
        $abilities = $user->effectiveAbilities();
        $abilities = array_values(array_unique(array_map(
            fn ($a) => strtolower(trim((string) $a)),
            $abilities
        )));

        // Optional (recommended for security): revoke previous tokens on login
        // If you need multi-device tokens, remove this line.
        $user->tokens()->delete();

        $token = $user->createToken($deviceName, $abilities);

        // telemetry
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $meta = $this->metaArray($user->meta);

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => [
                'id'            => $user->id,
                'customer_id'   => $user->customer_id,
                'username'      => $user->username,
                'email'         => $user->email,
                'phone'         => $user->phone,
                'role'          => $user->role,
                'roles'         => [$user->role],
                'meta'          => $meta,
                'locale'        => $meta['locale'] ?? null,
                'notes'         => $meta['notes'] ?? null,
                'created_at'    => $user->created_at,
                'last_login_at' => $user->last_login_at,
                'last_login_ip' => $user->last_login_ip,
            ],
            'abilities' => $abilities,
        ]);
    }

    public function me(Request $request)
    {
        $u = $request->user();
        $token = $u->currentAccessToken();

        $meta = $this->metaArray($u->meta);

        return response()->json([
            'id'            => $u->id,
            'customer_id'   => $u->customer_id,
            'username'      => $u->username,
            'email'         => $u->email,
            'phone'         => $u->phone,
            'role'          => $u->role,
            'roles'         => [$u->role],
            'meta'          => $meta,
            'locale'        => $meta['locale'] ?? null,
            'notes'         => $meta['notes'] ?? null,
            'created_at'    => $u->created_at,
            'last_login_at' => $u->last_login_at,
            'last_login_ip' => $u->last_login_ip,
            'abilities'     => $token ? $token->abilities : [],
        ]);
    }

    /**
     * PATCH /api/user/v1/me
     * Uses existing DB columns: username, phone, email, meta
     */
    public function updateProfile(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            // Vue sends "name" => we map it to "username"
            'name'  => [
                'nullable','string','max:190',
                Rule::unique('users', 'username')->ignore($u->id)->where(fn($q) => $q->where('customer_id', $u->customer_id)),
            ],

            'phone' => ['nullable','string','max:32'],

            // keep optional, even if UI disables it
            'email' => [
                'nullable','email','max:190',
                Rule::unique('users', 'email')->ignore($u->id)->where(fn($q) => $q->where('customer_id', $u->customer_id)),
            ],

            // stored inside meta (existing column)
            'locale' => ['nullable', Rule::in(['ar','en'])],
            'notes'  => ['nullable','string','max:2000'],
        ]);

        // Update direct columns
        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $u->username = $data['name'];
        }
        if (array_key_exists('phone', $data)) {
            $u->phone = $data['phone'];
        }
        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $u->email = $data['email'];
        }

        // Update meta JSON
        $meta = $this->metaArray($u->meta);
        if (array_key_exists('locale', $data) && $data['locale'] !== null) {
            $meta['locale'] = $data['locale'];
        }
        if (array_key_exists('notes', $data)) {
            $meta['notes'] = $data['notes'];
        }
        $this->saveMeta($u, $meta);

        $u->save();

        return response()->json([
            'message' => 'Profile updated',
            'user' => [
                'id'          => $u->id,
                'customer_id' => $u->customer_id,
                'username'    => $u->username,
                'email'       => $u->email,
                'phone'       => $u->phone,
                'role'        => $u->role,
                'roles'       => [$u->role],
                'meta'        => $meta,
                'locale'      => $meta['locale'] ?? null,
                'notes'       => $meta['notes'] ?? null,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            'current_password' => ['required','string'],
            'new_password'     => ['required','string','min:8','max:100','confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $u->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Current password is incorrect.',
            ]);
        }

        $u->password = Hash::make($data['new_password']);
        $u->save();

        // revoke tokens
        $u->tokens()->delete();

        return response()->json(['message' => 'Password updated. Please login again.']);
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
