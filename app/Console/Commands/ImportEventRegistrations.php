<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Sphere;
use App\Models\User;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportEventRegistrations extends Command
{
  protected $signature = 'import:event-registrations
                            {file : Path to the Excel file}
                            {--day1-event-id= : Event ID for Day 1 attendance}
                            {--day2-event-id= : Event ID for Day 2 attendance}
                            {--day3-event-id= : Event ID for Day 3 attendance}
                            {--header-row=1 : Row number where headers are located}
                            {--start-col=A : Starting column for data}
                            {--end-col=H : Ending column for data}
                            {--dry-run : Preview what will be imported without saving}';

  protected $description = 'Import event registrations with day attendance tracking';

  // Mapping from Excel areas to database spheres
  protected $areaMapping = [
    'economics' => 'Business/economics',
    'education' => 'Education/sports',
    'intellectual' => 'Education/sports',
    'political' => 'Government/law',
    'social' => 'Family/community',
    'spiritual' => 'Church/ministry',
  ];

  public function handle()
  {
    $filePath = $this->argument('file');
    $headerRow = (int) $this->option('header-row');
    $startCol = $this->option('start-col');
    $endCol = $this->option('end-col');
    $dryRun = (bool) $this->option('dry-run');

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

    // Get event IDs for each day
    $day1EventId = $this->option('day1-event-id');
    $day2EventId = $this->option('day2-event-id');
    $day3EventId = $this->option('day3-event-id');

    if (!$day1EventId && !$day2EventId && !$day3EventId) {
      $this->error("At least one event ID must be provided (--day1-event-id, --day2-event-id, or --day3-event-id)");
      return 1;
    }

    // Validate events exist
    $events = [];
    if ($day1EventId) {
      $events['day1'] = Event::find($day1EventId);
      if (!$events['day1']) {
        $this->error("Event ID {$day1EventId} not found for Day 1");
        return 1;
      }
    }
    if ($day2EventId) {
      $events['day2'] = Event::find($day2EventId);
      if (!$events['day2']) {
        $this->error("Event ID {$day2EventId} not found for Day 2");
        return 1;
      }
    }
    if ($day3EventId) {
      $events['day3'] = Event::find($day3EventId);
      if (!$events['day3']) {
        $this->error("Event ID {$day3EventId} not found for Day 3");
        return 1;
      }
    }

    $this->info("Loading file: {$filePath}");
    $this->newLine();

    try {
      $spreadsheet = IOFactory::load($filePath);
      $sheet = $spreadsheet->getActiveSheet();
      $highestRow = $sheet->getHighestRow();

      // Read headers
      $headers = [];
      for ($col = $startCol; $col <= $endCol; $col++) {
        $cellValue = $sheet->getCell($col . $headerRow)->getValue();
        if ($cellValue) {
          $headers[$col] = trim(strtolower($cellValue));
        }
      }

      $this->info("Headers found: " . implode(', ', $headers));
      $this->newLine();

      // Find column indices
      $lastNameCol = array_search('last name', $headers);
      $firstNameCol = array_search('first name', $headers);
      $areasCol = array_search('areas', $headers);
      $emailCol = array_search('email', $headers);
      $contactCol = array_search('contact number', $headers);
      $day1Col = array_search('day 1', $headers);
      $day2Col = array_search('day 2', $headers);
      $day3Col = array_search('day 3', $headers);

      if (!$lastNameCol || !$firstNameCol) {
        $this->error("Required columns not found: 'last name' and 'first name' are required");
        return 1;
      }

      // Process rows and check for existing users
      $dataRows = [];
      $existingUsers = [];
      $newUsers = [];

      for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
        $lastName = trim($sheet->getCell($lastNameCol . $row)->getValue() ?? '');
        $firstName = trim($sheet->getCell($firstNameCol . $row)->getValue() ?? '');

        if (empty($lastName) && empty($firstName)) {
          continue; // Skip empty rows
        }

        // Set to N/A if missing
        if (empty($firstName)) $firstName = 'N/A';
        if (empty($lastName)) $lastName = 'N/A';

        $areas = $areasCol ? trim($sheet->getCell($areasCol . $row)->getValue() ?? '') : '';
        $email = $emailCol ? trim($sheet->getCell($emailCol . $row)->getValue() ?? '') : null;
        $contact = $contactCol ? trim($sheet->getCell($contactCol . $row)->getValue() ?? '') : null;
        $day1 = $day1Col ? $sheet->getCell($day1Col . $row)->getValue() : null;
        $day2 = $day2Col ? $sheet->getCell($day2Col . $row)->getValue() : null;
        $day3 = $day3Col ? $sheet->getCell($day3Col . $row)->getValue() : null;

        $rowData = [
          'row' => $row,
          'first_name' => $firstName,
          'last_name' => $lastName,
          'email' => $email,
          'mobile_number' => $contact,
          'areas' => $areas,
          'day1' => ($day1 == 1 || $day1 === '1'),
          'day2' => ($day2 == 1 || $day2 === '1'),
          'day3' => ($day3 == 1 || $day3 === '1'),
        ];

        // Check if user exists
        if ($firstName !== 'N/A' && $lastName !== 'N/A') {
          $existingUser = User::whereRaw('LOWER(TRIM(first_name)) = ? AND LOWER(TRIM(last_name)) = ?', [
            mb_strtolower($firstName),
            mb_strtolower($lastName)
          ])->first();

          if ($existingUser) {
            $rowData['existing_user'] = $existingUser;
            $existingUsers[] = $rowData;
          } else {
            $newUsers[] = $rowData;
          }
        } else {
          $newUsers[] = $rowData;
        }

        $dataRows[] = $rowData;
      }

      // Display summary
      $this->info("═══════════════════════════════════════");
      $this->info("IMPORT SUMMARY");
      $this->info("═══════════════════════════════════════");
      $this->info("Total rows: " . count($dataRows));
      $this->info("New users to create: " . count($newUsers));
      $this->info("Existing users (will skip creation, but attach to events): " . count($existingUsers));
      $this->newLine();

      // Show existing users
      if (!empty($existingUsers)) {
        $this->warn("═══════════════════════════════════════");
        $this->warn("EXISTING USERS FOUND");
        $this->warn("═══════════════════════════════════════");
        $this->table(
          ['Row', 'Name', 'Email', 'User ID', 'Day 1', 'Day 2', 'Day 3'],
          array_map(function ($row) {
            return [
              $row['row'],
              $row['first_name'] . ' ' . $row['last_name'],
              $row['existing_user']->email ?? 'N/A',
              $row['existing_user']->id,
              $row['day1'] ? '✓' : '',
              $row['day2'] ? '✓' : '',
              $row['day3'] ? '✓' : '',
            ];
          }, $existingUsers)
        );
        $this->newLine();
      }

      // Show event assignments
      $this->info("EVENT ASSIGNMENTS:");
      if ($day1EventId && isset($events['day1'])) {
        $day1Count = count(array_filter($dataRows, fn($r) => $r['day1']));
        $this->info("Day 1 - Event ID: {$day1EventId} ({$events['day1']->title}) - {$day1Count} attendees");
      }
      if ($day2EventId && isset($events['day2'])) {
        $day2Count = count(array_filter($dataRows, fn($r) => $r['day2']));
        $this->info("Day 2 - Event ID: {$day2EventId} ({$events['day2']->title}) - {$day2Count} attendees");
      }
      if ($day3EventId && isset($events['day3'])) {
        $day3Count = count(array_filter($dataRows, fn($r) => $r['day3']));
        $this->info("Day 3 - Event ID: {$day3EventId} ({$events['day3']->title}) - {$day3Count} attendees");
      }
      $this->newLine();

      if ($dryRun) {
        $this->warn("DRY RUN MODE - No data will be saved");
        $this->info("Run without --dry-run to proceed with import");
        return 0;
      }

      // Confirm before proceeding
      if (!$this->confirm('Do you want to proceed with the import?')) {
        $this->warn("Import cancelled");
        return 0;
      }

      // Perform import
      $created = 0;
      $updated = 0;
      $attachments = ['day1' => 0, 'day2' => 0, 'day3' => 0];

      $this->info("Starting import...");
      $progressBar = $this->output->createProgressBar(count($dataRows));

      foreach ($dataRows as $rowData) {
        $user = null;

        // Check if user exists
        if (isset($rowData['existing_user'])) {
          $user = $rowData['existing_user'];
          $updated++;
        } else {
          // Create new user
          $user = new User();
          $user->first_name = $rowData['first_name'];
          $user->last_name = $rowData['last_name'];
          $user->email = $rowData['email'];
          $user->mobile_number = $rowData['mobile_number'];
          $user->save();
          $created++;
        }

        // Process areas/spheres
        if (!empty($rowData['areas'])) {
          $sphereIds = $this->mapAreasToSpheres($rowData['areas']);
          if (!empty($sphereIds)) {
            $user->spheres()->syncWithoutDetaching($sphereIds);
          }
        }

        // Attach to events based on day attendance
        if ($rowData['day1'] && isset($events['day1'])) {
          $events['day1']->users()->syncWithoutDetaching([$user->id]);
          $attachments['day1']++;
        }
        if ($rowData['day2'] && isset($events['day2'])) {
          $events['day2']->users()->syncWithoutDetaching([$user->id]);
          $attachments['day2']++;
        }
        if ($rowData['day3'] && isset($events['day3'])) {
          $events['day3']->users()->syncWithoutDetaching([$user->id]);
          $attachments['day3']++;
        }

        $progressBar->advance();
      }

      $progressBar->finish();
      $this->newLine(2);

      // Final summary
      $this->info("═══════════════════════════════════════");
      $this->info("IMPORT COMPLETED");
      $this->info("═══════════════════════════════════════");
      $this->info("New users created: {$created}");
      $this->info("Existing users updated: {$updated}");
      if ($attachments['day1'] > 0) {
        $this->info("Day 1 attachments: {$attachments['day1']}");
      }
      if ($attachments['day2'] > 0) {
        $this->info("Day 2 attachments: {$attachments['day2']}");
      }
      if ($attachments['day3'] > 0) {
        $this->info("Day 3 attachments: {$attachments['day3']}");
      }

      return 0;
    } catch (\Exception $e) {
      $this->error("Error reading file: " . $e->getMessage());
      $this->error($e->getTraceAsString());
      return 1;
    }
  }

  /**
   * Map areas from Excel to sphere IDs
   */
  protected function mapAreasToSpheres(string $areas): array
  {
    $sphereIds = [];

    // Split by common separators
    $areaList = preg_split('/[;,|\n]+/i', $areas);

    foreach ($areaList as $area) {
      $area = trim(strtolower($area));

      if (empty($area)) continue;

      // Map to sphere name
      $sphereName = $this->areaMapping[$area] ?? null;

      if ($sphereName) {
        $sphere = Sphere::where('name', $sphereName)->first();
        if ($sphere) {
          $sphereIds[] = $sphere->id;
        }
      }
    }

    return array_unique($sphereIds);
  }
}
