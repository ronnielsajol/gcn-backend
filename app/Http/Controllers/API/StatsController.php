<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function getSphereStatsPerEvent(Event $event)
    {
        // Get all users in the event (only role: user, attendance = 1) with their spheres
        $usersWithSpheres = DB::table('users')
            ->join('event_user', 'users.id', '=', 'event_user.user_id')
            ->leftJoin('user_sphere', 'users.id', '=', 'user_sphere.user_id')
            ->where('event_user.event_id', $event->id)
            ->where('users.role', 'user')
            ->where('users.attendance', 1)
            ->select('users.id', 'user_sphere.sphere_id')
            ->orderBy('users.id')
            ->orderBy('user_sphere.sphere_id')
            ->get();

        // Group spheres by user
        $userSpheres = $usersWithSpheres->groupBy('id');

        // Determine primary sphere for each user
        $primarySpheres = [];
        foreach ($userSpheres as $userId => $spheres) {
            $sphereIds = $spheres->pluck('sphere_id')->filter()->values()->toArray();

            if (empty($sphereIds)) {
                // User has no spheres
                $primarySpheres[$userId] = null;
            } elseif (count($sphereIds) === 1) {
                // User has only 1 sphere
                $primarySpheres[$userId] = $sphereIds[0];
            } else {
                // User has multiple spheres
                // If first sphere is 1 (Church/Ministry), take the second one
                if ($sphereIds[0] == 1 && isset($sphereIds[1])) {
                    $primarySpheres[$userId] = $sphereIds[1];
                } else {
                    $primarySpheres[$userId] = $sphereIds[0];
                }
            }
        }

        // Count users per sphere
        $sphereCounts = [];
        foreach ($primarySpheres as $userId => $sphereId) {
            if ($sphereId !== null) {
                if (!isset($sphereCounts[$sphereId])) {
                    $sphereCounts[$sphereId] = 0;
                }
                $sphereCounts[$sphereId]++;
            }
        }

        // Get sphere details
        $sphereStats = DB::table('spheres')
            ->whereIn('id', array_keys($sphereCounts))
            ->get()
            ->map(function ($sphere) use ($sphereCounts) {
                return (object)[
                    'sphere_id' => $sphere->id,
                    'sphere_name' => $sphere->name,
                    'sphere_slug' => $sphere->slug,
                    'user_count' => $sphereCounts[$sphere->id],
                ];
            })
            ->sortBy('sphere_name')
            ->values();

        // Get total unique users in the event (only role: user, attendance = 1)
        $totalUsers = $event->users()->where('role', 'user')->where('attendance', 1)->count();

        // Count users without spheres
        $usersWithoutSpheres = count(array_filter($primarySpheres, fn($sphereId) => $sphereId === null));

        // Add "Others" category for users without spheres
        if ($usersWithoutSpheres > 0) {
            $othersCategory = (object)[
                'sphere_id' => null,
                'sphere_name' => 'Others (No Sphere)',
                'sphere_slug' => 'others',
                'user_count' => $usersWithoutSpheres
            ];
            $sphereStats->push($othersCategory);
        }

        // Calculate percentages
        $sphereStatsWithPercentages = $sphereStats->map(function ($stat) use ($totalUsers) {
            $stat->percentage = $totalUsers > 0 ? round(($stat->user_count / $totalUsers) * 100, 2) : 0;
            return $stat;
        });

        return response()->json([
            'event_id' => $event->id,
            'event_name' => $event->name,
            'total_users' => $totalUsers,
            'sphere_stats' => $sphereStatsWithPercentages,
            'summary' => [
                'total_spheres_represented' => $sphereStats->where('sphere_id', '!=', null)->count(),
                'users_without_spheres' => $usersWithoutSpheres,
                'most_popular_sphere' => $sphereStats->sortByDesc('user_count')->first(),
            ]
        ]);
    }
}
