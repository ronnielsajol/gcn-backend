<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AnalyzeAttendanceExcel extends Command
{
    protected $signature = 'excel:analyze-attendance
                            {file : Path to the Excel file}
                            {--attendance-col=F : Column letter for attendance (default: F)}
                            {--header-row=2 : Row number where headers are located}
                            {--start-col=A : Starting column for data}
                            {--end-col=Z : Ending column for data}';

    protected $description = 'Analyze Excel file attendance column and identify discrepancies';

    public function handle()
    {
        $filePath = $this->argument('file');
        $attendanceCol = $this->option('attendance-col');
        $headerRow = (int) $this->option('header-row');
        $startCol = $this->option('start-col');
        $endCol = $this->option('end-col');

        // Resolve file path
        if (!file_exists($filePath)) {
            // Try storage/app path
            $storagePath = storage_path('app/' . ltrim($filePath, '/'));
            if (file_exists($storagePath)) {
                $filePath = $storagePath;
            } else {
                $this->error("File not found: {$filePath}");
                return 1;
            }
        }

        $this->info("Analyzing file: {$filePath}");
        $this->newLine();

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            $this->info("Total rows in file: {$highestRow}");
            $this->info("Header row: {$headerRow}");
            $this->info("Data starts at row: " . ($headerRow + 1));
            $this->newLine();

            // Read all headers
            $headers = [];
            $columnLetters = [];
            for ($col = $startCol; $col <= $endCol; $col++) {
                $cellValue = $sheet->getCell($col . $headerRow)->getValue();
                if ($cellValue) {
                    $headers[$col] = trim($cellValue);
                    $columnLetters[] = $col;
                }
            }

            $this->info("Headers found: " . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)));
            $this->newLine();

            // Analyze attendance column
            $attendanceIndex = null;
            foreach ($headers as $col => $header) {
                if (strtolower(trim($header)) === 'attendance') {
                    $attendanceIndex = $col;
                    break;
                }
            }

            if (!$attendanceIndex) {
                $attendanceIndex = $attendanceCol;
                $this->warn("'Attendance' header not found. Using column {$attendanceCol}");
            } else {
                $this->info("Attendance column detected at: {$attendanceIndex}");
            }
            $this->newLine();

            // Count attendance = 1
            $attendanceOnes = 0;
            $attendanceOther = 0;
            $attendanceEmpty = 0;
            $attendanceDetails = [];

            // Also check for duplicate names
            $names = [];
            $duplicates = [];

            // Track rows with issues
            $emptyNameRows = [];
            $nonOneAttendanceRows = [];
            $rowsWithAttendanceOne = [];

            for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
                $rowData = [];
                foreach ($columnLetters as $col) {
                    $cellValue = $sheet->getCell($col . $row)->getValue();
                    $rowData[$headers[$col]] = $cellValue;
                }

                $attendanceValue = $sheet->getCell($attendanceIndex . $row)->getValue();

                // Get name fields (assuming first two columns are first_name and last_name)
                $firstName = trim($sheet->getCell($columnLetters[0] . $row)->getValue() ?? '');
                $lastName = trim($sheet->getCell($columnLetters[1] . $row)->getValue() ?? '');
                $fullName = trim($firstName . ' ' . $lastName);

                // Check for empty names
                if (empty($firstName) && empty($lastName)) {
                    $emptyNameRows[] = [
                        'row' => $row,
                        'attendance' => $attendanceValue,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ];
                }

                // Track attendance values
                if ($attendanceValue == 1 || $attendanceValue === '1') {
                    $attendanceOnes++;

                    // Store all rows with attendance = 1
                    $rowsWithAttendanceOne[] = [
                        'row' => $row,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'full_name' => $fullName,
                        'has_name' => !empty($firstName) && !empty($lastName),
                        'has_first_name' => !empty($firstName),
                        'has_last_name' => !empty($lastName),
                    ];

                    // Check for duplicates
                    $nameKey = strtolower($fullName);
                    if (isset($names[$nameKey]) && !empty($fullName)) {
                        $duplicates[] = [
                            'name' => $fullName,
                            'first_row' => $names[$nameKey],
                            'duplicate_row' => $row,
                        ];
                    } else {
                        if (!empty($fullName)) {
                            $names[$nameKey] = $row;
                        }
                    }
                } elseif (empty($attendanceValue) || $attendanceValue === null) {
                    $attendanceEmpty++;
                    if (!empty($fullName)) {
                        $nonOneAttendanceRows[] = [
                            'row' => $row,
                            'name' => $fullName,
                            'attendance' => 'empty',
                        ];
                    }
                } else {
                    $attendanceOther++;
                    if (!empty($fullName)) {
                        $nonOneAttendanceRows[] = [
                            'row' => $row,
                            'name' => $fullName,
                            'attendance' => $attendanceValue,
                        ];
                    }
                }
            }

            // Display results
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Rows with Attendance = 1', $attendanceOnes],
                    ['Rows with Attendance = other value', $attendanceOther],
                    ['Rows with empty Attendance', $attendanceEmpty],
                    ['Total data rows', $highestRow - $headerRow],
                ]
            );
            $this->newLine();

            // Show empty name rows
            if (!empty($emptyNameRows)) {
                $this->warn("Found " . count($emptyNameRows) . " rows with empty names:");
                $this->table(
                    ['Row Number', 'Attendance Value'],
                    array_map(fn($item) => [$item['row'], $item['attendance'] ?? 'empty'], $emptyNameRows)
                );
                $this->newLine();
            }

            // Show rows with attendance = 1 but missing names (partial or complete)
            $missingNameRows = array_filter($rowsWithAttendanceOne, fn($r) => !$r['has_name']);
            if (!empty($missingNameRows)) {
                $this->error("═══════════════════════════════════════");
                $this->error("⚠ ROWS WITH ATTENDANCE = 1 BUT MISSING NAMES");
                $this->error("═══════════════════════════════════════");
                $this->warn("Found " . count($missingNameRows) . " rows with attendance = 1 but incomplete names:");
                $this->warn("These rows will be SKIPPED during import!\n");
                $this->table(
                    ['Row #', 'First Name', 'Last Name', 'Issue'],
                    array_map(function ($item) {
                        $issue = '';
                        if (empty($item['first_name']) && empty($item['last_name'])) {
                            $issue = 'Both names missing';
                        } elseif (empty($item['first_name'])) {
                            $issue = 'First name missing';
                        } elseif (empty($item['last_name'])) {
                            $issue = 'Last name missing';
                        }
                        return [
                            $item['row'],
                            $item['first_name'] ?: '(empty)',
                            $item['last_name'] ?: '(empty)',
                            $issue
                        ];
                    }, $missingNameRows)
                );
                $this->newLine();
            }

            // Show duplicate names
            if (!empty($duplicates)) {
                $this->error("═══════════════════════════════════════");
                $this->error("⚠ DUPLICATE NAMES WITH ATTENDANCE = 1");
                $this->error("═══════════════════════════════════════");
                $this->warn("Found " . count($duplicates) . " duplicate names:");
                $this->warn("Only the FIRST occurrence will be imported, duplicates will be SKIPPED!\n");
                $this->table(
                    ['Name', 'First Occurrence (Row)', 'Duplicate (Row)', 'Status'],
                    array_map(fn($item) => [
                        $item['name'],
                        $item['first_row'],
                        $item['duplicate_row'],
                        'Duplicate skipped'
                    ], $duplicates)
                );
                $this->newLine();
            }

            // Show non-1 attendance rows with names
            if (!empty($nonOneAttendanceRows) && count($nonOneAttendanceRows) <= 20) {
                $this->warn("Rows with attendance ≠ 1 (but have names):");
                $this->table(
                    ['Row Number', 'Name', 'Attendance Value'],
                    array_map(fn($item) => [$item['row'], $item['name'], $item['attendance']], $nonOneAttendanceRows)
                );
                $this->newLine();
            } elseif (!empty($nonOneAttendanceRows)) {
                $this->warn("Found " . count($nonOneAttendanceRows) . " rows with attendance ≠ 1 (showing first 20):");
                $this->table(
                    ['Row Number', 'Name', 'Attendance Value'],
                    array_map(fn($item) => [$item['row'], $item['name'], $item['attendance']], array_slice($nonOneAttendanceRows, 0, 20))
                );
                $this->newLine();
            }

            // Calculate expected import count
            $emptyNamesWithAttendanceOne = count(array_filter($emptyNameRows, fn($item) => $item['attendance'] == 1 || $item['attendance'] === '1'));
            $expectedImports = $attendanceOnes - count($duplicates) - $emptyNamesWithAttendanceOne;

            // Count valid importable rows (attendance = 1, has name, not duplicate)
            $validImportableRows = array_filter($rowsWithAttendanceOne, function ($item) use ($duplicates) {
                if (!$item['has_name']) return false;

                // Check if this row is a duplicate
                foreach ($duplicates as $dup) {
                    if ($dup['duplicate_row'] === $item['row']) {
                        return false;
                    }
                }
                return true;
            });

            $this->info("═══════════════════════════════════════");
            $this->info("ANALYSIS SUMMARY:");
            $this->info("═══════════════════════════════════════");
            $this->info("Excel attendance count (1's): {$attendanceOnes}");
            $this->info("Rows with valid names: " . count(array_filter($rowsWithAttendanceOne, fn($r) => $r['has_name'])));
            $this->info("Rows with empty names: {$emptyNamesWithAttendanceOne}");
            $this->info("Duplicate names (skipped): " . count($duplicates));
            $this->info("Valid importable rows: " . count($validImportableRows));
            $this->newLine();

            $discrepancy = 382 - 374;
            $actualDiscrepancy = $attendanceOnes - 374;
            $this->warn("You reported: Excel has 382, Website has 374 (difference: {$discrepancy})");
            $this->warn("Actual Excel count: {$attendanceOnes}, Website: 374 (difference: {$actualDiscrepancy})");
            $this->newLine();

            if (count($validImportableRows) == 374) {
                $this->info("✓ The website count (374) matches the valid importable rows!");
                $this->info("  This means the import worked correctly.");
            } elseif ($expectedImports == 374) {
                $this->info("✓ The website count (374) matches the expected import count!");
            } else {
                $possibleIssues = [];

                if (count($duplicates) > 0) {
                    $possibleIssues[] = count($duplicates) . " duplicate names";
                }
                if ($emptyNamesWithAttendanceOne > 0) {
                    $possibleIssues[] = $emptyNamesWithAttendanceOne . " empty names";
                }

                $accountedFor = count($duplicates) + $emptyNamesWithAttendanceOne;
                $unaccountedFor = $actualDiscrepancy - $accountedFor;

                if ($unaccountedFor > 0) {
                    $this->warn("⚠ Still {$unaccountedFor} records unaccounted for!");
                    $this->warn("  Possible reasons:");
                    $this->warn("  • Import may have been run multiple times with duplicates");
                    $this->warn("  • Some rows might have failed validation");
                    $this->warn("  • The 374 count on website might be from a different query");
                    $this->warn("  • Check if website is counting event_user attachments vs User records");
                }

                if (!empty($possibleIssues)) {
                    $this->info("  Issues found: " . implode(", ", $possibleIssues));
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("Error reading file: " . $e->getMessage());
            return 1;
        }
    }
}
