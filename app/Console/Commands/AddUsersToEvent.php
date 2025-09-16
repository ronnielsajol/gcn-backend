<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AddUsersToEvent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'event:add-users
        {event_id : The ID of the event}
        {--users= : Comma-separated list of user IDs (e.g., 1,2,3,4)}
        {--role= : Add all users with specific role (super_admin, admin, user)}
        {--all : Add all users to the event}
        {--detach : Remove users instead of adding them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add users to an event by user IDs, role, or all users';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $eventId = $this->argument('event_id');
        $userIds = $this->option('users');
        $role = $this->option('role');
        $addAll = $this->option('all');
        $detach = $this->option('detach');

        // Find the event
        $event = Event::find($eventId);
        if (!$event) {
            $this->error("Event with ID {$eventId} not found.");
            return Command::FAILURE;
        }

        $this->info("Event: {$event->name}");

        // Determine which users to add/remove
        $users = $this->getUsersToProcess($userIds, $role, $addAll);

        if ($users->isEmpty()) {
            $this->warn('No users found matching the criteria.');
            return Command::SUCCESS;
        }

        $this->info("Found {$users->count()} users to process.");

        // Show user details
        $this->table(
            ['ID', 'Name', 'Email', 'Role'],
            $users->map(function ($user) {
                return [
                    $user->id,
                    $user->getFullNameAttribute(),
                    $user->email,
                    $user->role
                ];
            })
        );

        // Confirm action
        $action = $detach ? 'remove from' : 'add to';
        if (!$this->confirm("Do you want to {$action} these users {$action} the event '{$event->name}'?")) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        // Process the users
        $userIdsArray = $users->pluck('id')->toArray();

        if ($detach) {
            $event->users()->detach($userIdsArray);
            $this->info("Successfully removed {$users->count()} users from the event.");
        } else {
            // Use syncWithoutDetaching to avoid removing existing users
            $event->users()->syncWithoutDetaching($userIdsArray);
            $this->info("Successfully added {$users->count()} users to the event.");
        }

        // Show final count
        $totalAttendees = $event->users()->count();
        $this->info("Total event attendees: {$totalAttendees}");

        return Command::SUCCESS;
    }

    /**
     * Get the users to process based on the provided options
     */
    private function getUsersToProcess(?string $userIds, ?string $role, bool $addAll): Collection
    {
        if ($addAll) {
            return User::all();
        }

        if ($role) {
            if (!in_array($role, ['super_admin', 'admin', 'user'])) {
                $this->error("Invalid role '{$role}'. Valid roles are: super_admin, admin, user");
                return collect();
            }
            return User::where('role', $role)->get();
        }

        if ($userIds) {
            $idsArray = array_map('trim', explode(',', $userIds));
            $idsArray = array_filter($idsArray, 'is_numeric');

            if (empty($idsArray)) {
                $this->error('No valid user IDs provided.');
                return collect();
            }

            $users = User::whereIn('id', $idsArray)->get();

            // Check if all requested users were found
            $foundIds = $users->pluck('id')->toArray();
            $missingIds = array_diff($idsArray, $foundIds);

            if (!empty($missingIds)) {
                $this->warn('User IDs not found: ' . implode(', ', $missingIds));
            }

            return $users;
        }

        $this->error('You must specify either --users, --role, or --all option.');
        return collect();
    }
}
