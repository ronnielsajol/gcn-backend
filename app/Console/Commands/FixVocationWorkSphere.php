<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class FixVocationWorkSphere extends Command
{
  protected $signature = 'users:fix-vocation-sphere
                            {--dry-run : Preview without making changes}';

  protected $description = 'Fix vocation_work_sphere for users without sphere relationships';

  public function handle()
  {
    $dryRun = $this->option('dry-run');

    if ($dryRun) {
      $this->warn("DRY RUN MODE - No changes will be made");
      $this->newLine();
    }

    // Get users with vocation_work_sphere = 1
    $usersWithOne = User::where('vocation_work_sphere', 1)->get();

    $this->info("Total users with vocation_work_sphere = 1: " . $usersWithOne->count());
    $this->newLine();

    // Separate users with and without sphere relationships
    $withSpheres = [];
    $withoutSpheres = [];

    foreach ($usersWithOne as $user) {
      if ($user->spheres()->count() > 0) {
        $withSpheres[] = $user;
      } else {
        $withoutSpheres[] = $user;
      }
    }

    $this->info("Users WITH sphere relationships: " . count($withSpheres) . " (will keep vocation_work_sphere = 1)");
    $this->info("Users WITHOUT sphere relationships: " . count($withoutSpheres) . " (will set to NULL)");
    $this->newLine();

    if (empty($withoutSpheres)) {
      $this->warn("No users need to be fixed!");
      return 0;
    }

    // Show sample of users that will be fixed
    $this->warn("Sample of users that will be set to NULL:");
    $sample = array_slice($withoutSpheres, 0, 10);
    $this->table(
      ['ID', 'Name', 'Email', 'Current vocation_work_sphere'],
      array_map(fn($u) => [
        $u->id,
        trim($u->first_name . ' ' . $u->last_name),
        $u->email ?? 'N/A',
        $u->vocation_work_sphere
      ], $sample)
    );
    $this->newLine();

    if ($dryRun) {
      $this->warn("DRY RUN - Would set " . count($withoutSpheres) . " users to NULL");
      return 0;
    }

    if (!$this->confirm("Set vocation_work_sphere to NULL for " . count($withoutSpheres) . " users?", true)) {
      $this->warn("Operation cancelled");
      return 0;
    }

    // Fix the users
    $fixed = 0;
    $progressBar = $this->output->createProgressBar(count($withoutSpheres));

    foreach ($withoutSpheres as $user) {
      $user->vocation_work_sphere = null;
      $user->save();
      $fixed++;
      $progressBar->advance();
    }

    $progressBar->finish();
    $this->newLine(2);

    $this->info("═══════════════════════════════════════");
    $this->info("FIX COMPLETED");
    $this->info("═══════════════════════════════════════");
    $this->info("Users fixed: {$fixed}");
    $this->info("Users with vocation_work_sphere = 1 remaining: " . count($withSpheres));

    return 0;
  }
}
