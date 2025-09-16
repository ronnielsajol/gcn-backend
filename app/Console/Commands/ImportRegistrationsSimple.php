<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Group;
use App\Models\Sphere;
use Google_Client;
use Google_Service_Drive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportRegistrationsSimple extends Command
{
    protected $signature = 'import:registrations-simple
                            {path? : Excel path under storage/app/imports (default: 3.0_REG FILE_May 25, 2024_CLARK.xlsx)}
                            {--sheet=FINAL CLARK REGISTRATION_2024 : Sheet name (trailing space tolerated)}
                            {--drive : Download proof-of-payment files from Google Drive into storage}';

    protected $description = 'Blind-insert every row from the Excel into users (nullable columns allowed).';

    /** Header map: Excel header -> users column */
    private array $map = [
        'Email Address' => 'email',
        'Title' => 'title',
        'Last Name' => 'last_name',
        'First Name' => 'first_name',
        'Middle Initial' => 'middle_initial',
        'Mobile Number ' => 'mobile_number',
        'Home Address (City/Town/Province [e.g. Taguig City])' => 'home_address',
        'Name of Church where you attend' => 'church_name',
        'Church Address (City/Town/Province [e.g. Taguig City])' => 'church_address',
        'Working or Student' => 'working_or_student',
        'Vocation/Work Sphere (check all that apply)' => 'spheres_raw',
        'Mode of Payment' => 'mode_of_payment',
        'Proof of Payment  (Please upload a clear photo of your deposit slip)' => 'proof_of_payment_url',
        'Notes' => 'notes',
        'Group ' => 'group_name',
        'Reference Number ' => 'reference_number',
        'RECONCILED' => 'reconciled',
        "VICTORY PAMPANGA FINANCE\nMS. ABBEY" => 'finance_checked',
        "EMAIL CONFRIMATION\nTN SECRETARIAT" => 'email_confirmed',
        'ATTENDANCE' => 'attendance',
        'ID ' => 'id_issued',
        'BOOK ' => 'book_given',
        // We ignore the sheet Timestamp and the duplicate Email column on purpose
    ];

    public function handle(): int
    {
        $fileRelative  = $this->argument('path') ?? '3.0_REG FILE_May 25, 2024_CLARK.xlsx';
        $wantedSheet   = $this->option('sheet');
        $downloadDrive = (bool)$this->option('drive');

        $filePath = storage_path("app/imports/{$fileRelative}");
        if (!file_exists($filePath)) {
            $this->error("Excel not found: {$filePath}");
            return self::FAILURE;
        }

        $drive = null;
        if ($downloadDrive) {
            $drive = $this->makeDriveService();
            if (!$drive) {
                $this->error('Google Drive service failed to initialize.');
                return self::FAILURE;
            }
        }

        $spreadsheet = IOFactory::load($filePath);
        $realName = $this->resolveSheetName($spreadsheet->getSheetNames(), $wantedSheet);
        if (!$realName) {
            $this->warn("Sheet '{$wantedSheet}' not found. Available:");
            foreach ($spreadsheet->getSheetNames() as $n) $this->line("- {$n}");
            return self::FAILURE;
        }

        $sheet = $spreadsheet->getSheetByName($realName);

        // Your file: headers in B2:Y2, data starts row 3
        $headerRow = 2;
        $startCol = 'B';
        $endCol = 'Y';
        $headers = [];
        for ($col = $startCol; $col !== $this->nextCol($endCol); $col = $this->nextCol($col)) {
            $headers[] = trim((string)$sheet->getCell("{$col}{$headerRow}")->getCalculatedValue());
        }

        // Map label -> column letter
        $idx = [];
        for ($i = 0, $col = $startCol; $col !== $this->nextCol($endCol); $col = $this->nextCol($col), $i++) {
            $label = $headers[$i] ?? '';
            if ($label !== '') $idx[$label] = $col;
        }

        $firstDataRow = $headerRow + 1;
        $lastRow = $sheet->getHighestRow();

        $inserted = 0;
        $skippedEmpty = 0;

        $this->info("Inserting rows {$firstDataRow}..{$lastRow} from '{$realName}'");
        for ($row = $firstDataRow; $row <= $lastRow; $row++) {
            if ($this->rowEmpty($sheet, $row, $startCol, $endCol)) {
                $skippedEmpty++;
                continue;
            }

            // Build payload from mapping (nullable allowed)
            $payload = [];
            foreach ($this->map as $label => $field) {
                if (!isset($idx[$label])) continue;
                $val = $sheet->getCell($idx[$label] . $row)->getCalculatedValue();
                $payload[$field] = is_string($val) ? trim($val) : $val;
            }

            // Normalize flags (optional)
            foreach (['reconciled', 'finance_checked', 'email_confirmed', 'attendance', 'id_issued', 'book_given'] as $flag) {
                if (array_key_exists($flag, $payload)) {
                    $payload[$flag] = $this->toBool($payload[$flag]);
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

            // Optional: download proof file and store local path
            $storedPath = null;
            if ($downloadDrive && !empty($payload['proof_of_payment_url'])) {
                try {
                    $storedPath = $this->downloadProofFromDrive($drive, (string)$payload['proof_of_payment_url']);
                } catch (\Throwable $e) {
                    Log::warning('Drive download failed', ['row' => $row, 'error' => $e->getMessage()]);
                }
            }

            // Create new user record (NO upsert)
            $user = new User();
            $user->email                = $payload['email'] ?? null;
            $user->title                = $payload['title'] ?? null;
            $user->last_name            = $payload['last_name'] ?? null;
            $user->first_name           = $payload['first_name'] ?? null;
            $user->middle_initial       = $payload['middle_initial'] ?? null;
            $user->mobile_number        = $payload['mobile_number'] ?? null;
            $user->home_address         = $payload['home_address'] ?? null;
            $user->church_name          = $payload['church_name'] ?? null;
            $user->church_address       = $payload['church_address'] ?? null;
            $user->working_or_student   = $this->normalizeWorkingStudent($payload['working_or_student'] ?? null);
            $user->mode_of_payment      = $this->normalizeModeOfPayment($payload['mode_of_payment'] ?? null);
            $user->proof_of_payment_url = $payload['proof_of_payment_url'] ?? null;
            if ($storedPath) $user->proof_of_payment_path = $storedPath;
            $user->notes                = $payload['notes'] ?? null;
            $user->group_id             = $groupId;
            $user->reference_number     = $payload['reference_number'] ?? null;
            $user->reconciled           = (bool)($payload['reconciled'] ?? false);
            $user->finance_checked      = (bool)($payload['finance_checked'] ?? false);
            $user->email_confirmed      = (bool)($payload['email_confirmed'] ?? false);
            $user->attendance           = (bool)($payload['attendance'] ?? false);
            $user->id_issued            = (bool)($payload['id_issued'] ?? false);
            $user->book_given           = (bool)($payload['book_given'] ?? false);
            $user->save();
            $inserted++;

            // Optional: spheres pivot (if you want this even in simple mode)
            if (!empty($payload['spheres_raw'])) {
                $labels = array_filter(array_map('trim', explode(',', (string)$payload['spheres_raw'])));
                $sphereIds = [];
                foreach ($labels as $label) {
                    $slug = Str::slug($label, '-');
                    $sphere = Sphere::where('slug', $slug)->first();
                    if (!$sphere) {
                        $sphere = Sphere::whereRaw('LOWER(name) = ?', [mb_strtolower($label)])->first();
                    }
                    if ($sphere) $sphereIds[] = $sphere->id;
                }
                if (!empty($sphereIds)) {
                    $user->spheres()->syncWithoutDetaching($sphereIds);
                }
            }
        }

        $this->info("Done. Inserted={$inserted}, skipped_blank_rows={$skippedEmpty}");
        return self::SUCCESS;
    }

    /* ===== Helpers ===== */

    private function resolveSheetName(array $names, string $wanted): ?string
    {
        $w = trim($wanted);
        foreach ($names as $n) if (strcasecmp(trim($n), $w) === 0) return $n;
        return null;
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

    private function makeDriveService(): ?Google_Service_Drive
    {
        try {
            $c = new Google_Client();
            $c->setAuthConfig(storage_path('app/google/credentials.json'));
            $c->addScope(Google_Service_Drive::DRIVE_READONLY);
            return new Google_Service_Drive($c);
        } catch (\Throwable $e) {
            Log::error('Drive init error', ['e' => $e->getMessage()]);
            return null;
        }
    }

    private function downloadProofFromDrive(Google_Service_Drive $drive, string $driveUrl): ?string
    {
        $fileId = null;
        if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $driveUrl, $m)) $fileId = $m[1];
        elseif (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $driveUrl, $m)) $fileId = $m[1];
        if (!$fileId) return null;

        $meta = $drive->files->get($fileId, ['fields' => 'name,mimeType']);
        $mime = $meta->getMimeType() ?? 'application/octet-stream';
        $name = $meta->getName() ?? ($fileId . '.bin');

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (!$ext) {
            $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
            $ext = $map[$mime] ?? 'bin';
        }

        $res = $drive->files->get($fileId, ['alt' => 'media']);
        $content = $res->getBody()->getContents();

        $hash = substr(hash('sha256', $content), 0, 16);
        $path = "proofs/{$hash}." . strtolower($ext);
        Storage::disk('public')->put($path, $content);
        return $path;
    }
}
