<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FindMissingUsers extends Command
{
  protected $signature = 'users:find-missing
                            {path : Excel filename or path}
                            {--sheet= : Sheet name (default: first sheet)}
                            {--header-row=1 : Row number where headers are located}
                            {--start-row=2 : Start checking from this row}
                            {--last-name-col=A : Column letter for last name}
                            {--first-name-col=B : Column letter for first name}
                            {--export=missing-users.txt : Export results to file}';

  protected $description = 'Find users from Excel sheet that do not exist in database';

  public function handle(): int
  {
    $fileRelative = $this->argument('path');
    $sheetName = $this->option('sheet');
    $headerRow = (int)$this->option('header-row');
    $startRow = (int)$this->option('start-row');
    $lastNameCol = strtoupper($this->option('last-name-col'));
    $firstNameCol = strtoupper($this->option('first-name-col'));
    $exportFile = $this->option('export');

    // Handle file path
    if (str_starts_with($fileRelative, 'storage/app/')) {
      $fileRelative = substr($fileRelative, strlen('storage/app/'));
      $filePath = storage_path("app/{$fileRelative}");
    } elseif (str_starts_with($fileRelative, 'app/')) {
      $filePath = storage_path($fileRelative);
    } elseif (file_exists($fileRelative)) {
      $filePath = $fileRelative;
    } else {
      $filePath = storage_path("app/imports/{$fileRelative}");
    }

    if (!file_exists($filePath)) {
      $this->error("Excel not found: {$filePath}");
      return self::FAILURE;
    }

    $this->info("Loading Excel file: {$filePath}");
    $spreadsheet = IOFactory::load($filePath);

    // Get sheet
    if ($sheetName) {
      $sheet = $spreadsheet->getSheetByName($sheetName);
      if (!$sheet) {
        $this->error("Sheet '{$sheetName}' not found. Available sheets:");
        foreach ($spreadsheet->getSheetNames() as $name) {
          $this->line("  - {$name}");
        }
        return self::FAILURE;
      }
    } else {
      $sheet = $spreadsheet->getActiveSheet();
      $sheetName = $sheet->getTitle();
    }

    $this->info("Using sheet: {$sheetName}");
    $this->info("Columns: Last Name = {$lastNameCol}, First Name = {$firstNameCol}");
    $this->newLine();

    $lastRow = $sheet->getHighestRow();
    $missingUsers = [];
    $foundCount = 0;
    $emptyCount = 0;

    $progressBar = $this->output->createProgressBar($lastRow - $startRow + 1);
    $progressBar->setFormat('Processing: %current%/%max% [%bar%] %percent:3s%%');

    for ($row = $startRow; $row <= $lastRow; $row++) {
      $lastName = trim((string)$sheet->getCell("{$lastNameCol}{$row}")->getCalculatedValue());
      $firstName = trim((string)$sheet->getCell("{$firstNameCol}{$row}")->getCalculatedValue());

      // Skip empty rows
      if (empty($lastName) && empty($firstName)) {
        $emptyCount++;
        $progressBar->advance();
        continue;
      }

      // Skip if both names are N/A
      if (strtoupper($lastName) === 'N/A' && strtoupper($firstName) === 'N/A') {
        $emptyCount++;
        $progressBar->advance();
        continue;
      }

      // Search for user in database (case-insensitive)
      $exists = User::whereRaw('LOWER(TRIM(first_name)) = ? AND LOWER(TRIM(last_name)) = ?', [
        mb_strtolower($firstName),
        mb_strtolower($lastName)
      ])->exists();

      if ($exists) {
        $foundCount++;
      } else {
        $missingUsers[] = [
          'row' => $row,
          'last_name' => $lastName,
          'first_name' => $firstName,
          'full_name' => trim("{$firstName} {$lastName}")
        ];
      }

      $progressBar->advance();
    }

    $progressBar->finish();
    $this->newLine(2);

    // Display results
    $this->info("═══════════════════════════════════════");
    $this->info("SUMMARY");
    $this->info("═══════════════════════════════════════");
    $this->info("Total rows processed: " . ($lastRow - $startRow + 1 - $emptyCount));
    $this->info("Found in database: {$foundCount}");
    $this->error("NOT found in database: " . count($missingUsers));
    $this->info("Empty/skipped rows: {$emptyCount}");
    $this->newLine();

    if (empty($missingUsers)) {
      $this->info("✓ All users from Excel exist in the database!");
      return self::SUCCESS;
    }

    // Display missing users
    $this->warn("Users NOT found in database:");
    $this->newLine();

    $this->table(
      ['Row', 'Last Name', 'First Name', 'Full Name'],
      array_map(fn($u) => [
        $u['row'],
        $u['last_name'],
        $u['first_name'],
        $u['full_name']
      ], $missingUsers)
    );

    // Export to file if requested
    if ($exportFile) {
      $exportPath = storage_path("app/{$exportFile}");
      $content = "Missing Users Report\n";
      $content .= "Generated: " . now()->toDateTimeString() . "\n";
      $content .= "Excel File: {$filePath}\n";
      $content .= "Sheet: {$sheetName}\n";
      $content .= "Total Missing: " . count($missingUsers) . "\n";
      $content .= str_repeat("=", 80) . "\n\n";
      $content .= sprintf("%-6s %-25s %-25s %s\n", "Row", "Last Name", "First Name", "Full Name");
      $content .= str_repeat("-", 80) . "\n";

      foreach ($missingUsers as $user) {
        $content .= sprintf(
          "%-6d %-25s %-25s %s\n",
          $user['row'],
          $user['last_name'],
          $user['first_name'],
          $user['full_name']
        );
      }

      file_put_contents($exportPath, $content);
      $this->newLine();
      $this->info("✓ Results exported to: {$exportPath}");
    }

    return self::SUCCESS;
  }
}
