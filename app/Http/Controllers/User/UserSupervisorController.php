<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Support\UserAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserSupervisorController extends Controller
{
    /**
     * Create a new supervisor user for the authenticated user's company,
     * respecting the package limitations on the number of supervisors.
     */

    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // Recommended: rely on middleware abilities:user:manage
        // But keep a safe fallback:
        if (!$actor || ($actor->role ?? null) !== 'manager') {
            return response()->json(['message' => 'Unauthorized to list users.'], 403);
        }

        $customerId = (int) $actor->customer_id;

        // Query params used by Vue
        $role    = strtolower((string) $request->query('role', 'supervisor')); // supervisor|manager
        $q       = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100)); // clamp
        $page    = (int) $request->query('page', 1);
        $page    = max(1, $page);

        // allow only these roles
        if (!in_array($role, ['supervisor', 'manager'], true)) {
            return response()->json([
                'message' => 'Invalid role filter. Allowed: supervisor, manager.',
            ], 422);
        }

        $query = User::query()
            ->select([
                'id',
                'customer_id',
                'username',
                'email',
                'phone',
                'role',
                'abilities',
                'created_at',
                'updated_at',
            ])
            ->where('customer_id', $customerId)
            ->where('role', $role);

        // Search by name OR username OR email
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $paginator = $query
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // Return paginator in a Vue-friendly structure
        return response()->json([
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $manager */
        $manager = $request->user();

        // This endpoint is already guarded by middleware 'abilities:user:manage'
        // but keep role check as a hard safety (defense-in-depth).
        if (!$manager || $manager->role !== 'manager') {
            return response()->json(['message' => 'Unauthorized to create supervisors.'], 403);
        }

        $customerId = (int) $manager->customer_id;

        /** @var Customer|null $customer */
        $customer = Customer::query()
            ->with('package')
            ->find($customerId);

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

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->where(fn ($q) => $q->where('customer_id', $customerId)),
            ],
            'username' => [
                'required',
                'string',
                'max:190',
                Rule::unique('users')->where(fn ($q) => $q->where('customer_id', $customerId)),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $supervisor = User::create([
            'customer_id' => $customerId,
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'username'    => $validated['username'],
            'password'    => Hash::make($validated['password']),
            'role'        => 'supervisor',

            // Store canonical abilities in DB (optional but recommended).
            // If you prefer "role defaults only", you can remove this line.
            'abilities'   => UserAbilities::supervisorDefaults(),
        ]);

        return response()->json([
            'message' => 'Supervisor created successfully.',
            'user'    => $supervisor,
        ], 201);
    }
}
