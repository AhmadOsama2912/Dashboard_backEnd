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
}
