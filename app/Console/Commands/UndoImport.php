<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UndoImport extends Command
{
  protected $signature = 'import:undo
        {--dry-run : Preview what would be deleted without actually deleting}
        {--force : Force delete (permanent deletion)}
        {--event-id= : Only delete users attached to specific event ID}
        {--created-after= : Delete users created after this date/time (Y-m-d H:i:s format) - REQUIRED}
        {--created-before= : Delete users created before this date/time (Y-m-d H:i:s format)}';

  protected $description = 'Undo recent import by deleting users created within a time range';

  public function handle(): int
  {
    $dryRun = (bool)$this->option('dry-run');
    $forceDelete = (bool)$this->option('force');
    $eventId = $this->option('event-id');
    $createdAfter = $this->option('created-after');
    $createdBefore = $this->option('created-before');

    if (!$createdAfter) {
      $this->error("The --created-after option is required!");
      $this->info("Example: --created-after=\"2025-11-07 14:30:00\"");
      return self::FAILURE;
    }

    // Build query
    $query = User::where('role', 'user')
      ->where('created_at', '>', $createdAfter);

    if ($createdBefore) {
      $query->where('created_at', '<', $createdBefore);
    }

    // Filter by event if specified
    if ($eventId) {
      $query->whereHas('events', function ($q) use ($eventId) {
        $q->where('events.id', $eventId);
      });
    }

    $users = $query->orderBy('created_at', 'desc')->get();
    $count = $users->count();

    if ($count === 0) {
      $this->info("No users found matching the criteria.");
      return self::SUCCESS;
    }

    $this->warn("Found {$count} users to delete");
    $this->line("Created after: {$createdAfter}");
    if ($createdBefore) {
      $this->line("Created before: {$createdBefore}");
    }
    if ($eventId) {
      $this->line("Attached to event ID: {$eventId}");
    }

    if ($dryRun) {
      $this->warn("\n=== DRY RUN MODE - Preview (first 20 users) ===");
      foreach ($users->take(20) as $user) {
        $this->line(sprintf(
          "ID: %-5d | %s %s | %s | Created: %s",
          $user->id,
          $user->first_name ?? '[NULL]',
          $user->last_name ?? '[NULL]',
          $user->email ?? '[NO EMAIL]',
          $user->created_at
        ));
      }

      if ($count > 20) {
        $this->line("... and " . ($count - 20) . " more users");
      }

      $this->warn("\nDRY RUN - Would delete {$count} users");
      $this->info("\nTo actually delete, run without --dry-run flag");
    } else {
      $this->warn("\n⚠️  WARNING: You are about to delete {$count} users!");
      $this->line("This will:");
      if ($forceDelete) {
        $this->error("  • PERMANENTLY delete users (cannot be recovered)");
      } else {
        $this->line("  • Soft delete users (can be recovered)");
      }
      $this->line("  • Remove them from all events");
      $this->line("  • Remove their sphere associations");

      if (!$this->confirm("\nAre you absolutely sure?")) {
        $this->info("Operation cancelled.");
        return self::SUCCESS;
      }

      if ($forceDelete && !$this->confirm("FINAL WARNING: Force delete is permanent. Continue?")) {
        $this->info("Operation cancelled.");
        return self::SUCCESS;
      }

      $deleted = 0;
      $failed = 0;
      $progressBar = $this->output->createProgressBar($count);

      foreach ($users as $user) {
        try {
          if ($forceDelete) {
            $user->forceDelete();
          } else {
            $user->delete();
          }
          $deleted++;
        } catch (\Throwable $e) {
          $failed++;
          $this->error("\nFailed to delete user ID {$user->id}: " . $e->getMessage());
        }
        $progressBar->advance();
      }

      $progressBar->finish();
      $this->line("\n");

      if ($forceDelete) {
        $this->info("Permanently deleted {$deleted} out of {$count} users.");
      } else {
        $this->info("Soft deleted {$deleted} out of {$count} users.");
      }

      if ($failed > 0) {
        $this->warn("Failed to delete {$failed} users.");
      }
    }

    return self::SUCCESS;
  }
}
