<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\FilterSortTrait;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserFile;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class UserFileController extends Controller
{
    use AuthorizesRequests, FilterSortTrait;

    protected ActivityLogService $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    public function index(Request $request, $userId)
    {
        $this->authorize('viewAny', User::class);
        $user = User::findOrFail($userId);
        $files = $user->userFiles()->paginate($this->getPerPageLimit($request));

        return response()->json($files);
    }

    public function store(Request $request, $userId)
    {
        $validator = Validator::make($request->all(), [
            'files.*' => 'required|file|max:10240', // 10MB max per file
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $uploadedFiles = [];

        foreach ($request->file('files') as $file) {
            $path = $file->store('user_files', 'public');

            $userFile = UserFile::create([
                'user_id' => $userId,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $request->user()->getFullNameAttribute(),
            ]);

            $uploadedFiles[] = $userFile;
        }

        return response()->json($uploadedFiles, 201);
    }

    /**
     * Upload files for a user (Frontend calls this endpoint)
     */
    public function upload(Request $request, $userId)
    {
        $this->authorize('viewAny', User::class);

        $validator = Validator::make($request->all(), [
            'files' => 'required|array',
            'files.*' => 'required|file|max:10240', // 10MB max per file
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $uploadedFiles = [];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($request->file('files') as $index => $file) {
                try {
                    $path = $file->store('user_files', 'public');

                    $userFile = UserFile::create([
                        'user_id' => $userId,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'uploaded_by' => $request->user()->getFullNameAttribute,
                    ]);

                    $uploadedFiles[] = $userFile;

                    // Log activity
                    ActivityLog::create([
                        'admin_id' => $request->user()->id,
                        'action' => 'file_upload',
                        'model_type' => UserFile::class,
                        'model_id' => $userFile->id,
                        'old_values' => null,
                        'new_values' => ['file_name' => $file->getClientOriginalName()],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                } catch (\Exception $e) {
                    $errors[] = [
                        'file_index' => $index,
                        'file_name' => $file->getClientOriginalName(),
                        'error' => 'Failed to upload: ' . $e->getMessage()
                    ];
                }
            }

            DB::commit();

            if (count($errors) > 0 && count($uploadedFiles) === 0) {
                // All uploads failed
                return response()->json([
                    'message' => 'All file uploads failed',
                    'errors' => $errors
                ], 500);
            }

            return response()->json([
                'message' => 'Files uploaded successfully',
                'uploaded_files' => $uploadedFiles,
                'errors' => $errors,
                'summary' => [
                    'total_attempted' => count($request->file('files')),
                    'successful' => count($uploadedFiles),
                    'failed' => count($errors)
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk add files for a user
     */
    public function bulkStore(Request $request, $userId)
    {
        $validator = Validator::make($request->all(), [
            'files.*' => 'required|file|max:10240', // 10MB max per file
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $uploadedFiles = [];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($request->file('files') as $index => $file) {
                try {
                    $path = $file->store('user_files', 'public');

                    $userFile = UserFile::create([
                        'user_id' => $userId,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'uploaded_by' => $request->user()->getFullNameAttribute,
                    ]);

                    $uploadedFiles[] = $userFile;

                    // Log activity
                    ActivityLog::create([
                        'admin_id' => $request->user()->id,
                        'action' => 'bulk_file_upload',
                        'model_type' => UserFile::class,
                        'model_id' => $userFile->id,
                        'old_values' => null,
                        'new_values' => ['file_name' => $file->getClientOriginalName()],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                } catch (\Exception $e) {
                    $errors[] = [
                        'file_index' => $index,
                        'file_name' => $file->getClientOriginalName(),
                        'error' => 'Failed to upload: ' . $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'uploaded_files' => $uploadedFiles,
                'errors' => $errors,
                'summary' => [
                    'total_attempted' => count($request->file('files')),
                    'successful' => count($uploadedFiles),
                    'failed' => count($errors)
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Bulk upload failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Bulk delete files (Frontend calls this endpoint)
     */
    public function bulkDelete(Request $request, $userId)
    {
        $this->authorize('viewAny', User::class);

        $validator = Validator::make($request->all(), [
            'file_ids' => 'required|array',
            'file_ids.*' => 'integer|exists:user_files,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $deletedFiles = [];
        $errors = [];

        DB::beginTransaction();

        try {
            $files = UserFile::where('user_id', $userId)
                ->whereIn('id', $request->input('file_ids'))
                ->get();

            foreach ($files as $file) {
                try {
                    // Delete physical file
                    if (Storage::disk('public')->exists($file->file_path)) {
                        Storage::disk('public')->delete($file->file_path);
                    }

                    // Store file info before deletion for response
                    $fileInfo = [
                        'id' => $file->id,
                        'file_name' => $file->file_name,
                        'file_path' => $file->file_path
                    ];

                    // Log activity before deletion
                    ActivityLog::create([
                        'admin_id' => $request->user()->id,
                        'action' => 'bulk_file_delete',
                        'model_type' => UserFile::class,
                        'model_id' => $file->id,
                        'old_values' => $file->toArray(),
                        'new_values' => null,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);

                    $file->delete();
                    $deletedFiles[] = $fileInfo;
                } catch (\Exception $e) {
                    $errors[] = [
                        'file_id' => $file->id,
                        'file_name' => $file->file_name,
                        'error' => 'Failed to delete: ' . $e->getMessage()
                    ];
                }
            }

            // Check for file IDs that weren't found
            $foundIds = $files->pluck('id')->toArray();
            $notFoundIds = array_diff($request->input('file_ids'), $foundIds);

            foreach ($notFoundIds as $notFoundId) {
                $errors[] = [
                    'file_id' => $notFoundId,
                    'error' => 'File not found or does not belong to this user'
                ];
            }

            DB::commit();

            return response()->json([
                'message' => 'Files deleted successfully',
                'deleted_files' => $deletedFiles,
                'errors' => $errors,
                'summary' => [
                    'total_attempted' => count($request->input('file_ids')),
                    'successful' => count($deletedFiles),
                    'failed' => count($errors)
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Bulk delete failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete files (Original method - kept for backward compatibility)
     */
    public function bulkDestroy(Request $request, $userId)
    {
        return $this->bulkDelete($request, $userId);
    }

    public function destroy(Request $request, $userId, $fileId)
    {
        $this->authorize('viewAny', User::class);

        try {
            $file = UserFile::where('user_id', $userId)->findOrFail($fileId);

            // Delete physical file
            if (Storage::disk('public')->exists($file->file_path)) {
                Storage::disk('public')->delete($file->file_path);
            }

            // Log activity before deletion
            ActivityLog::create([
                'admin_id' => $request->user()->id,
                'action' => 'file_delete',
                'model_type' => UserFile::class,
                'model_id' => $file->id,
                'old_values' => $file->toArray(),
                'new_values' => null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $file->delete();

            return response()->json([
                'message' => 'File deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete file: ' . $e->getMessage()
            ], 500);
        }
    }

    public function download($userId, $fileId)
    {
        $this->authorize('viewAny', User::class);
        $file = UserFile::where('user_id', $userId)->findOrFail($fileId);

        $actualPath = storage_path('app/public/' . $file->file_path);

        if (!file_exists($actualPath)) {
            abort(404, 'File not found');
        }

        return response()->download($actualPath, $file->file_name);
    }
}
