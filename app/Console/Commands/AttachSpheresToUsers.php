<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Sphere;
use Illuminate\Console\Command;

class AttachSpheresToUsers extends Command
{
  protected $signature = 'users:attach-spheres
                            {--sphere-id=* : Sphere IDs to attach (can specify multiple)}
                            {--all-spheres : Attach all available spheres}
                            {--role=user : Only attach to users with this role (default: user)}
                            {--without-spheres : Only process users who have no spheres yet}
                            {--dry-run : Preview without making changes}';

  protected $description = 'Attach spheres to users (filtered by role)';

  public function handle()
  {
    $sphereIds = $this->option('sphere-id');
    $allSpheres = $this->option('all-spheres');
    $role = $this->option('role');
    $withoutSpheresOnly = $this->option('without-spheres');
    $dryRun = $this->option('dry-run');

    if ($dryRun) {
      $this->warn("DRY RUN MODE - No changes will be made");
      $this->newLine();
    }

    // Show available spheres
    $this->info("Available Spheres:");
    $availableSpheres = Sphere::all();
    $this->table(
      ['ID', 'Name', 'Slug'],
      $availableSpheres->map(fn($s) => [$s->id, $s->name, $s->slug])
    );
    $this->newLine();

    // Determine which spheres to attach
    $spheresToAttach = [];

    if ($allSpheres) {
      $spheresToAttach = $availableSpheres->pluck('id')->toArray();
      $this->info("Will attach ALL spheres");
    } elseif (!empty($sphereIds)) {
      $spheresToAttach = $sphereIds;
      $this->info("Will attach sphere IDs: " . implode(', ', $sphereIds));
    } else {
      // Interactive selection
      $sphereId = $this->ask('Enter Sphere ID to attach (or comma-separated IDs for multiple)');
      $spheresToAttach = array_map('trim', explode(',', $sphereId));
    }

    // Validate spheres
    $validSpheres = Sphere::whereIn('id', $spheresToAttach)->get();
    if ($validSpheres->count() !== count($spheresToAttach)) {
      $this->error("Some sphere IDs are invalid!");
      return 1;
    }

    $this->info("Spheres to attach:");
    foreach ($validSpheres as $sphere) {
      $this->line("  • [{$sphere->id}] {$sphere->name}");
    }
    $this->newLine();

    // Get users to process
    $query = User::where('role', $role);

    if ($withoutSpheresOnly) {
      $query->whereDoesntHave('spheres');
    }

    $users = $query->get();

    if ($users->isEmpty()) {
      $this->warn("No users found with role '{$role}'");
      return 0;
    }

    $this->info("Found {$users->count()} users with role '{$role}'");
    $this->newLine();

    // Categorize users
    $usersToProcess = [];
    $usersAlreadyHaveAll = [];

    foreach ($users as $user) {
      $currentSphereIds = $user->spheres->pluck('id')->toArray();
      $missingIds = array_diff($spheresToAttach, $currentSphereIds);

      if (!empty($missingIds)) {
        $usersToProcess[] = [
          'user' => $user,
          'missing_ids' => $missingIds,
          'current_count' => count($currentSphereIds),
        ];
      } else {
        $usersAlreadyHaveAll[] = $user;
      }
    }

    $this->info("Users that will be updated: " . count($usersToProcess));
    $this->info("Users that already have all spheres: " . count($usersAlreadyHaveAll));
    $this->newLine();

    if (empty($usersToProcess)) {
      $this->warn("All users already have the selected spheres!");
      return 0;
    }

    // Show sample
    $this->info("Sample of users that will be updated (showing first 10):");
    $sample = array_slice($usersToProcess, 0, 10);
    $this->table(
      ['ID', 'Name', 'Email', 'Current Spheres', 'Will Add'],
      array_map(function ($item) use ($validSpheres) {
        $user = $item['user'];
        $currentSpheres = $user->spheres->pluck('name')->join(', ') ?: 'None';
        $willAdd = $validSpheres->whereIn('id', $item['missing_ids'])->pluck('name')->join(', ');

        return [
          $user->id,
          trim($user->first_name . ' ' . $user->last_name),
          $user->email ?? 'N/A',
          strlen($currentSpheres) > 30 ? substr($currentSpheres, 0, 27) . '...' : $currentSpheres,
          $willAdd,
        ];
      }, $sample)
    );
    $this->newLine();

    if ($dryRun) {
      $this->warn("DRY RUN - Would attach spheres to " . count($usersToProcess) . " users");
      return 0;
    }

    if (!$this->confirm("Attach spheres to " . count($usersToProcess) . " users?", true)) {
      $this->warn("Operation cancelled");
      return 0;
    }

    // Process users
    $attachmentCount = 0;
    $progressBar = $this->output->createProgressBar(count($usersToProcess));

    foreach ($usersToProcess as $item) {
      $user = $item['user'];
      $missingIds = $item['missing_ids'];

      // Attach missing spheres (without detaching existing ones)
      $user->spheres()->syncWithoutDetaching($missingIds);
      $attachmentCount += count($missingIds);

      $progressBar->advance();
    }

    $progressBar->finish();
    $this->newLine(2);

    $this->info("═══════════════════════════════════════");
    $this->info("OPERATION COMPLETED");
    $this->info("═══════════════════════════════════════");
    $this->info("Users updated: " . count($usersToProcess));
    $this->info("Total sphere attachments made: {$attachmentCount}");
    $this->info("Users skipped (already had spheres): " . count($usersAlreadyHaveAll));

    return 0;
  }
}
