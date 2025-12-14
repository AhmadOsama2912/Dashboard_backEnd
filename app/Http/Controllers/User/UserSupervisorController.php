<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserSupervisorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var User $manager */
        $manager = $request->user();

        if (!$manager || $manager->role !== 'manager') {
            return response()->json(['message' => 'Unauthorized to create supervisors.'], 403);
        }

        $customerId = $manager->customer_id;

        $customer = Customer::query()->with('package')->find($customerId);
        if (!$customer || !$customer->package) {
            return response()->json(['message' => 'Company or package information not found.'], 500);
        }

        $maxSupervisors = (int) ($customer->package->max_supervisors ?? PHP_INT_MAX);

        $currentSupervisors = User::query()
            ->where('customer_id', $customerId)
            ->where('role', 'supervisor')
            ->count();

        if ($currentSupervisors >= $maxSupervisors) {
            return response()->json([
                'message' => 'Package limit reached.',
                'limit'   => $maxSupervisors,
                'current' => $currentSupervisors,
            ], 403);
        }

        $allowedAbilities = [
            'content:change',  // 1
            'devices:assign',  // 2
            'content:add',     // 3
            'bulk:send',       // 4
        ];

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users')->where(fn ($q) => $q->where('customer_id', $customerId)),
            ],
            'username' => [
                'required', 'string', 'max:190',
                Rule::unique('users')->where(fn ($q) => $q->where('customer_id', $customerId)),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            // permissions/abilities controlled by manager dashboard
            'abilities'   => ['sometimes', 'array'],
            'abilities.*' => ['string', Rule::in($allowedAbilities)],
        ]);

        $supervisor = User::create([
            'customer_id' => $customerId,
            'name'        => $data['name'],
            'email'       => $data['email'],
            'username'    => $data['username'],
            'password'    => Hash::make($data['password']),
            'role'        => 'supervisor',
            'abilities'   => $data['abilities'] ?? [],   // store permissions here
        ]);

        return response()->json([
            'message' => 'Supervisor created successfully.',
            'user'    => $supervisor,
        ], 201);
    }

    /**
     * Manager: list all supervisors in his company
     */

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $manager */
        $manager = $request->user();

        if (!$manager || $manager->role !== 'manager') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $customerId = $manager->customer_id;

        // role filter: supervisor | manager
        $role = $request->query('role', 'supervisor');
        if (!in_array($role, ['supervisor', 'manager'], true)) {
            $role = 'supervisor';
        }

        $q = trim((string) $request->query('q', ''));

        $query = \App\Models\User::query()
            ->where('customer_id', $customerId)
            ->where('role', $role) // ✅ هذا هو الفرق
            ->whereNull('deleted_at'); // (لو SoftDeletes)

        // optional search
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");

                // لو عندك name column لاحقاً
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'name')) {
                    $sub->orWhere('name', 'like', "%{$q}%");
                }
            });
        }

        $perPage = (int) $request->query('per_page', 20);

        // ✅ لا تعمل select فيه name إذا مش موجود بالجدول
        $select = ['id', 'customer_id', 'email', 'username', 'role', 'abilities', 'created_at', 'updated_at'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'name')) {
            $select[] = 'name';
        }

        $users = $query
            ->select($select)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($users);
    }


    /**
     * Manager: update a supervisor (same company)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $manager */
        $manager = $request->user();

        if (!$manager || $manager->role !== 'manager') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $supervisor = User::query()
            ->where('id', $id)
            ->where('customer_id', $manager->customer_id)
            ->where('role', 'supervisor')
            ->first();

        if (!$supervisor) {
            return response()->json(['message' => 'Supervisor not found.'], 404);
        }

        $allowedAbilities = [
            'content:change',
            'devices:assign',
            'content:add',
            'bulk:send',
        ];

        $data = $request->validate([
            'email'    => [
                'sometimes', 'string', 'email', 'max:255',
                Rule::unique('users')
                    ->where(fn ($q) => $q->where('customer_id', $manager->customer_id))
                    ->ignore($supervisor->id),
            ],
            'username' => [
                'sometimes', 'string', 'max:190',
                Rule::unique('users')
                    ->where(fn ($q) => $q->where('customer_id', $manager->customer_id))
                    ->ignore($supervisor->id),
            ],

            // optional password update
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],

            // abilities update
            'abilities'   => ['sometimes', 'array'],
            'abilities.*' => ['string', Rule::in($allowedAbilities)],
        ]);


        if (array_key_exists('email', $data)) {
            $supervisor->email = $data['email'];
        }
        if (array_key_exists('username', $data)) {
            $supervisor->username = $data['username'];
        }
        if (array_key_exists('abilities', $data)) {
            $supervisor->abilities = $data['abilities'] ?? [];
        }
        if (!empty($data['password'])) {
            $supervisor->password = Hash::make($data['password']);
        }

        $supervisor->save();

        return response()->json([
            'message' => 'Supervisor updated successfully.',
            'user'    => $supervisor,
        ]);
    }

    /**
     * Manager: get single supervisor details (optional, useful for edit form)
     */
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $manager */
        $manager = $request->user();

        if (!$manager || $manager->role !== 'manager') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $supervisor = User::query()
            ->where('id', $id)
            ->where('customer_id', $manager->customer_id)
            ->where('role', 'supervisor')
            ->select(['id','name','email','username','role','abilities','created_at','updated_at'])
            ->first();

        if (!$supervisor) {
            return response()->json(['message' => 'Supervisor not found.'], 404);
        }

        return response()->json(['user' => $supervisor]);
    }
}
