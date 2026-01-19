<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class 
UserSupervisorController extends Controller
{
    /**
     * Create a new supervisor user for the authenticated user's company,
     * respecting the package limitations on the number of supervisors.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        //  dd($request->all());

        /** @var User $manager */
        $manager = $request->user();
        $customerId = $manager->customer_id;

        // dd($manager->hasAbility('user:manage'));
        // 1. Check if the manager has the ability to create users (supervisors)
        // This is typically a manager-only ability, e.g., 'user:manage'
        // dd(!$manager->role === 'manager');
        if (!$manager->role === 'manager') {
           
            return response()->json(['message' => 'Unauthorized to create supervisors.'], 403);
        }

        // 2. Load the customer and its package to check limitations
        /** @var Customer $customer */
        $customer = Customer::with('package')->find($customerId);

        if (!$customer || !$customer->package) {
            return response()->json(['message' => 'Company or package information not found.'], 500);
        }

        $package = $customer->package;
        // Assuming a column `max_supervisors` exists on the Package model
        $maxSupervisors = $package->max_supervisors ?? PHP_INT_MAX;

        // 3. Check current supervisor count against package limit
        $currentSupervisors = User::where('customer_id', $customerId)
            ->where('role', 'supervisor') // Assuming a 'role' column
            ->count();

        if ($currentSupervisors >= $maxSupervisors) {
            return response()->json([
                'message' => 'Package limit reached.',
                'limit' => $maxSupervisors,
                'current' => $currentSupervisors
            ], 403);
        }

        // 4. Validate the request data
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // Ensure email is unique within the customer's scope
                Rule::unique('users')->where(function ($query) use ($customerId) {
                    return $query->where('customer_id', $customerId);
                }),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'username' => ['required', 'string', 'max:190',
                Rule::unique('users')->where(function ($query) use ($customerId) {
                    return $query->where('customer_id', $customerId);
                }),
            ],
        ]);

        // 5. Create the new supervisor user
        $supervisor = User::create([
            'customer_id' => $customerId,
            'name' => $request->name,
            'email' => $request->email,
            'username' => $request->username,
            'password' => Hash::make($request->password),
            'role' => 'supervisor', // Set the role to supervisor
            // Abilities will be set by the model/middleware based on the 'supervisor' role
        ]);

        return response()->json([
            'message' => 'Supervisor created successfully.',
            'user' => $supervisor
        ], 201);
    }
}
