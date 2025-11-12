<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Sphere;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncUserSpheres extends Command
{
  protected $signature = 'users:sync-spheres
                            {--user-id= : Specific user ID to sync}
                            {--sphere-id= : Sphere ID to attach to users}
                            {--all : Sync all users with NULL vocation_work_sphere}
                            {--dry-run : Preview without making changes}';

  protected $description = 'Sync user sphere relationships to the pivot table';

  public function handle()
  {
    $userId = $this->option('user-id');
    $sphereId = $this->option('sphere-id');
    $all = $this->option('all');
    $dryRun = $this->option('dry-run');

    if ($dryRun) {
      $this->warn("DRY RUN MODE - No changes will be made");
      $this->newLine();
    }

    // Show available spheres
    $this->info("Available Spheres:");
    $spheres = Sphere::all();
    $this->table(
      ['ID', 'Name', 'Slug'],
      $spheres->map(fn($s) => [$s->id, $s->name, $s->slug])
    );
    $this->newLine();

    // Get users to process
    $query = User::query();

    if ($userId) {
      $query->where('id', $userId);
    } elseif ($all) {
      // Users with no sphere relationships
      $query->whereDoesntHave('spheres');
    } else {
      $this->error("Please specify --user-id, --sphere-id with --all, or --all option");
      return 1;
    }

    $users = $query->get();

    if ($users->isEmpty()) {
      $this->warn("No users found to process");
      return 0;
    }

    $this->info("Found {$users->count()} users to process");
    $this->newLine();

    if (!$sphereId && !$this->option('all')) {
      $sphereId = $this->ask('Enter the Sphere ID to attach to these users');
    }

    // Validate sphere
    if ($sphereId) {
      $sphere = Sphere::find($sphereId);
      if (!$sphere) {
        $this->error("Sphere ID {$sphereId} not found!");
        return 1;
      }

      $this->info("Will attach sphere: {$sphere->name} (ID: {$sphere->id})");
      $this->newLine();
    }

    // Show users to be processed
    if ($users->count() <= 20) {
      $this->table(
        ['ID', 'Name', 'Email', 'Current Spheres'],
        $users->map(fn($u) => [
          $u->id,
          trim($u->first_name . ' ' . $u->last_name),
          $u->email,
          $u->spheres->pluck('name')->join(', ') ?: 'None'
        ])
      );
    } else {
      $this->info("Showing first 20 users:");
      $this->table(
        ['ID', 'Name', 'Email', 'Current Spheres'],
        $users->take(20)->map(fn($u) => [
          $u->id,
          trim($u->first_name . ' ' . $u->last_name),
          $u->email,
          $u->spheres->pluck('name')->join(', ') ?: 'None'
        ])
      );
    }
    $this->newLine();

    if ($dryRun) {
      $this->warn("DRY RUN - Would attach sphere to {$users->count()} users");
      return 0;
    }

    if (!$this->confirm("Proceed with syncing {$users->count()} users?", true)) {
      $this->warn("Sync cancelled");
      return 0;
    }

    // Process users
    $attached = 0;
    $skipped = 0;

    $progressBar = $this->output->createProgressBar($users->count());

    foreach ($users as $user) {
      if ($sphereId) {
        // Check if already attached
        if (!$user->spheres->contains($sphereId)) {
          $user->spheres()->attach($sphereId);
          $attached++;
        } else {
          $skipped++;
        }
      }
      $progressBar->advance();
    }

    $progressBar->finish();
    $this->newLine(2);

    $this->info("═══════════════════════════════════════");
    $this->info("SYNC COMPLETED");
    $this->info("═══════════════════════════════════════");
    $this->info("Users processed: {$users->count()}");
    $this->info("Spheres attached: {$attached}");
    $this->info("Already attached (skipped): {$skipped}");

    return 0;
  }
}
