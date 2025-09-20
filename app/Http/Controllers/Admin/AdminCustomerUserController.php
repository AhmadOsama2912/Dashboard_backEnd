<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Http\Request;
use illuminate\Validation\Rule;

class AdminCustomerUserController extends Controller
{
    /**
     * GET /api/admin/v1/users
     * Query: q, role, customer_id, per_page, sort, direction
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'q'           => ['nullable','string','max:190'],
            'role'        => ['nullable','in:manager,supervisor'],
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'per_page'    => ['nullable','integer','min:1','max:100'],
            'sort'        => ['nullable','in:id,username,email,role,customer_id,created_at'],
            'direction'   => ['nullable','in:asc,desc'],
        ]);

        $perPage   = $data['per_page']  ?? 15;
        $sort      = $data['sort']      ?? 'created_at';
        $direction = $data['direction'] ?? 'desc';

        $query = User::query()
            ->with(['customer:id,name,package_id','customer.package:id,name']);

        if (!empty($data['q'])) {
            $q = $data['q'];
            $query->where(function ($w) use ($q) {
                $w->where('username','like',"%{$q}%")
                  ->orWhere('email','like',"%{$q}%");
            });
        }

        if (!empty($data['role'])) {
            $query->where('role', $data['role']);
        }

        if (!empty($data['customer_id'])) {
            $query->where('customer_id', $data['customer_id']);
        }

        $paginator = $query
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        $paginator->getCollection()->transform(function (User $u) {
            return [
                'id'          => $u->id,
                'username'    => $u->username,
                'email'       => $u->email,
                'role'        => $u->role,
                'customer'    => $u->customer ? [
                    'id'   => $u->customer->id,
                    'name' => $u->customer->name,
                    'package' => $u->customer->package ? [
                        'id' => $u->customer->package->id,
                        'name' => $u->customer->package->name,
                    ] : null,
                ] : null,
                'created_at'  => $u->created_at,
            ];
        });

        return response()->json($paginator);
    }

    /**
     * GET /api/admin/v1/users/{user}
     */
    public function show(User $user)
    {
        $user->load(['customer:id,name,package_id','customer.package:id,name']);

        return response()->json([
            'id'         => $user->id,
            'username'   => $user->username,
            'email'      => $user->email,
            'role'       => $user->role,
            'customer'   => $user->customer ? [
                'id'   => $user->customer->id,
                'name' => $user->customer->name,
                'package' => $user->customer->package ? [
                    'id'   => $user->customer->package->id,
                    'name' => $user->customer->package->name,
                ] : null,
            ] : null,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ]);
    }

    /**
     * POST /api/admin/v1/users (your existing store())
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required','exists:customers,id'],
            'role'        => ['required','in:manager,supervisor'],
            'username'    => ['required','string','max:190'],
            'email'       => ['required','email','max:190'],
            'password'    => ['required','string','min:8','max:191','confirmed'],
            'phone'       => ['nullable','string','max:50'],
            'meta'        => ['nullable','array'],
        ]);

        // enforce package user limits (optional)
        $customer = Customer::with('package','users')->findOrFail($data['customer_id']);
        if ($data['role']==='manager' && $customer->users()->where('role','manager')->count() >= $customer->package->managers_limit) {
            return response()->json(['message'=>'Managers limit reached for this package'], 422);
        }
        if ($data['role']==='supervisor' && $customer->users()->where('role','supervisor')->count() >= $customer->package->supervisors_limit) {
            return response()->json(['message'=>'Supervisors limit reached for this package'], 422);
        }

        // uniqueness scoped per customer
        if (User::where('customer_id',$data['customer_id'])->where('email',$data['email'])->exists()) {
            return response()->json(['message'=>'Email already used for this customer'], 422);
        }
        if (User::where('customer_id',$data['customer_id'])->where('username',$data['username'])->exists()) {
            return response()->json(['message'=>'Username already used for this customer'], 422);
        }

        $user = User::create([
            'customer_id' => $data['customer_id'],
            'role'        => $data['role'],
            'username'    => $data['username'],
            'email'       => $data['email'],
            'password'    => $data['password'], // hashed by cast
            'phone'       => $data['phone'] ?? null,
            'meta'        => $data['meta'] ?? null,
        ]);

        return response()->json([
            'message' => 'User created',
            'user'    => [
                'id'=>$user->id,'customer_id'=>$user->customer_id,'role'=>$user->role,
                'username'=>$user->username,'email'=>$user->email
            ]
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        // We may change customer_id and/or role, so we must re-check package limits & uniqueness
        $data = $request->validate([
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'role'        => ['nullable','in:manager,supervisor'],
            'username'    => ['nullable','string','max:190'],
            'email'       => ['nullable','email','max:190'],
            'password'    => ['nullable','string','min:8','max:191','confirmed'],
            'phone'       => ['nullable','string','max:50'],
            'meta'        => ['nullable','array'],
        ]);

        $newCustomerId = $data['customer_id'] ?? $user->customer_id;
        $newRole       = $data['role'] ?? $user->role;

        // Enforce per-package limits only if role/customer changed
        if ($newCustomerId != $user->customer_id || $newRole !== $user->role) {
            $customer = Customer::with('package')->findOrFail($newCustomerId);

            if ($newRole === 'manager') {
                $count = User::where('customer_id', $newCustomerId)->where('role', 'manager')->where('id', '!=', $user->id)->count();
                if ($count >= $customer->package->managers_limit) {
                    return response()->json(['message' => 'Managers limit reached for this package'], 422);
                }
            }

            if ($newRole === 'supervisor') {
                $count = User::where('customer_id', $newCustomerId)->where('role', 'supervisor')->where('id', '!=', $user->id)->count();
                if ($count >= $customer->package->supervisors_limit) {
                    return response()->json(['message' => 'Supervisors limit reached for this package'], 422);
                }
            }
        }

        // Unique email/username scoped by customer
        $emailRule = Rule::unique('users','email')
            ->where('customer_id', $newCustomerId)
            ->ignore($user->id);
        $usernameRule = Rule::unique('users','username')
            ->where('customer_id', $newCustomerId)
            ->ignore($user->id);

        $request->validate([
            'email'    => ['nullable','email','max:190', $emailRule],
            'username' => ['nullable','string','max:190', $usernameRule],
        ]);

        // Apply changes
        $user->fill([
            'customer_id' => $newCustomerId,
            'role'        => $newRole,
        ] + collect($data)->only(['username','email','phone','meta'])->toArray());

        // Password optional
        if (!empty($data['password'])) {
            $user->password = $data['password']; // auto-hashed by cast
        }

        $user->save();

        return response()->json([
            'message' => 'User updated',
            'user'    => [
                'id' => $user->id,
                'customer_id' => $user->customer_id,
                'role' => $user->role,
                'username' => $user->username,
                'email' => $user->email,
            ],
        ]);
    }

    public function destroy(User $user)
    {
        $user->delete(); // soft delete
        return response()->json(['message' => 'User deleted']);
    }
}
