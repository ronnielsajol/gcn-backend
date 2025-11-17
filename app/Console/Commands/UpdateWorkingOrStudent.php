<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class UpdateWorkingOrStudent extends Command
{
  protected $signature = 'users:update-working-status
                            {--dry-run : Preview changes without saving}
                            {--age-range= : Specific age range to filter (e.g., "25-34")}';

  protected $description = 'Update working_or_student to "working" for users not in 18-24 age range';

  public function handle()
  {
    $dryRun = $this->option('dry-run');
    $specificAgeRange = $this->option('age-range');

    if ($dryRun) {
      $this->warn("DRY RUN MODE - No changes will be made");
      $this->newLine();
    }

    // Check if age_range column exists
    if (!Schema::hasColumn('users', 'age_range')) {
      $this->error("The 'age_range' column does not exist in the users table!");
      $this->newLine();

      if ($this->confirm('Would you like to add the age_range column to the users table?')) {
        $this->call('make:migration', ['name' => 'add_age_range_to_users_table']);
        $this->info("Migration created. Please edit it and run 'php artisan migrate'");
      }

      return 1;
    }

    // Get users query
    $query = User::whereNotNull('age_range');

    // Exclude "18-24" age range
    if (!$specificAgeRange) {
      $query->where('age_range', '!=', '18-24');
    } else {
      $query->where('age_range', $specificAgeRange);
    }

    // Get all distinct age ranges first
    $allAgeRanges = User::whereNotNull('age_range')
      ->distinct()
      ->pluck('age_range')
      ->sort();

    $this->info("Age ranges found in database:");
    foreach ($allAgeRanges as $range) {
      $count = User::where('age_range', $range)->count();
      $indicator = $range === '18-24' ? '  (STUDENTS - will be skipped)' : '  (will be set to WORKING)';
      $this->line("  • {$range}: {$count} users{$indicator}");
    }
    $this->newLine();

    $users = $query->get();

    if ($users->isEmpty()) {
      $this->warn("No users found with age_range that needs updating");
      return 0;
    }

    $this->info("Found {$users->count()} users to update");
    $this->newLine();

    // Categorize users by current status
    $byStatus = $users->groupBy('working_or_student');
    $currentWorking = $byStatus->get('working', collect())->count();
    $currentStudent = $byStatus->get('student', collect())->count();
    $currentNull = $byStatus->get(null, collect())->count();

    $this->table(
      ['Current Status', 'Count'],
      [
        ['Working', $currentWorking],
        ['Student', $currentStudent],
        ['NULL/Empty', $currentNull],
        ['TOTAL', $users->count()],
      ]
    );
    $this->newLine();

    // Show sample of users
    $this->info("Sample of users that will be affected (showing first 15):");
    $sample = $users->take(15);
    $this->table(
      ['ID', 'Name', 'Age Range', 'Current Status', 'Will Change To'],
      $sample->map(fn($u) => [
        $u->id,
        trim($u->first_name . ' ' . $u->last_name),
        $u->age_range ?? 'NULL',
        $u->working_or_student ?? 'NULL',
        'working',
      ])
    );
    $this->newLine();

    if ($dryRun) {
      $this->warn("DRY RUN - Would update {$users->count()} users to working_or_student = 'working'");
      return 0;
    }

    if (!$this->confirm("Update {$users->count()} users to working_or_student = 'working'?", true)) {
      $this->warn("Operation cancelled");
      return 0;
    }

    // Update users
    $updated = 0;
    $progressBar = $this->output->createProgressBar($users->count());

    foreach ($users as $user) {
      $user->working_or_student = 'working';
      $user->save();
      $updated++;
      $progressBar->advance();
    }

    $progressBar->finish();
    $this->newLine(2);

    $this->info("═══════════════════════════════════════");
    $this->info("UPDATE COMPLETED");
    $this->info("═══════════════════════════════════════");
    $this->info("Users updated: {$updated}");
    $this->info("All users (except age_range 18-24) are now set to 'working'");

    return 0;
  }
}
