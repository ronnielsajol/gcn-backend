<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Group;
use App\Models\Sphere;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportRegistrations extends Command
{
    protected $signature = 'import:registrations
        {path? : Excel filename or path (can be relative to storage/app/imports/ or full path)}
        {--sheet=FINAL CLARK REGISTRATION_2024 : Sheet name (trailing space tolerated)}
        {--header-row=2 : Row number where headers are located (default: 2)}
        {--start-col=A : Starting column letter for headers/data (default: A)}
        {--end-col=Z : Ending column letter for headers/data (default: Z)}
        {--start-row= : Start importing at this 1-based sheet row (data rows start at 3)}
        {--resume : Continue from the last imported row in users for this sheet}
        {--skip-existing : Skip rows already imported for this sheet (by source_row)}
        {--event-id= : Event ID to attach imported registrants as attendees}
        {--interactive-map : Interactively map Excel columns to database fields}
        {--dry-run : Preview what would be imported without actually saving to database}';

    protected $description = 'Insert every row from the Excel into users; store Google Drive link only (no downloads).';

    /**
     * Map uses **normalized** header keys (lowercase, trimmed, spaces/newlines collapsed).
     * We normalize actual sheet headers the same way before lookup.
     */
    private array $map = [
        'email address' => 'email',
        'title' => 'title',
        'last name' => 'last_name',
        'first name' => 'first_name',
        'middle initial' => 'middle_initial',
        'mobile number' => 'mobile_number',
        'home address (city/town/province [e.g. taguig city])' => 'home_address',
        'name of church where you attend' => 'church_name',
        'church address (city/town/province [e.g. taguig city])' => 'church_address',
        'working or student' => 'working_or_student',
        'vocation/work sphere' => 'spheres_raw', // multi-select cell
        'mode of payment' => 'mode_of_payment',
        'proof of payment (please upload a clear photo of your deposit slip)' => 'proof_of_payment_url',
        'notes' => 'notes',
        'group' => 'group_name',
        'reference number' => 'reference_number',
        'reconciled' => 'reconciled',
        'victory pampanga finance ms. abbey' => 'finance_checked',   // newline flattened
        'email confrimation tn secretariat' => 'email_confirmed',    // sheet typo preserved after normalization
        'attendance' => 'attendance',
        'id' => 'id_issued',
        'book' => 'book_given',
    ];

    public function handle(): int
    {
        $fileRelative  = $this->argument('path') ?? '3.0_REG FILE_May 25, 2024_CLARK.xlsx';
        $wantedSheet   = $this->option('sheet');
        $eventId       = $this->option('event-id');

        // Validate event if event-id is provided
        $event = null;
        if ($eventId) {
            $event = Event::find($eventId);
            if (!$event) {
                $this->error("Event with ID {$eventId} not found.");
                return self::FAILURE;
            }
            $this->info("Registrants will be attached to event: {$event->name} (ID: {$event->id})");
        }

        // Handle both relative and absolute paths
        // If path starts with 'storage/app/' or contains full path, use it directly
        // Otherwise, prepend 'app/imports/'
        if (str_starts_with($fileRelative, 'storage/app/')) {
            // Remove 'storage/app/' prefix since storage_path() adds it
            $fileRelative = substr($fileRelative, strlen('storage/app/'));
            $filePath = storage_path("app/{$fileRelative}");
        } elseif (str_starts_with($fileRelative, 'app/')) {
            // Already has 'app/' prefix
            $filePath = storage_path($fileRelative);
        } elseif (file_exists($fileRelative)) {
            // Absolute path provided and exists
            $filePath = $fileRelative;
        } else {
            // Relative path, assume it's in imports directory
            $filePath = storage_path("app/imports/{$fileRelative}");
        }

        if (!file_exists($filePath)) {
            $this->error("Excel not found: {$filePath}");
            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($filePath);
        $realName = $this->resolveSheetName($spreadsheet->getSheetNames(), $wantedSheet);
        if (!$realName) {
            $this->warn("Sheet '{$wantedSheet}' not found. Available:");
            foreach ($spreadsheet->getSheetNames() as $n) $this->line("- {$n}");
            return self::FAILURE;
        }
        $sheet = $spreadsheet->getSheetByName($realName);

        // headers in B2:Y2 by default, data starts at row 3 (or headerRow + 1)
        $headerRow    = (int)($this->option('header-row') ?? 2);
        $startCol     = strtoupper($this->option('start-col') ?? 'A');
        $endCol       = strtoupper($this->option('end-col') ?? 'Z');
        $firstDataRow = $headerRow + 1;

        $this->info("Using header row: {$headerRow}, columns: {$startCol} to {$endCol}, data starts at row: {$firstDataRow}");

        // Start controls
        $requestedStart = $this->option('start-row') ? (int)$this->option('start-row') : null;
        $startRow = $requestedStart && $requestedStart > $firstDataRow ? $requestedStart : $firstDataRow;

        if ($this->option('resume')) {
            $last = User::where('source_sheet', $realName)->max('source_row');
            if ($last && $last + 1 > $startRow) $startRow = $last + 1;
        }

        $skipExisting = (bool)$this->option('skip-existing');
        $existingRows = [];
        if ($skipExisting) {
            User::where('source_sheet', $realName)
                ->whereNotNull('source_row')
                ->orderBy('source_row')
                ->chunk(1000, function ($chunk) use (&$existingRows) {
                    foreach ($chunk as $u) $existingRows[(int)$u->source_row] = true;
                });
        }

        $this->info("Starting at row {$startRow} (data rows begin at {$firstDataRow})");

        // Collect headers & build normalized index map
        $rawHeaders = [];
        for ($col = $startCol; $col !== $this->nextCol($endCol); $col = $this->nextCol($col)) {
            $rawHeaders[] = (string)$sheet->getCell("{$col}{$headerRow}")->getCalculatedValue();
        }

        // normalized header -> column letter
        $nidx = [];
        for ($i = 0, $col = $startCol; $col !== $this->nextCol($endCol); $col = $this->nextCol($col), $i++) {
            $norm = $this->normHeader($rawHeaders[$i] ?? '');
            if ($norm !== '') $nidx[$norm] = $col;
        }

        // Interactive mapping if requested
        if ($this->option('interactive-map')) {
            $this->map = $this->interactiveMapping($rawHeaders, $nidx);
            $this->info("\n✓ Custom mapping applied\n");
        }

        // Show mapping preview and confirm before proceeding
        $this->warn("\n=== COLUMN MAPPING PREVIEW ===");
        $mappedCount = 0;
        $unmappedCount = 0;

        foreach ($rawHeaders as $i => $header) {
            if (trim($header) === '') continue;
            $norm = $this->normHeader($header);
            if ($norm === '') continue;

            if (isset($this->map[$norm])) {
                $this->info("  ✓ '{$header}' → {$this->map[$norm]}");
                $mappedCount++;
            } else {
                $this->line("  ✗ '{$header}' → (not mapped, will be skipped)");
                $unmappedCount++;
            }
        }

        $this->line("\nSummary: {$mappedCount} columns mapped, {$unmappedCount} columns will be skipped");

        if (!$this->option('dry-run')) {
            $confirm = $this->confirm("\nProceed with import using this mapping?", true);
            if (!$confirm) {
                $this->warn("Import cancelled by user");
                return self::SUCCESS;
            }
        }

        $lastRow = $sheet->getHighestRow();
        $inserted = 0;
        $skippedEmpty = 0;
        $skippedExistingCount = 0;
        $skippedDuplicateName = 0;
        $attachedToEvent = 0;
        $updated = 0;
        $skippedUnchanged = 0;
        $failed = 0;
        $failSamples = [];
        $dryRun = (bool)$this->option('dry-run');

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No data will be saved to database");
            $this->warn("\n=== EXCEL HEADERS FOUND ===");
            $this->line("Raw headers from Excel:");
            foreach ($rawHeaders as $i => $header) {
                $norm = $this->normHeader($header);
                $matched = isset($this->map[$norm]) ? "✓ MAPPED to: {$this->map[$norm]}" : "✗ NOT MAPPED";
                $this->line("  [{$i}] '{$header}' → normalized: '{$norm}' → {$matched}");
            }
            $this->warn("\n=== EXPECTED HEADERS (from map) ===");
            foreach ($this->map as $expectedNorm => $field) {
                $found = isset($nidx[$expectedNorm]) ? "✓ FOUND at column {$nidx[$expectedNorm]}" : "✗ MISSING";
                $this->line("  '{$expectedNorm}' ({$field}) → {$found}");
            }
            $this->line("");
        }

        $this->info("Processing rows {$startRow}..{$lastRow} from '{$realName}'");

        for ($row = $startRow; $row <= $lastRow; $row++) {
            try {
                if ($this->rowEmpty($sheet, $row, $startCol, $endCol)) {
                    $skippedEmpty++;
                    continue;
                }
                if ($skipExisting && isset($existingRows[$row])) {
                    $skippedExistingCount++;
                    continue;
                }

                // Build payload using normalized headers
                $payload = [];
                foreach ($this->map as $normHeader => $field) {
                    if (!isset($nidx[$normHeader])) continue; // header not present
                    $val = $sheet->getCell($nidx[$normHeader] . $row)->getCalculatedValue();
                    $payload[$field] = is_string($val) ? trim($val) : $val;
                }

                // Normalize boolean flags
                foreach (['reconciled', 'finance_checked', 'email_confirmed', 'attendance', 'id_issued', 'book_given'] as $flag) {
                    if (array_key_exists($flag, $payload)) {
                        $payload[$flag] = $this->toBool($payload[$flag]);
                    }
                }

                // ----- Build sphere labels (either from one multi-select cell OR checkbox columns)
                $sphereLabels = [];

                // Case 1: multi-select cell
                if (!empty($payload['spheres_raw'])) {
                    // Split by semicolon, comma, pipe, newline, or " or "
                    $sphereLabels = preg_split('/[;,|\n]+|\s+or\s+/i', (string)$payload['spheres_raw']);
                    $sphereLabels = array_values(array_filter(array_map('trim', $sphereLabels)));
                }

                // Case 2: checkbox columns named like "Vocation/Work Sphere - Business/Economics", etc.
                if (empty($sphereLabels)) {
                    foreach ($nidx as $normHeader => $colLetter) {
                        if (str_starts_with($normHeader, 'vocation/work sphere')) {
                            $cellVal = $sheet->getCell($colLetter . $row)->getCalculatedValue();
                            $checked = in_array(mb_strtolower(trim((string)$cellVal)), ['1', 'y', 'yes', 'true', 't', 'checked', 'x', 'present'], true);
                            if ($checked) {
                                // Everything after the prefix and a separator becomes the label
                                $label = trim(preg_replace('/^vocation\/work sphere\s*[-:–]\s*/', '', $normHeader));
                                if ($label !== '') $sphereLabels[] = $label;
                            }
                        }
                    }
                }

                // Deduplicate labels (preserve order)
                $sphereLabels = array_values(array_unique($sphereLabels));

                // Find sphere IDs from labels (do this before duplicate check so we can compare)
                $sphereIds = [];
                if (!empty($sphereLabels)) {
                    foreach ($sphereLabels as $label) {
                        $slug = Str::slug($label, '-');
                        $normLabel = preg_replace('/\s+/', ' ', mb_strtolower($label));
                        $sphere = Sphere::where('slug', $slug)->first();
                        if (!$sphere) {
                            $sphere = Sphere::whereRaw('LOWER(name) = ?', [$normLabel])->first();
                        }
                        if ($sphere) {
                            $sphereIds[] = $sphere->id;
                        }
                    }
                }

                // Check if user with same first name + last name already exists
                $firstName = trim((string)($payload['first_name'] ?? ''));
                $lastName = trim((string)($payload['last_name'] ?? ''));

                // Set to "N/A" if either name is missing
                if (empty($firstName)) {
                    $firstName = 'N/A';
                    $payload['first_name'] = 'N/A';
                }
                if (empty($lastName)) {
                    $lastName = 'N/A';
                    $payload['last_name'] = 'N/A';
                }

                $existingUser = null;
                // Only check for duplicates if both names are not N/A
                if ($firstName !== 'N/A' && $lastName !== 'N/A') {
                    $existingUser = User::whereRaw('LOWER(TRIM(first_name)) = ? AND LOWER(TRIM(last_name)) = ?', [
                        mb_strtolower($firstName),
                        mb_strtolower($lastName)
                    ])->first();

                    if ($existingUser) {
                        // Compare key fields to detect changes
                        $hasChanges = false;
                        $changes = [];

                        // Compare fields (skip null/empty comparisons)
                        $fieldsToCompare = [
                            'email',
                            'title',
                            'middle_initial',
                            'mobile_number',
                            'home_address',
                            'church_name',
                            'church_address',
                            'mode_of_payment',
                            'proof_of_payment_url',
                            'notes',
                            'reference_number'
                        ];

                        foreach ($fieldsToCompare as $field) {
                            $newValue = isset($payload[$field]) ? trim((string)$payload[$field]) : '';
                            $oldValue = isset($existingUser->$field) ? trim((string)$existingUser->$field) : '';

                            // Only consider it a change if new value is not empty and differs from old
                            if ($newValue !== '' && $newValue !== $oldValue) {
                                $hasChanges = true;
                                $changes[] = $field;
                            }
                        }

                        // Compare normalized enums
                        $newWorkingStudent = $this->normalizeWorkingStudent($payload['working_or_student'] ?? null);
                        if ($newWorkingStudent && $newWorkingStudent !== $existingUser->working_or_student) {
                            $hasChanges = true;
                            $changes[] = 'working_or_student';
                        }

                        $newModeOfPayment = $this->normalizeModeOfPayment($payload['mode_of_payment'] ?? null);
                        if ($newModeOfPayment && $newModeOfPayment !== $existingUser->mode_of_payment) {
                            $hasChanges = true;
                            $changes[] = 'mode_of_payment';
                        }

                        // Compare boolean flags
                        foreach (['reconciled', 'finance_checked', 'email_confirmed', 'attendance', 'id_issued', 'book_given'] as $flag) {
                            $newFlag = (bool)($payload[$flag] ?? false);
                            $oldFlag = (bool)$existingUser->$flag;
                            if ($newFlag !== $oldFlag) {
                                $hasChanges = true;
                                $changes[] = $flag;
                            }
                        }

                        // Compare spheres
                        $existingSphereIds = $existingUser->spheres->pluck('id')->toArray();
                        sort($existingSphereIds);
                        $newSphereIds = $sphereIds;
                        sort($newSphereIds);
                        if ($existingSphereIds !== $newSphereIds) {
                            $hasChanges = true;
                            $changes[] = 'spheres';
                        }

                        if ($hasChanges) {
                            // Update the existing user
                            if (!$dryRun) {
                                $existingUser->email = $payload['email'] ?? $existingUser->email;
                                $existingUser->title = $payload['title'] ?? $existingUser->title;
                                $existingUser->middle_initial = $payload['middle_initial'] ?? $existingUser->middle_initial;
                                $existingUser->mobile_number = $payload['mobile_number'] ?? $existingUser->mobile_number;
                                $existingUser->home_address = $payload['home_address'] ?? $existingUser->home_address;
                                $existingUser->church_name = $payload['church_name'] ?? $existingUser->church_name;
                                $existingUser->church_address = $payload['church_address'] ?? $existingUser->church_address;
                                if ($newWorkingStudent) $existingUser->working_or_student = $newWorkingStudent;
                                if ($newModeOfPayment) $existingUser->mode_of_payment = $newModeOfPayment;
                                $existingUser->proof_of_payment_url = $payload['proof_of_payment_url'] ?? $existingUser->proof_of_payment_url;
                                $existingUser->notes = $payload['notes'] ?? $existingUser->notes;
                                $existingUser->reference_number = $payload['reference_number'] ?? $existingUser->reference_number;
                                $existingUser->reconciled = (bool)($payload['reconciled'] ?? $existingUser->reconciled);
                                $existingUser->finance_checked = (bool)($payload['finance_checked'] ?? $existingUser->finance_checked);
                                $existingUser->email_confirmed = (bool)($payload['email_confirmed'] ?? $existingUser->email_confirmed);
                                $existingUser->attendance = (bool)($payload['attendance'] ?? $existingUser->attendance);
                                $existingUser->id_issued = (bool)($payload['id_issued'] ?? $existingUser->id_issued);
                                $existingUser->book_given = (bool)($payload['book_given'] ?? $existingUser->book_given);

                                // Update group if provided
                                if (!empty($payload['group_name'])) {
                                    $group = Group::firstOrCreate(
                                        ['name' => trim((string)$payload['group_name'])],
                                        ['type' => null, 'description' => null]
                                    );
                                    $existingUser->group_id = $group->id;
                                }

                                $existingUser->save();

                                // Update spheres
                                if (!empty($sphereIds)) {
                                    $existingUser->spheres()->sync($sphereIds);
                                }
                            }

                            $updated++;
                            $this->info("Row {$row}: Updated {$firstName} {$lastName} (ID: {$existingUser->id}) - changed: " . implode(', ', $changes));
                        } else {
                            // No changes detected
                            $skippedUnchanged++;
                            if ($skippedUnchanged <= 5 || $skippedUnchanged % 50 === 0) {
                                $this->line("Row {$row}: Skipped unchanged - {$firstName} {$lastName} (ID: {$existingUser->id})");
                            }
                        }

                        // Still attach to event if event-id is provided
                        if ($event) {
                            $wasAlreadyAttached = $event->users()->where('users.id', $existingUser->id)->exists();
                            if (!$wasAlreadyAttached) {
                                if (!$dryRun) {
                                    $event->users()->syncWithoutDetaching([$existingUser->id]);
                                }
                                $attachedToEvent++;
                                $this->info("Row {$row}: Attached {$firstName} {$lastName} (ID: {$existingUser->id}) to event");
                            }
                        }

                        continue;
                    }
                }

                // Group (optional)
                $groupId = null;
                if (!empty($payload['group_name'])) {
                    $group = Group::firstOrCreate(
                        ['name' => trim((string)$payload['group_name'])],
                        ['type' => null, 'description' => null]
                    );
                    $groupId = $group->id;
                }

                // Create the user (blind insert)
                $user = new User();
                $user->email                  = $payload['email'] ?? null;
                $user->title                  = $payload['title'] ?? null;
                $user->last_name              = $payload['last_name'] ?? null;
                $user->first_name             = $payload['first_name'] ?? null;
                $user->middle_initial         = $payload['middle_initial'] ?? null;
                $user->mobile_number          = $payload['mobile_number'] ?? null;
                $user->home_address           = $payload['home_address'] ?? null;
                $user->church_name            = $payload['church_name'] ?? null;
                $user->church_address         = $payload['church_address'] ?? null;
                $user->working_or_student     = $this->normalizeWorkingStudent($payload['working_or_student'] ?? null);
                $user->mode_of_payment        = $this->normalizeModeOfPayment($payload['mode_of_payment'] ?? null);
                $user->proof_of_payment_url   = $payload['proof_of_payment_url'] ?? null; // store link only
                $user->notes                  = $payload['notes'] ?? null;
                $user->group_id               = $groupId;
                $user->reference_number       = $payload['reference_number'] ?? null;     // <— now robustly mapped
                $user->reconciled             = (bool)($payload['reconciled'] ?? false);
                $user->finance_checked        = (bool)($payload['finance_checked'] ?? false);
                $user->email_confirmed        = (bool)($payload['email_confirmed'] ?? false);
                $user->attendance             = (bool)($payload['attendance'] ?? false);
                $user->id_issued              = (bool)($payload['id_issued'] ?? false);
                $user->book_given             = (bool)($payload['book_given'] ?? false);

                // Store sphere IDs as comma-separated (already computed above)
                $user->vocation_work_sphere = !empty($sphereIds) ? implode(', ', $sphereIds) : null;

                // Dry run preview
                if ($dryRun) {
                    if ($inserted < 5) { // Show first 5 records as preview
                        $this->line("\n--- Row {$row} Preview ---");
                        $this->line("Name: {$user->first_name} {$user->last_name}");
                        $this->line("Email: {$user->email}");
                        $this->line("Mobile: {$user->mobile_number}");
                        $this->line("Church: {$user->church_name}");
                        $this->line("Group: " . ($groupId ? $payload['group_name'] : 'None'));
                        $this->line("Spheres: " . (!empty($sphereLabels) ? implode(', ', $sphereLabels) : 'None'));
                        if ($event) {
                            $this->line("Will attach to event: {$event->name}");
                        }
                    }
                    $inserted++;
                    continue; // Skip actual save in dry-run mode
                }

                $user->save();

                // Attach sphere IDs through pivot
                if (!empty($sphereIds)) {
                    $user->spheres()->syncWithoutDetaching($sphereIds);
                }

                // Attach user to event if event-id was provided
                if ($event) {
                    $event->users()->syncWithoutDetaching([$user->id]);
                    $attachedToEvent++;
                }

                $inserted++;
            } catch (\Throwable $e) {
                $failed++;
                if ($failed <= 10) {
                    $this->warn("Row {$row} error: " . $e->getMessage());
                }
            }
        }

        if ($dryRun) {
            $this->warn("\nDRY RUN COMPLETE - No data was saved");
            $this->info("Would have inserted: {$inserted} users");
            $this->info("Would have updated: {$updated} users");
            $this->info("Would have skipped (unchanged): {$skippedUnchanged} users");
            if ($event) {
                $this->info("Would have attached {$attachedToEvent} users to event: {$event->name}");
            }
        } else {
            $this->info("\nDone. Summary:");
            $this->info("  Inserted (new): {$inserted}");
            $this->info("  Updated (changed): {$updated}");
            $this->info("  Skipped (unchanged): {$skippedUnchanged}");
            $this->info("  Skipped (blank rows): {$skippedEmpty}");
            $this->info("  Skipped (existing rows by number): {$skippedExistingCount}");
            $this->info("  Failed: {$failed}");

            if ($event) {
                $this->info("  Attached to event '{$event->name}': {$attachedToEvent} users");
            }
        }

        return self::SUCCESS;
    }

    /* ===== Helpers ===== */

    private function resolveSheetName(array $names, string $wanted): ?string
    {
        $w = trim($wanted);
        foreach ($names as $n) if (strcasecmp(trim($n), $w) === 0) return $n;
        return null;
    }

    /** Normalize header: lowercase, trim, collapse whitespace/newlines */
    private function normHeader(string $h): string
    {
        $h = mb_strtolower($h);
        $h = str_replace(["\r", "\n"], ' ', $h);
        $h = preg_replace('/\s+/', ' ', $h);
        return trim($h);
    }

    private function toBool($value): bool
    {
        $v = mb_strtolower(trim((string)$value));
        return in_array($v, ['1', 'y', 'yes', 'true', 't', 'checked', 'x', 'present'], true);
    }

    private function normalizeWorkingStudent(?string $v): ?string
    {
        if ($v === null) return null;
        $v = mb_strtolower(trim($v));
        if (str_contains($v, 'work')) return 'working';
        if (str_contains($v, 'student')) return 'student';
        return null;
    }

    private function normalizeModeOfPayment(?string $v): ?string
    {
        if ($v === null) return null;
        $v = mb_strtolower(trim($v));
        if (str_contains($v, 'gcash')) return 'gcash';
        if (str_contains($v, 'bank')) return 'bank';
        if (str_contains($v, 'cash')) return 'cash';
        return 'other';
    }

    private function rowEmpty($sheet, int $row, string $startCol, string $endCol): bool
    {
        for ($col = $startCol; $col !== $this->nextCol($endCol); $col = $this->nextCol($col)) {
            $v = trim((string)$sheet->getCell("{$col}{$row}")->getCalculatedValue());
            if ($v !== '') return false;
        }
        return true;
    }

    private function nextCol(string $col): string
    {
        $num = 0;
        for ($i = 0; $i < strlen($col); $i++) $num = $num * 26 + (ord($col[$i]) - 64);
        $num++;
        $s = '';
        while ($num > 0) {
            $rem = ($num - 1) % 26;
            $s = chr(65 + $rem) . $s;
            $num = intdiv($num - 1, 26);
        }
        return $s;
    }

    /**
     * Interactive column mapping interface
     */
    private function interactiveMapping(array $rawHeaders, array $nidx): array
    {
        $this->warn("\n=== INTERACTIVE COLUMN MAPPING ===");
        $this->info("Map each Excel column to a database field.\n");

        $availableFields = $this->getAvailableFields();
        $customMap = [];

        // Show all Excel columns with their normalized keys
        $excelColumns = [];
        foreach ($rawHeaders as $i => $header) {
            if (trim($header) === '') continue;
            $norm = $this->normHeader($header);
            if ($norm !== '' && isset($nidx[$norm])) {
                $excelColumns[] = [
                    'raw' => $header,
                    'normalized' => $norm,
                    'column' => $nidx[$norm]
                ];
            }
        }

        $this->info("Found " . count($excelColumns) . " columns in Excel:\n");

        foreach ($excelColumns as $idx => $col) {
            $this->line("\n[" . ($idx + 1) . "/" . count($excelColumns) . "] Excel Column: '{$col['raw']}'");
            $this->line("    Column Letter: {$col['column']}");

            // Check if there's a default mapping
            $defaultField = $this->map[$col['normalized']] ?? null;
            if ($defaultField) {
                $this->info("    Default mapping → {$defaultField}");
            }

            // Show available fields
            $this->line("\n    Available database fields:");
            foreach ($availableFields as $num => $field) {
                $marker = ($defaultField === $field['value']) ? ' ← (default)' : '';
                $this->line("      [{$num}] {$field['label']}{$marker}");
            }
            $this->line("      [0] Skip this column");
            if ($defaultField) {
                $this->line("      [Enter] Use default ({$defaultField})");
            }

            $choice = $this->ask("    Your choice", $defaultField ? '' : '0');

            // Handle default (empty input)
            if ($choice === '' && $defaultField) {
                $customMap[$col['normalized']] = $defaultField;
                $this->info("    ✓ Mapped to: {$defaultField}");
                continue;
            }

            // Handle skip
            if ($choice === '0') {
                $this->line("    ⊘ Skipped");
                continue;
            }

            // Handle numeric choice
            if (is_numeric($choice) && isset($availableFields[(int)$choice])) {
                $selectedField = $availableFields[(int)$choice]['value'];
                $customMap[$col['normalized']] = $selectedField;
                $this->info("    ✓ Mapped to: {$selectedField}");
                continue;
            }

            // Handle direct field name input
            $fieldNames = array_column($availableFields, 'value');
            if (in_array($choice, $fieldNames)) {
                $customMap[$col['normalized']] = $choice;
                $this->info("    ✓ Mapped to: {$choice}");
                continue;
            }

            // Invalid choice - skip
            $this->warn("    Invalid choice - skipping this column");
        }

        $this->line("\n=== MAPPING SUMMARY ===");
        $mappedCount = count($customMap);
        $skippedCount = count($excelColumns) - $mappedCount;
        $this->info("Mapped: {$mappedCount} columns");
        $this->line("Skipped: {$skippedCount} columns");

        if ($mappedCount > 0) {
            $this->line("\nFinal mappings:");
            foreach ($customMap as $norm => $field) {
                $this->line("  '{$norm}' → {$field}");
            }
        }

        return $customMap;
    }

    /**
     * Get available database fields for mapping
     */
    private function getAvailableFields(): array
    {
        return [
            1 => ['value' => 'email', 'label' => 'email - Email Address'],
            2 => ['value' => 'title', 'label' => 'title - Title (Mr/Ms/Dr)'],
            3 => ['value' => 'first_name', 'label' => 'first_name - First Name'],
            4 => ['value' => 'last_name', 'label' => 'last_name - Last Name'],
            5 => ['value' => 'middle_initial', 'label' => 'middle_initial - Middle Initial'],
            6 => ['value' => 'mobile_number', 'label' => 'mobile_number - Mobile Number'],
            7 => ['value' => 'home_address', 'label' => 'home_address - Home Address'],
            8 => ['value' => 'church_name', 'label' => 'church_name - Church Name'],
            9 => ['value' => 'church_address', 'label' => 'church_address - Church Address'],
            10 => ['value' => 'working_or_student', 'label' => 'working_or_student - Working or Student'],
            11 => ['value' => 'spheres_raw', 'label' => 'spheres_raw - Vocation/Work Sphere(s)'],
            12 => ['value' => 'mode_of_payment', 'label' => 'mode_of_payment - Mode of Payment'],
            13 => ['value' => 'proof_of_payment_url', 'label' => 'proof_of_payment_url - Proof of Payment Link'],
            14 => ['value' => 'notes', 'label' => 'notes - Notes'],
            15 => ['value' => 'group_name', 'label' => 'group_name - Group Name'],
            16 => ['value' => 'reference_number', 'label' => 'reference_number - Reference Number'],
            17 => ['value' => 'reconciled', 'label' => 'reconciled - Reconciled (boolean)'],
            18 => ['value' => 'finance_checked', 'label' => 'finance_checked - Finance Checked (boolean)'],
            19 => ['value' => 'email_confirmed', 'label' => 'email_confirmed - Email Confirmed (boolean)'],
            20 => ['value' => 'attendance', 'label' => 'attendance - Attendance (boolean)'],
            21 => ['value' => 'id_issued', 'label' => 'id_issued - ID Issued (boolean)'],
            22 => ['value' => 'book_given', 'label' => 'book_given - Book Given (boolean)'],
            23 => ['value' => 'age_range', 'label' => 'age_range - Age Range'],
        ];
    }
}
