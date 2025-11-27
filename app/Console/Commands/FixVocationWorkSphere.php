<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class FixVocationWorkSphere extends Command
{
    protected $signature = 'users:fix-vocation-sphere
                            {--dry-run : Preview without making changes}';

    protected $description = 'Remove sphere assignments for users with attendance = 0';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No changes will be made");
            $this->newLine();
        }

        // Get all users with vocation_work_sphere set (not null and not empty)
        $usersWithSpheres = User::whereNotNull('vocation_work_sphere')
            ->where('vocation_work_sphere', '!=', '')
            ->get();

        $this->info("Total users with vocation_work_sphere set: " . $usersWithSpheres->count());
        $this->newLine();

        // Separate users by attendance status
        $withAttendance = [];
        $withoutAttendance = [];

        foreach ($usersWithSpheres as $user) {
            if ($user->attendance == 1) {
                $withAttendance[] = $user;
            } else {
                $withoutAttendance[] = $user;
            }
        }

        $this->info("Users WITH attendance = 1: " . count($withAttendance) . " (will keep spheres)");
        $this->info("Users WITHOUT attendance = 1: " . count($withoutAttendance) . " (will remove sphere assignments)");
        $this->newLine();

        if (empty($withoutAttendance)) {
            $this->warn("No users need sphere removal!");
            return 0;
        }

        // Show sample of users that will be fixed
        $this->warn("Sample of users whose spheres will be removed:");
        $sample = array_slice($withoutAttendance, 0, 10);
        $this->table(
            ['ID', 'Name', 'Email', 'Attendance', 'Current vocation_work_sphere', 'Sphere Count'],
            array_map(fn($u) => [
                $u->id,
                trim($u->first_name . ' ' . $u->last_name),
                $u->email ?? 'N/A',
                $u->attendance ?? 0,
                $u->vocation_work_sphere,
                $u->spheres()->count()
            ], $sample)
        );
        $this->newLine();

        if ($dryRun) {
            $this->warn("DRY RUN - Would remove spheres from " . count($withoutAttendance) . " users");
            return 0;
        }

        if (!$this->confirm("Remove sphere assignments for " . count($withoutAttendance) . " users without attendance?", true)) {
            $this->warn("Operation cancelled");
            return 0;
        }

        // Fix the users
        $fixed = 0;
        $progressBar = $this->output->createProgressBar(count($withoutAttendance));

        foreach ($withoutAttendance as $user) {
            // Remove sphere assignments from pivot table
            $user->spheres()->detach();

            // Clear vocation_work_sphere column
            $user->vocation_work_sphere = null;
            $user->save();

            $fixed++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("═══════════════════════════════════════");
        $this->info("SPHERE REMOVAL COMPLETED");
        $this->info("═══════════════════════════════════════");
        $this->info("Users cleaned: {$fixed}");
        $this->info("Users with spheres remaining: " . count($withAttendance));

        return 0;
    }
}
