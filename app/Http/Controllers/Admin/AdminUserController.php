<?php
// app/Http/Controllers/Admin/AdminUserController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminUserController extends Controller
{
    /**
     * GET /api/admin/v1/admins
     * Query: q, per_page, sort, direction, include_deleted
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'q'               => ['nullable','string','max:190'],
            'per_page'        => ['nullable','integer','min:1','max:100'],
            'sort'            => ['nullable','in:id,name,username,email,last_login_at,created_at'],
            'direction'       => ['nullable','in:asc,desc'],
            'include_deleted' => ['nullable','boolean'],
        ]);

        $perPage   = $data['per_page']   ?? 15;
        $sort      = $data['sort']       ?? 'created_at';
        $direction = $data['direction']  ?? 'desc';

        $query = Admin::query();

        if (!empty($data['include_deleted'])) {
            $query->withTrashed();
        }

        if (!empty($data['q'])) {
            $q = $data['q'];
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('username', 'like', "%{$q}%")
                  ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $paginator = $query
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        // Transform output (hide sensitive & keep consistent shape)
        $paginator->getCollection()->transform(fn (Admin $a) => [
            'id'             => $a->id,
            'name'           => $a->name,
            'username'       => $a->username,
            'email'          => $a->email,
            'is_super_admin' => (bool) ($a->is_super_admin ?? false),
            'last_login_at'  => $a->last_login_at,
            'created_at'     => $a->created_at,
            'deleted_at'     => $a->deleted_at,
        ]);

        return response()->json($paginator);
    }
    /**
     * GET /api/admin/v1/admins/{admin}
     * Route model binding: {admin}
     */
    public function show(Admin $admin)
    {
        return response()->json([
            'id'             => $admin->id,
            'name'           => $admin->name,
            'username'       => $admin->username,
            'email'          => $admin->email,
            'is_super_admin' => (bool) ($admin->is_super_admin ?? false),
            'last_login_at'  => $admin->last_login_at,
            'last_login_ip'  => $admin->last_login_ip,
            'created_at'     => $admin->created_at,
            'updated_at'     => $admin->updated_at,
            'deleted_at'     => $admin->deleted_at,
        ]);
    }
    /**
     * Create another admin (requires token ability: admin:manage)
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name'           => ['nullable','string','max:190'],
                'username'       => ['required','string','max:190','unique:admins,username'],
                'email'          => ['required','email','max:190','unique:admins,email'],
                'password'       => ['required','string','min:8','max:191','confirmed'],
                'phone'          => ['nullable','string','max:50'],
                'avatar_path'    => ['nullable','string','max:255'],
                'is_super_admin' => ['sometimes','boolean'],
            ]);

            // امنع التصعيد: بس السوبر أدمن يقدر يعيّن is_super_admin
            $data['is_super_admin'] = ($request->user()->is_super_admin ?? false)
                && array_key_exists('is_super_admin', $data)
                ? (bool) $data['is_super_admin']
                : false;

            $admin = Admin::create([
                'name'           => $data['name'] ?? null,
                'username'       => $data['username'],
                'email'          => $data['email'],
                'password'       => $data['password'], // يتهش تلقائياً من الـ cast
                'phone'          => $data['phone'] ?? null,
                'avatar_path'    => $data['avatar_path'] ?? null,
                'is_super_admin' => $data['is_super_admin'],
            ]);

            return response()->json([
                'message' => 'Admin created',
                'admin'   => [
                    'id'             => $admin->id,
                    'name'           => $admin->name,
                    'username'       => $admin->username,
                    'email'          => $admin->email,
                    'is_super_admin' => $admin->is_super_admin,
                ],
            ], 201);

        } catch (ValidationException $e) {
            // 422 للأخطاء المنطقية
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            // 500 مع رسالة مختصرة
            return response()->json([
                'message' => 'Server error',
            ], 500);
        }
    }

    public function update(Request $request, Admin $admin)
    {
        $data = $request->validate([
            'name'           => ['nullable','string','max:190'],
            'username'       => ['nullable','string','max:190', Rule::unique('admins','username')->ignore($admin->id)],
            'email'          => ['nullable','email','max:190', Rule::unique('admins','email')->ignore($admin->id)],
            'password'       => ['nullable','string','min:8','max:191','confirmed'],
            'phone'          => ['nullable','string','max:50'],
            'avatar_path'    => ['nullable','string','max:255'],
            'is_super_admin' => ['sometimes','boolean'],
        ]);

        $authAdmin = $request->user(); // current admin

        // Only super admins can modify is_super_admin, and prevent losing the last one
        if (array_key_exists('is_super_admin', $data)) {
            if (!$authAdmin->is_super_admin) {
                unset($data['is_super_admin']); // ignore escalation attempt
            } else {
                // If demoting this admin, ensure it's not the last super admin
                $demoting = $admin->is_super_admin && $data['is_super_admin'] === false;
                if ($demoting) {
                    $superCount = Admin::where('is_super_admin', true)->where('id', '!=', $admin->id)->count();
                    if ($superCount === 0) {
                        return response()->json(['message' => 'Cannot demote the last super admin'], 422);
                    }
                }
            }
        }

        // Prevent self-demotion via PATCHing own record (optional but safer)
        if ($admin->id === $authAdmin->id && isset($data['is_super_admin']) && $data['is_super_admin'] === false) {
            return response()->json(['message' => 'You cannot remove your own super admin role'], 422);
        }

        // Fill only provided fields
        if (isset($data['password']) && $data['password'] === null) unset($data['password']);
        $admin->fill($data);
        $admin->save();

        return response()->json([
            'message' => 'Admin updated',
            'admin'   => [
                'id' => $admin->id,
                'name' => $admin->name,
                'username' => $admin->username,
                'email' => $admin->email,
                'is_super_admin' => (bool) $admin->is_super_admin,
            ],
        ]);
    }

    public function destroy(Request $request, Admin $admin)
    {
        $authAdmin = $request->user();

        if ($admin->id === $authAdmin->id) {
            return response()->json(['message' => 'You cannot delete your own account'], 422);
        }

        // Prevent deleting last super admin
        if ($admin->is_super_admin) {
            $others = Admin::where('is_super_admin', true)->where('id', '!=', $admin->id)->count();
            if ($others === 0) {
                return response()->json(['message' => 'Cannot delete the last super admin'], 422);
            }
        }

    }
}
