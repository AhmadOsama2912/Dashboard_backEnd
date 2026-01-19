<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserScreenController extends Controller
{
    /**
     * Display a listing of the screens owned by the user's company,
     * scoped by user permissions (manager sees all, supervisor sees assigned).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $user->customer_id;

        $screenQuery = Screen::where('customer_id', $customerId);

        // Check for supervisor-level permission to view only assigned screens
        if ($user->hasAbility('user:screens:view_assigned') && !$user->hasAbility('user:screens:view_all')) {
            // Assuming a method to get assigned screen IDs for the supervisor
            // NOTE: This is a placeholder. The actual implementation depends on the database schema.
            // For now, we assume a method `assignedScreenIds()` exists on the User model.
            $assignedScreenIds = $user->assignedScreenIds();

            if (!empty($assignedScreenIds)) {
                $screenQuery->whereIn('id', $assignedScreenIds);
            } else {
                // If no screens are assigned, return an empty set
                return response()->json(['data' => []]);
            }
        }

        $screens = $screenQuery->paginate(
            $request->get('per_page', 15)
        );

        return response()->json($screens);
    }

    /**
     * Display the specified screen, ensuring it belongs to the user's company
     * and is accessible based on user permissions.
     *
     * @param Request $request
     * @param Screen $screen
     * @return JsonResponse
     */
    public function show(Request $request, Screen $screen): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // 1. Check if the screen belongs to the user's company
        if ($screen->customer_id !== $user->customer_id) {
            return response()->json(['message' => 'Screen not found or unauthorized.'], 404);
        }

        // 2. Check for supervisor-level permission to view only assigned screens
        if ($user->hasAbility('user:screens:view_assigned') && !$user->hasAbility('user:screens:view_all')) {
            // Assuming a method to check if the screen is assigned to the supervisor
            // NOTE: This is a placeholder. The actual implementation depends on the database schema.
            // For now, we assume a method `isAssignedToScreen($screenId)` exists on the User model.
            if (!$user->isAssignedToScreen($screen->id)) {
                return response()->json(['message' => 'Screen not found or unauthorized.'], 404);
            }
        }

        // Load necessary relations for a detailed view
        $screen->load(['customer', 'package', 'playlist', 'supervisor']);

        return response()->json($screen);
    }
}
