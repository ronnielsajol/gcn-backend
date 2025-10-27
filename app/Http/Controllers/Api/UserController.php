<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ChecksEventAttendance;
use App\Http\Traits\FilterSortTrait;
use App\Models\Event;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserController extends Controller
{
    use AuthorizesRequests, ChecksEventAttendance, FilterSortTrait;
    protected ActivityLogService $activityLogService;

    protected array $searchableFields = ['first_name', 'last_name', 'email'];
    protected array $filterableFields = ['role'];
    protected array $sortableFields = [
        'id',
        'first_name',
        'last_name',
        'email',
        'gender',
        'religion',
        'role',
        'created_at',
        'updated_at',
    ];
    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);
        $query = User::with('userFiles')->where('role', 'user');

        // Apply search and basic filters
        $this->applyFilters($query, $request, $this->searchableFields, array_diff($this->filterableFields, ['sphere']));

        // Handle sphere filtering separately
        if ($request->filled('sphere_id')) {
            $sphereId = $request->sphere_id;

            if ($sphereId == 0) {
                $query->whereDoesntHave('spheres');
            } else {
                $query->whereHas('spheres', function ($q) use ($sphereId) {
                    $q->where('spheres.id', $sphereId);
                });
            }
        }

        // Handle filtering for users with no spheres (alternative method)
        if ($request->filled('no_sphere') && $request->boolean('no_sphere')) {
            $query->whereDoesntHave('spheres');
        }

        $this->applySorting($query, $request, $this->sortableFields);

        $users = $query->paginate($this->getPerPageLimit($request));

        // Add event attendance check if event_id is provided in request
        if ($request->filled('event_id')) {
            $this->withEventAttendanceCheck($users, (int) $request->event_id);
        }

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $validatedData = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'address' => 'required|string',
            'gender' => 'required|in:male,female,other',
            'religion' => 'required|string|max:255',
            'contact_number' => 'required|string|max:20',
            'email' => 'required|email|unique:users',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:pdf,doc,docx,jpg,png|max:5120',
        ]);

        $validatedData['role'] = 'user';

        if ($request->hasFile('profile_image')) {
            $path = $request->file('profile_image')->store('profile_images', 'public');
            $validatedData['profile_image'] = Storage::url($path);
        }

        $user = User::create($validatedData);

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();

                $sanitizedName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
                $date = now()->format('Ymd_His');

                $newFileName = "{$user->id}_{$date}_{$sanitizedName}.{$extension}";

                $filePath = $file->storeAs('user_files/' . $user->id, $newFileName, 'public');

                $user->userFiles()->create([
                    'file_path' => $filePath,
                    'file_name' => $originalName,
                    'file_size' => $file->getSize(),
                    'file_type' => $file->getClientMimeType(),
                    'uploaded_by' => $request->user()->id,
                ]);
            }
        }
        $this->activityLogService->log(
            $request->user(),
            'created',
            'User',
            $user->id,
            null,
            $user->toArray(),
            $request
        );
        return response()->json($user->load('userFiles'), 201);
    }

    public function show(Request $request, $id)
    {
        $user = User::with('userFiles')->findOrFail($id);
        $this->authorize('view', $user);

        // Add event attendance check if event_id is provided in request
        if ($request->filled('event_id')) {
            $this->withEventAttendanceCheck($user, (int) $request->event_id);
        }

        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $this->authorize('update', $user);

        $oldValues = $user->toArray();
        $validatedData = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string',
            'gender' => 'sometimes|required|in:male,female,other',
            'religion' => 'sometimes|required|string|max:255',
            'contact_number' => 'sometimes|required|string|max:20',
            'email' => "sometimes|required|email|unique:users,email,{$id}",
            'password' => 'sometimes|nullable|string|min:8',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:pdf,doc,docx,jpg,png|max:5120',
        ]);

        if ($request->filled('password')) {
            $validatedData['password'] = Hash::make($validatedData['password']);
        }

        if ($request->hasFile('profile_image')) {
            if ($user->profile_image) {
                $path = str_replace('/storage/', '', $user->profile_image);
                Storage::disk('public')->delete($path);
            }

            $storedPath = $request->file('profile_image')->store('profile_images', 'public');
            $validatedData['profile_image'] = Storage::url($storedPath);
        }

        $user->update($validatedData);

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();

                $sanitizedName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
                $date = now()->format('Ymd_His');

                $newFileName = "{$user->id}_{$date}_{$sanitizedName}.{$extension}";

                $filePath = $file->storeAs('user_files/' . $user->id, $newFileName, 'public');
                $user->userFiles()->create([
                    'file_path' => $filePath,
                    'file_name' => $originalName,
                    'file_size' => $file->getSize(),
                    'file_type' => $file->getClientMimeType(),
                    'uploaded_by' => $request->user()->id,
                ]);
            }
        }

        $user->refresh();
        $newValues = $user->toArray();
        $this->activityLogService->log(
            $request->user(),
            'updated',
            'User',
            $user->id,
            $oldValues,
            $newValues,
            $request
        );
        return response()->json($user->load('userFiles'));
    }

    public function destroy(Request $request, $id)
    {
        $user = User::with('userFiles')->findOrFail($id);
        $this->authorize('delete', $user);

        $oldValues = $user->toArray();

        if ($user->profile_image) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $user->profile_image));
        }
        foreach ($user->userFiles as $file) {
            Storage::disk('public')->delete($file->file_path);
        }

        $user->delete();

        $this->activityLogService->log(
            $request->user(),
            'deleted',
            'User',
            $user->id,
            $oldValues,
            null,
            $request
        );
        return response()->json(['message' => 'User deleted successfully']);
    }
    public function getUserEvents(User $user)
    {
        // Get events with additional data
        $events = $user->events()
            ->with(['createdBy:id,first_name,last_name',])
            ->select('events.id', 'events.name')
            ->orderBy('start_time', 'asc') // Order by start time
            ->paginate(15);

        return response()->json([
            'message' => "Events for {$user->first_name} {$user->last_name}",
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
            ],
            'events' => $events,
        ]);
    }

    /**
     * Export users with their event attendance count to CSV
     */
    public function exportUsersWithEventCount(Request $request)
    {
        $this->authorize('viewAny', User::class);

        // Get users with their event count (excluding soft-deleted events)
        $users = User::where('role', 'user')
            ->withCount(['events' => function ($query) {
                $query->whereNull('events.deleted_at');
            }])
            ->get(['id', 'first_name', 'last_name', 'email', 'contact_number', 'gender', 'religion', 'address']);


        // Debug: Check if relationships are working
        if ($users->isEmpty()) {
            return response()->json(['message' => 'No users found'], 404);
        }

        // Prepare CSV headers
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="users_event_attendance_' . now()->format('Y-m-d_H-i-s') . '.csv"',
        ];

        // Create CSV content
        $callback = function () use ($users) {
            $file = fopen('php://output', 'w');

            // Add CSV headers
            fputcsv($file, [
                'User ID',
                'First Name',
                'Last Name',
                'Email',
                'Contact Number',
                'Gender',
                'Religion',
                'Address',
                'Total Events Attended'
            ]);

            // Add user data
            foreach ($users as $user) {
                fputcsv($file, [
                    $user->id,
                    $user->first_name,
                    $user->last_name,
                    $user->email,
                    "'" . $user->contact_number,
                    ucfirst($user->gender),
                    $user->religion,
                    $user->address,
                    $user->events_count
                ]);
            }

            fclose($file);
        };

        // Log the export activity
        $this->activityLogService->log(
            $request->user(),
            'exported',
            'User',
            $users->isNotEmpty() ? $users->first()->id : null,
            null,
            ['action' => 'users_with_event_count_csv_export', 'total_users' => $users->count()],
            $request
        );

        return response()->stream($callback, 200, $headers);
    }
    // /**
    //  * Test method to return users with event count as JSON (for debugging)
    //  */
    // public function testUsersWithEventCountJson(Request $request)
    // {
    //     $this->authorize('viewAny', User::class);

    //     // Method 1: Using withCount (what your CSV export uses)
    //     $usersWithCount = User::where('role', 'user')
    //         ->withCount(['events' => function ($query) {
    //             $query->whereNull('events.deleted_at');
    //         }])
    //         ->select('id', 'first_name', 'last_name', 'email', 'contact_number', 'gender', 'religion', 'address')
    //         ->get();

    //     // Method 2: Using eager loading to see actual events
    //     $usersWithEvents = User::where('role', 'user')
    //         ->with(['events' => function ($query) {
    //             $query->whereNull('events.deleted_at')
    //                 ->select('events.id', 'events.name');
    //         }])
    //         ->select('id', 'first_name', 'last_name', 'email')
    //         ->limit(3) // Just first 3 for testing
    //         ->get();

    //     // Method 3: Manual count for comparison
    //     $manualCounts = [];
    //     foreach ($usersWithEvents as $user) {
    //         $manualCounts[$user->id] = $user->events()->whereNull('events.deleted_at')->count();
    //     }

    //     return response()->json([
    //         'message' => 'Debug data for users with event counts',
    //         'method1_withCount' => $usersWithCount->map(function ($user) {
    //             return [
    //                 'id' => $user->id,
    //                 'name' => $user->first_name . ' ' . $user->last_name,
    //                 'events_count' => $user->events_count ?? 'NULL',
    //                 'has_events_count_attribute' => isset($user->events_count)
    //             ];
    //         }),
    //         'method2_withEvents' => $usersWithEvents->map(function ($user) {
    //             return [
    //                 'id' => $user->id,
    //                 'name' => $user->first_name . ' ' . $user->last_name,
    //                 'events' => $user->events,
    //                 'events_via_collection_count' => $user->events->count()
    //             ];
    //         }),
    //         'method3_manual_counts' => $manualCounts,
    //         'total_users_with_role_user' => User::where('role', 'user')->count(),
    //         'total_events' => Event::count(),
    //         'total_active_events' => Event::whereNull('deleted_at')->count(),
    //         'total_pivot_records' => DB::table('event_user')->count(),
    //     ]);
    // }
    /**
     * Export a specific user's detailed information to CSV
     */
    public function exportUserInfo(Request $request, $id)
    {
        $user = User::with(['userFiles', 'events:id,name,start_time,end_time'])
            ->findOrFail($id);

        $this->authorize('view', $user);

        // Prepare CSV headers
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="user_' . $user->id . '_info_' . now()->format('Y-m-d_H-i-s') . '.csv"',
        ];

        // Create CSV content
        $callback = function () use ($user) {
            $file = fopen('php://output', 'w');

            // User Information Section
            fputcsv($file, ['USER INFORMATION']);
            fputcsv($file, ['Field', 'Value']);
            fputcsv($file, ['User ID', $user->id]);
            fputcsv($file, ['First Name', $user->first_name]);
            fputcsv($file, ['Last Name', $user->last_name]);
            fputcsv($file, ['Email', $user->email]);
            fputcsv($file, ['Contact Number', $user->contact_number]);
            fputcsv($file, ['Gender', ucfirst($user->gender)]);
            fputcsv($file, ['Religion', $user->religion]);
            fputcsv($file, ['Address', $user->address]);
            fputcsv($file, ['Created At', $user->created_at->format('Y-m-d H:i:s')]);
            fputcsv($file, ['Updated At', $user->updated_at->format('Y-m-d H:i:s')]);

            // Empty row
            fputcsv($file, []);

            // User Files Section
            fputcsv($file, ['USER FILES']);
            if ($user->userFiles->count() > 0) {
                fputcsv($file, ['File Name', 'File Size (bytes)', 'File Type', 'Upload Date']);
                foreach ($user->userFiles as $file) {
                    fputcsv($file, [
                        $file->file_name,
                        $file->file_size,
                        $file->file_type,
                        $file->created_at->format('Y-m-d H:i:s')
                    ]);
                }
            } else {
                fputcsv($file, ['No files uploaded']);
            }

            // Empty row
            fputcsv($file, []);

            // Events Attended Section
            fputcsv($file, ['EVENTS ATTENDED']);
            if ($user->events->count() > 0) {
                fputcsv($file, ['Event ID', 'Event Name', 'Start Time', 'End Time']);
                foreach ($user->events as $event) {
                    fputcsv($file, [
                        $event->id,
                        $event->name,
                        $event->start_time ? $event->start_time->format('Y-m-d H:i:s') : 'N/A',
                        $event->end_time ? $event->end_time->format('Y-m-d H:i:s') : 'N/A'
                    ]);
                }
            } else {
                fputcsv($file, ['No events attended']);
            }

            fclose($file);
        };

        // Log the export activity
        $this->activityLogService->log(
            $request->user(),
            'exported',
            'User',
            $user->id,
            null,
            ['action' => 'user_info_csv_export'],
            $request
        );

        return response()->stream($callback, 200, $headers);
    }
}
