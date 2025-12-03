<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CompareAttendance extends Command
{
    protected $signature = 'users:compare-attendance
                            {path : Excel filename or path}
                            {--sheet= : Sheet name (default: first sheet)}
                            {--header-row=1 : Row number where headers are located}
                            {--start-row=2 : Start checking from this row}
                            {--last-name-col=A : Column letter for last name}
                            {--first-name-col=B : Column letter for first name}
                            {--attendance-col=Q : Column letter for attendance}
                            {--event-id= : Filter by specific event ID}';

    protected $description = 'Compare attendance counts between Excel and database';

    public function handle(): int
    {
        $fileRelative = $this->argument('path');
        $sheetName = $this->option('sheet');
        $headerRow = (int)$this->option('header-row');
        $startRow = (int)$this->option('start-row');
        $lastNameCol = strtoupper($this->option('last-name-col'));
        $firstNameCol = strtoupper($this->option('first-name-col'));
        $attendanceCol = strtoupper($this->option('attendance-col'));
        $eventId = $this->option('event-id');

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
                $this->error("Sheet '{$sheetName}' not found.");
                return self::FAILURE;
            }
        } else {
            $sheet = $spreadsheet->getActiveSheet();
            $sheetName = $sheet->getTitle();
        }

        $this->info("Using sheet: {$sheetName}");
        $this->info("Columns: Last Name = {$lastNameCol}, First Name = {$firstNameCol}, Attendance = {$attendanceCol}");
        $this->newLine();

        $lastRow = $sheet->getHighestRow();
        $excelUsers = [];
        $excelAttendanceCount = 0;
        $duplicates = [];

        // Read Excel data
        $this->info("Reading Excel data...");
        for ($row = $startRow; $row <= $lastRow; $row++) {
            $lastName = trim((string)$sheet->getCell("{$lastNameCol}{$row}")->getCalculatedValue());
            $firstName = trim((string)$sheet->getCell("{$firstNameCol}{$row}")->getCalculatedValue());
            $attendance = trim((string)$sheet->getCell("{$attendanceCol}{$row}")->getCalculatedValue());

            // Skip empty rows
            if (empty($lastName) && empty($firstName)) {
                continue;
            }

            // Skip N/A rows
            if (strtoupper($lastName) === 'N/A' && strtoupper($firstName) === 'N/A') {
                continue;
            }

            $hasAttendance = in_array($attendance, ['1', 1, true, 'true', 'TRUE', 'yes', 'YES'], true);

            if ($hasAttendance) {
                $excelAttendanceCount++;
            }

            $key = mb_strtolower(trim("{$firstName}|{$lastName}"));

            // Check for duplicates
            if (isset($excelUsers[$key])) {
                // This is a duplicate
                if (!isset($duplicates[$key])) {
                    $duplicates[$key] = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'rows' => [$excelUsers[$key]['row']],
                        'attendance_values' => [$excelUsers[$key]['attendance']],
                    ];
                }
                $duplicates[$key]['rows'][] = $row;
                $duplicates[$key]['attendance_values'][] = $hasAttendance ? 1 : 0;
            }

            $excelUsers[$key] = [
                'row' => $row,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'attendance' => $hasAttendance ? 1 : 0,
            ];
        }

        // Get database users
        $this->info("Querying database...");
        $query = User::where('role', 'user');

        if ($eventId) {
            $query->whereHas('events', function ($q) use ($eventId) {
                $q->where('events.id', $eventId);
            });
        }

        $dbUsers = $query->get(['id', 'first_name', 'last_name', 'attendance']);
        $dbAttendanceCount = $dbUsers->where('attendance', 1)->count();

        // Create DB lookup
        $dbUserMap = [];
        foreach ($dbUsers as $user) {
            $key = mb_strtolower(trim("{$user->first_name}|{$user->last_name}"));
            $dbUserMap[$key] = [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'attendance' => $user->attendance ?? 0,
            ];
        }

        // Compare
        $this->newLine();
        $this->info("═══════════════════════════════════════");
        $this->info("ATTENDANCE COMPARISON");
        $this->info("═══════════════════════════════════════");
        $this->info("Excel attendance count: {$excelAttendanceCount}");
        $this->info("Database attendance count: {$dbAttendanceCount}");
        $diff = $excelAttendanceCount - $dbAttendanceCount;

        if ($diff > 0) {
            $this->error("Difference: -{$diff} (Database has {$diff} fewer)");
        } elseif ($diff < 0) {
            $this->warn("Difference: +" . abs($diff) . " (Database has " . abs($diff) . " more)");
        } else {
            $this->info("Difference: 0 (Perfect match!)");
        }
        $this->newLine();

        // Find discrepancies
        $inExcelNotInDb = [];
        $attendanceMismatch = [];
        $inDbNotInExcel = [];

        // Check Excel users
        foreach ($excelUsers as $key => $excelUser) {
            if (!isset($dbUserMap[$key])) {
                if ($excelUser['attendance'] == 1) {
                    $inExcelNotInDb[] = $excelUser;
                }
            } else {
                $dbUser = $dbUserMap[$key];
                if ($excelUser['attendance'] != $dbUser['attendance']) {
                    $attendanceMismatch[] = [
                        'row' => $excelUser['row'],
                        'first_name' => $excelUser['first_name'],
                        'last_name' => $excelUser['last_name'],
                        'excel_attendance' => $excelUser['attendance'],
                        'db_attendance' => $dbUser['attendance'],
                        'db_id' => $dbUser['id'],
                    ];
                }
            }
        }

        // Check DB users not in Excel
        foreach ($dbUserMap as $key => $dbUser) {
            if (!isset($excelUsers[$key]) && $dbUser['attendance'] == 1) {
                $inDbNotInExcel[] = $dbUser;
            }
        }

        // Display results
        if (!empty($duplicates)) {
            $this->error("═══════════════════════════════════════");
            $this->error("DUPLICATE NAMES IN EXCEL");
            $this->error("═══════════════════════════════════════");
            $this->error("Count: " . count($duplicates));
            $this->warn("These users appear multiple times in the Excel sheet!");
            $this->warn("This causes the attendance count mismatch.\n");

            $duplicateTable = [];
            foreach ($duplicates as $dup) {
                $duplicateTable[] = [
                    implode(', ', $dup['rows']),
                    $dup['first_name'],
                    $dup['last_name'],
                    implode(', ', $dup['attendance_values']),
                    count($dup['rows']) + 1, // +1 because we track additional duplicates
                ];
            }

            $this->table(
                ['Rows', 'First Name', 'Last Name', 'Attendance Values', 'Times Appears'],
                $duplicateTable
            );
            $this->newLine();
        }

        if (!empty($inExcelNotInDb)) {
            $this->error("═══════════════════════════════════════");
            $this->error("IN EXCEL (attendance=1) BUT NOT IN DATABASE");
            $this->error("═══════════════════════════════════════");
            $this->error("Count: " . count($inExcelNotInDb));
            $this->table(
                ['Row', 'First Name', 'Last Name', 'Excel Attendance'],
                array_map(fn($u) => [$u['row'], $u['first_name'], $u['last_name'], $u['attendance']], $inExcelNotInDb)
            );
            $this->newLine();
        }

        if (!empty($attendanceMismatch)) {
            $this->warn("═══════════════════════════════════════");
            $this->warn("ATTENDANCE MISMATCH (User exists, different attendance)");
            $this->warn("═══════════════════════════════════════");
            $this->warn("Count: " . count($attendanceMismatch));
            $this->table(
                ['Row', 'First Name', 'Last Name', 'Excel', 'DB', 'User ID'],
                array_map(fn($u) => [
                    $u['row'],
                    $u['first_name'],
                    $u['last_name'],
                    $u['excel_attendance'],
                    $u['db_attendance'],
                    $u['db_id']
                ], $attendanceMismatch)
            );
            $this->newLine();
        }

        if (!empty($inDbNotInExcel)) {
            $this->warn("═══════════════════════════════════════");
            $this->warn("IN DATABASE (attendance=1) BUT NOT IN EXCEL");
            $this->warn("═══════════════════════════════════════");
            $this->warn("Count: " . count($inDbNotInExcel));
            $this->table(
                ['User ID', 'First Name', 'Last Name', 'DB Attendance'],
                array_map(fn($u) => [$u['id'], $u['first_name'], $u['last_name'], $u['attendance']], $inDbNotInExcel)
            );
            $this->newLine();
        }

        if (empty($inExcelNotInDb) && empty($attendanceMismatch) && empty($inDbNotInExcel)) {
            $this->info("✓ All users match perfectly!");
        }

        // Summary
        $this->info("═══════════════════════════════════════");
        $this->info("SUMMARY");
        $this->info("═══════════════════════════════════════");
        $this->info("Excel rows processed: " . count($excelUsers));
        $this->info("Database users: " . count($dbUserMap));
        $this->info("In Excel not in DB (att=1): " . count($inExcelNotInDb));
        $this->info("Attendance mismatches: " . count($attendanceMismatch));
        $this->info("In DB not in Excel (att=1): " . count($inDbNotInExcel));

        return self::SUCCESS;
    }
}
