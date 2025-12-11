<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserDashboardController extends Controller
{
    /**
     * Return summary values for the authenticated tenant user's company.
     * The data returned depends on the user's permissions (manager vs supervisor).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $user->customer_id;

        // Base query for screens, scoped to the user's company
        $screenQuery = Screen::where('customer_id', $customerId);

        // If the user is a supervisor and has assigned screens, scope the query further
        if ($user->hasAbility('user:screens:view_assigned')) {
            // Assuming a many-to-many relationship or a pivot table for supervisor-screen assignment
            // For simplicity, let's assume a direct relationship or a method to get assigned screen IDs
            // Since we don't have the full schema, we'll assume a method `assignedScreenIds()` exists on the User model
            // If not, this logic will need adjustment based on the actual schema.
            $assignedScreenIds = $user->assignedScreenIds(); // Placeholder for actual logic

            if (!empty($assignedScreenIds)) {
                $screenQuery->whereIn('id', $assignedScreenIds);
            }
        }

        $totalScreens = $screenQuery->count();
        $activeScreens = (clone $screenQuery)
            ->whereNotNull('last_check_in_at')
            ->where('last_check_in_at', '>=', now()->subMinutes(6))
            ->count();

        $inactiveScreens = $totalScreens - $activeScreens;

        // Count of tenant users (managers/supervisors) in the company
        $totalUsers = User::where('customer_id', $customerId)->count();

        // Placeholder for other summary data (e.g., total playlists, content items)
        // This would require checking the `Playlist` and `PlaylistItem` models, scoped by `customer_id`.
        $totalPlaylists = \App\Models\Playlist::where('customer_id', $customerId)->count();

        return response()->json([
            'total_screens' => $totalScreens,
            'active_screens' => $activeScreens,
            'inactive_screens' => $inactiveScreens,
            'total_users' => $totalUsers,
            'total_playlists' => $totalPlaylists,
            // Add more summary data as needed
        ]);
    }

    /**
     * Return a richer set of metrics for the authenticated user's dashboard.
     *
     * This endpoint expands upon the summary values by including additional
     * counters required for the user portal landing page charts.  It can be
     * safely consumed from the front‑end without exposing sensitive data.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function metrics(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $user->customer_id;

        // Query screens scoped by company and by supervisor assignment if needed
        $screenQuery = Screen::where('customer_id', $customerId);
        if ($user->hasAbility('user:screens:view_assigned') && !$user->hasAbility('user:screens:view_all')) {
            $assignedScreenIds = method_exists($user, 'assignedScreenIds') ? $user->assignedScreenIds() : [];
            if (!empty($assignedScreenIds)) {
                $screenQuery->whereIn('id', $assignedScreenIds);
            }
        }

        $totalScreens = $screenQuery->count();
        $activeScreens = (clone $screenQuery)
            ->whereNotNull('last_check_in_at')
            ->where('last_check_in_at', '>=', now()->subMinutes(6))
            ->count();
        $inactiveScreens = $totalScreens - $activeScreens;

        // Supervisors: count company users with supervisor role
        // We assume a `User` model attribute/relationship indicates role. Adjust accordingly to match your schema.
        $totalSupervisors = User::where('customer_id', $customerId)
            ->where('role', 'supervisor')
            ->count();

        // Playlists scoped to the company
        $totalPlaylists = \App\Models\Playlist::where('customer_id', $customerId)->count();

        // Media items: count of items associated with the company's playlists
        // This may need adjustment based on your actual relationships
        $totalMediaItems = \App\Models\PlaylistItem::whereHas('playlist', function ($query) use ($customerId) {
            $query->where('customer_id', $customerId);
        })->count();

        return response()->json([
            'screens'         => $totalScreens,
            'active_screens'  => $activeScreens,
            'offline_screens' => $inactiveScreens,
            'playlists'       => $totalPlaylists,
            'supervisors'     => $totalSupervisors,
            'media_items'     => $totalMediaItems,
        ]);
    }
}
