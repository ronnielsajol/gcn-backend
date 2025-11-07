<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CleanupNullUsers extends Command
{
    protected $signature = 'users:cleanup-null
        {--dry-run : Preview what would be deleted without actually deleting}
        {--force : Force delete (bypass soft deletes)}
        {--trashed-only : Only target already soft-deleted users}
        {--event-id= : Only delete users attached to specific event ID}
        {--created-after= : Only delete users created after this date (Y-m-d H:i:s format)}';

    protected $description = 'Delete users with null or empty first_name AND last_name (keeping role=user)';

    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $forceDelete = (bool)$this->option('force');
        $trashedOnly = (bool)$this->option('trashed-only');
        $eventId = $this->option('event-id');
        $createdAfter = $this->option('created-after');

        // Build query for users with null/empty names but role=user
        // Check for both NULL and empty strings
        $query = User::where('role', 'user')
            ->where(function ($q) {
                $q->whereNull('first_name')
                    ->orWhere('first_name', '')
                    ->orWhere('first_name', ' ');
            })
            ->where(function ($q) {
                $q->whereNull('last_name')
                    ->orWhere('last_name', '')
                    ->orWhere('last_name', ' ');
            });

        // Include trashed users if needed
        if ($trashedOnly) {
            $query->onlyTrashed();
            $this->info("Targeting only soft-deleted users...");
        } else {
            // Default behavior - don't include trashed
            $query->withoutTrashed();
        }

        // Filter by event if specified
        if ($eventId) {
            $query->whereHas('events', function ($q) use ($eventId) {
                $q->where('events.id', $eventId);
            });
        }

        // Filter by creation date if specified
        if ($createdAfter) {
            $query->where('created_at', '>', $createdAfter);
        }

        $nullUsers = $query->get();
        $count = $nullUsers->count();

        if ($count === 0) {
            $this->info("No null/empty users found matching the criteria.");
            return self::SUCCESS;
        }

        $this->warn("Found {$count} users with null/empty first_name and last_name");

        if ($dryRun) {
            $this->warn("\nDRY RUN MODE - Showing preview (first 10):");
            foreach ($nullUsers->take(10) as $user) {
                $firstName = $user->first_name === null ? 'NULL' : "'{$user->first_name}'";
                $lastName = $user->last_name === null ? 'NULL' : "'{$user->last_name}'";
                $deletedStatus = $user->deleted_at ? " [SOFT DELETED: {$user->deleted_at}]" : "";
                $this->line("ID: {$user->id}, First: {$firstName}, Last: {$lastName}, Email: {$user->email}, Created: {$user->created_at}{$deletedStatus}");
            }

            if ($count > 10) {
                $this->line("... and " . ($count - 10) . " more users");
            }

            $this->warn("\nDRY RUN - Would delete {$count} users");
            if ($trashedOnly || $forceDelete) {
                $this->warn("Mode: PERMANENT deletion (cannot be recovered)");
            } else {
                $this->info("Mode: Soft delete (can be recovered)");
            }
            $this->info("\nTo actually delete, run without --dry-run flag");
        } else {
            if (!$this->confirm("Are you sure you want to delete {$count} users? This cannot be undone!")) {
                $this->info("Operation cancelled.");
                return self::SUCCESS;
            }

            if ($forceDelete || $trashedOnly) {
                $this->warn("PERMANENT delete mode - users will be permanently removed from database");
            }

            $deleted = 0;
            $failed = 0;
            foreach ($nullUsers as $user) {
                try {
                    // Force delete if requested or if working with trashed users
                    if ($forceDelete || $trashedOnly) {
                        if (method_exists($user, 'forceDelete')) {
                            $user->forceDelete();
                        } else {
                            $user->delete();
                        }
                    } else {
                        $user->delete();
                    }
                    $deleted++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("Failed to delete user ID {$user->id}: " . $e->getMessage());
                }
            }

            $this->info("Successfully deleted {$deleted} out of {$count} null/empty users.");
            if ($failed > 0) {
                $this->warn("Failed to delete {$failed} users.");
            }
        }

        return self::SUCCESS;
    }
}
