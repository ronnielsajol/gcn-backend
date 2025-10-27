<?php

namespace App\Http\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\User;

trait ChecksEventAttendance
{
    /**
     * Set event ID for attendance check on users
     *
     * @param Collection|LengthAwarePaginator|User $users
     * @param int|null $eventId
     * @return Collection|LengthAwarePaginator|User
     */
    protected function withEventAttendanceCheck($users, ?int $eventId)
    {
        if ($eventId === null) {
            return $users;
        }

        // Handle single user
        if ($users instanceof User) {
            return $users->setCheckEventId($eventId);
        }

        // Handle paginated collection
        if ($users instanceof LengthAwarePaginator) {
            $users->getCollection()->each(function ($user) use ($eventId) {
                if ($user instanceof User) {
                    $user->setCheckEventId($eventId);
                }
            });
            return $users;
        }

        // Handle regular collection
        if ($users instanceof Collection) {
            return $users->each(function ($user) use ($eventId) {
                if ($user instanceof User) {
                    $user->setCheckEventId($eventId);
                }
            });
        }

        return $users;
    }
}
