<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportAgeRanges extends Command
{
  protected $signature = 'import:age-ranges
                            {file : Path to the Excel file}
                            {--start-row=2 : Starting row (default: 2)}
                            {--end-row=554 : Ending row (default: 554)}
                            {--last-name-col=A : Column for last name}
                            {--first-name-col=B : Column for first name}
                            {--age-range-col=C : Column for age range}
                            {--dry-run : Preview without saving}';

  protected $description = 'Import age ranges from Excel file and update matching users';

  public function handle()
  {
    $filePath = $this->argument('file');
    $startRow = (int) $this->option('start-row');
    $endRow = (int) $this->option('end-row');
    $lastNameCol = $this->option('last-name-col');
    $firstNameCol = $this->option('first-name-col');
    $ageRangeCol = $this->option('age-range-col');
    $dryRun = $this->option('dry-run');

    // Resolve file path
    if (!file_exists($filePath)) {
      $storagePath = storage_path('app/' . ltrim($filePath, '/'));
      if (file_exists($storagePath)) {
        $filePath = $storagePath;
      } else {
        $this->error("File not found: {$filePath}");
        return 1;
      }
    }

    if ($dryRun) {
      $this->warn("DRY RUN MODE - No changes will be made");
      $this->newLine();
    }

    $this->info("Loading file: {$filePath}");
    $this->info("Processing rows {$startRow} to {$endRow}");
    $this->newLine();

    try {
      $spreadsheet = IOFactory::load($filePath);
      $sheet = $spreadsheet->getActiveSheet();

      $matched = 0;
      $notFound = 0;
      $updated = 0;
      $alreadyHasValue = 0;
      $matchedUsers = [];
      $notFoundUsers = [];

      $this->info("Searching for matching users...");
      $progressBar = $this->output->createProgressBar($endRow - $startRow + 1);

      for ($row = $startRow; $row <= $endRow; $row++) {
        $lastName = trim($sheet->getCell($lastNameCol . $row)->getValue() ?? '');
        $firstName = trim($sheet->getCell($firstNameCol . $row)->getValue() ?? '');
        $ageRange = trim($sheet->getCell($ageRangeCol . $row)->getValue() ?? '');

        // Skip empty rows
        if (empty($lastName) && empty($firstName)) {
          $progressBar->advance();
          continue;
        }

        // Search for matching user (case-insensitive)
        $user = User::whereRaw('LOWER(TRIM(first_name)) = ? AND LOWER(TRIM(last_name)) = ?', [
          mb_strtolower($firstName),
          mb_strtolower($lastName)
        ])->first();

        if ($user) {
          $matched++;

          $matchedUsers[] = [
            'row' => $row,
            'user_id' => $user->id,
            'name' => trim($firstName . ' ' . $lastName),
            'age_range_excel' => $ageRange,
            'age_range_db' => $user->age_range,
            'will_update' => empty($user->age_range) || $user->age_range !== $ageRange,
          ];

          if (!$dryRun) {
            if (empty($user->age_range)) {
              $user->age_range = $ageRange;
              $user->save();
              $updated++;
            } elseif ($user->age_range !== $ageRange) {
              $user->age_range = $ageRange;
              $user->save();
              $updated++;
            } else {
              $alreadyHasValue++;
            }
          }
        } else {
          $notFound++;
          $notFoundUsers[] = [
            'row' => $row,
            'name' => trim($firstName . ' ' . $lastName),
            'age_range' => $ageRange,
          ];
        }

        $progressBar->advance();
      }

      $progressBar->finish();
      $this->newLine(2);

      // Display summary
      $this->info("═══════════════════════════════════════");
      $this->info("IMPORT SUMMARY");
      $this->info("═══════════════════════════════════════");
      $this->info("Total rows processed: " . ($endRow - $startRow + 1));
      $this->info("Users matched: {$matched}");
      $this->info("Users not found: {$notFound}");

      if (!$dryRun) {
        $this->info("Users updated: {$updated}");
        $this->info("Users already had correct value: {$alreadyHasValue}");
      }
      $this->newLine();

      // Show sample of matched users
      if (!empty($matchedUsers)) {
        $willUpdate = array_filter($matchedUsers, fn($u) => $u['will_update']);
        $willSkip = array_filter($matchedUsers, fn($u) => !$u['will_update']);

        if (!empty($willUpdate)) {
          $this->info("Sample of matched users that " . ($dryRun ? "WILL BE" : "WERE") . " updated (showing first 15):");
          $this->table(
            ['Row', 'User ID', 'Name', 'Age Range (Excel)', 'Age Range (DB Before)'],
            array_map(fn($u) => [
              $u['row'],
              $u['user_id'],
              $u['name'],
              $u['age_range_excel'],
              $u['age_range_db'] ?: '(empty)',
            ], array_slice($willUpdate, 0, 15))
          );
          $this->newLine();
        }

        if (!empty($willSkip) && count($willSkip) <= 10) {
          $this->info("Users already had correct age range (skipped):");
          $this->table(
            ['User ID', 'Name', 'Age Range'],
            array_map(fn($u) => [
              $u['user_id'],
              $u['name'],
              $u['age_range_db'],
            ], $willSkip)
          );
          $this->newLine();
        }
      }

      // Show sample of not found users
      if (!empty($notFoundUsers)) {
        $this->warn("Users NOT FOUND in database (showing first 20):");
        $this->table(
          ['Row', 'Name (from Excel)', 'Age Range'],
          array_map(fn($u) => [
            $u['row'],
            $u['name'],
            $u['age_range'],
          ], array_slice($notFoundUsers, 0, 20))
        );
        $this->newLine();
      }

      if ($dryRun) {
        $this->warn("DRY RUN - No changes were made");
        $this->info("Run without --dry-run to apply changes");
      } else {
        $this->info("✓ Import completed successfully!");
      }

      return 0;
    } catch (\Exception $e) {
      $this->error("Error reading file: " . $e->getMessage());
      $this->error($e->getTraceAsString());
      return 1;
    }
  }
}
