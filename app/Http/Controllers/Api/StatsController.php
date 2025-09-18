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
        // Get users with spheres (only role: user)
        $sphereStats = DB::table('users')
            ->join('event_user', 'users.id', '=', 'event_user.user_id')
            ->join('user_sphere', 'users.id', '=', 'user_sphere.user_id')
            ->join('spheres', 'user_sphere.sphere_id', '=', 'spheres.id')
            ->where('event_user.event_id', $event->id)
            ->where('users.role', 'user')
            ->select(
                'spheres.id as sphere_id',
                'spheres.name as sphere_name',
                'spheres.slug as sphere_slug',
                DB::raw('COUNT(DISTINCT users.id) as user_count')
            )
            ->groupBy('spheres.id', 'spheres.name', 'spheres.slug')
            ->orderBy('spheres.name')
            ->get();

        // Get total unique users in the event (only role: user)
        $totalUsers = $event->users()->where('role', 'user')->count();

        // Get count of users with no sphere assignments (null spheres, only role: user)
        $usersWithoutSpheres = DB::table('users')
            ->join('event_user', 'users.id', '=', 'event_user.user_id')
            ->leftJoin('user_sphere', 'users.id', '=', 'user_sphere.user_id')
            ->where('event_user.event_id', $event->id)
            ->where('users.role', 'user')
            ->whereNull('user_sphere.user_id')
            ->count();

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
