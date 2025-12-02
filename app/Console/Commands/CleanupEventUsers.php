<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Event;
use Illuminate\Console\Command;

class CleanupEventUsers extends Command
{
    protected $signature = 'users:cleanup-event
                            {event-id : Event ID to cleanup users from}
                            {--dry-run : Preview without making changes}';

    protected $description = 'Delete users if event is their only event, otherwise just detach from event';

    public function handle(): int
    {
        $eventId = $this->argument('event-id');
        $dryRun = $this->option('dry-run');

        // Validate event exists
        $event = Event::find($eventId);
        if (!$event) {
            $this->error("Event with ID {$eventId} not found.");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No changes will be made");
            $this->newLine();
        }

        $this->info("Event: {$event->name} (ID: {$eventId})");
        $this->newLine();

        // Get all users attached to this event
        $usersInEvent = $event->users()->get();

        if ($usersInEvent->isEmpty()) {
            $this->warn("No users found in this event.");
            return self::SUCCESS;
        }

        $this->info("Total users in event: " . $usersInEvent->count());
        $this->newLine();

        // Categorize users
        $usersToDelete = [];
        $usersToDetach = [];

        foreach ($usersInEvent as $user) {
            $eventCount = $user->events()->count();
            
            if ($eventCount === 1) {
                // This is their only event - mark for deletion
                $usersToDelete[] = [
                    'user' => $user,
                    'event_count' => $eventCount
                ];
            } else {
                // They have multiple events - just detach
                $usersToDetach[] = [
                    'user' => $user,
                    'event_count' => $eventCount
                ];
            }
        }

        // Display summary
        $this->info("═══════════════════════════════════════");
        $this->info("CLEANUP SUMMARY");
        $this->info("═══════════════════════════════════════");
        $this->info("Users to DELETE (only in this event): " . count($usersToDelete));
        $this->info("Users to DETACH (in multiple events): " . count($usersToDetach));
        $this->newLine();

        // Show users to delete
        if (!empty($usersToDelete)) {
            $this->error("═══════════════════════════════════════");
            $this->error("USERS TO DELETE");
            $this->error("═══════════════════════════════════════");
            $sample = array_slice($usersToDelete, 0, 20);
            $this->table(
                ['ID', 'Name', 'Email', 'Events'],
                array_map(fn($item) => [
                    $item['user']->id,
                    trim($item['user']->first_name . ' ' . $item['user']->last_name),
                    $item['user']->email ?? 'N/A',
                    $item['event_count']
                ], $sample)
            );
            
            if (count($usersToDelete) > 20) {
                $this->line("... and " . (count($usersToDelete) - 20) . " more users");
            }
            $this->newLine();
        }

        // Show users to detach
        if (!empty($usersToDetach)) {
            $this->warn("═══════════════════════════════════════");
            $this->warn("USERS TO DETACH (Keep User, Remove Event)");
            $this->warn("═══════════════════════════════════════");
            $sample = array_slice($usersToDetach, 0, 20);
            $this->table(
                ['ID', 'Name', 'Email', 'Events'],
                array_map(fn($item) => [
                    $item['user']->id,
                    trim($item['user']->first_name . ' ' . $item['user']->last_name),
                    $item['user']->email ?? 'N/A',
                    $item['event_count']
                ], $sample)
            );
            
            if (count($usersToDetach) > 20) {
                $this->line("... and " . (count($usersToDetach) - 20) . " more users");
            }
            $this->newLine();
        }

        if ($dryRun) {
            $this->warn("DRY RUN - No actual changes made");
            $this->info("Would delete: " . count($usersToDelete) . " users");
            $this->info("Would detach: " . count($usersToDetach) . " users from event");
            return self::SUCCESS;
        }

        // Confirm before proceeding
        $this->warn("⚠️  WARNING: This will permanently delete " . count($usersToDelete) . " users!");
        if (!$this->confirm("Proceed with cleanup?", false)) {
            $this->warn("Operation cancelled");
            return self::SUCCESS;
        }

        // Execute cleanup
        $deleted = 0;
        $detached = 0;

        // Delete users with only this event
        if (!empty($usersToDelete)) {
            $this->info("\nDeleting users...");
            $progressBar = $this->output->createProgressBar(count($usersToDelete));

            foreach ($usersToDelete as $item) {
                $user = $item['user'];
                
                // Detach from all events first
                $user->events()->detach();
                
                // Detach from all spheres
                $user->spheres()->detach();
                
                // Delete the user
                $user->delete();
                
                $deleted++;
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();
        }

        // Detach users with multiple events
        if (!empty($usersToDetach)) {
            $this->info("\nDetaching users from event...");
            $progressBar = $this->output->createProgressBar(count($usersToDetach));

            foreach ($usersToDetach as $item) {
                $user = $item['user'];
                
                // Only detach from this specific event
                $user->events()->detach($eventId);
                
                $detached++;
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();
        }

        // Final summary
        $this->newLine();
        $this->info("═══════════════════════════════════════");
        $this->info("CLEANUP COMPLETED");
        $this->info("═══════════════════════════════════════");
        $this->info("Users deleted: {$deleted}");
        $this->info("Users detached: {$detached}");
        $this->info("Total users remaining in event: " . $event->users()->count());

        return self::SUCCESS;
    }
}
