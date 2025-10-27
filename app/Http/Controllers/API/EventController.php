<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Traits\FilterSortTrait;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
    use FilterSortTrait;

    protected ActivityLogService $activityLogService;

    // Define searchable fields for users within events
    protected array $userSearchableFields = ['first_name', 'last_name', 'email', 'mobile_number'];
    protected array $userFilterableFields = ['gender', 'religion'];
    protected array $userSortableFields = [
        'id',
        'first_name',
        'last_name',
        'email',
        'gender',
        'religion',
        'mobile_number'
    ];

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Get pagination parameters
        $perPage = $request->get('per_page', 15);
        $userPerPage = $request->get('user_per_page', 5); // Limit users per event in listing

        // Eager load relationships to prevent N+1 query issues
        $events = Event::with(['createdBy:id,first_name,last_name'])
            ->paginate($perPage);

        // Load a limited number of users for each event in the listing
        $events->getCollection()->transform(function ($event) use ($userPerPage) {
            $event->load(['users' => function ($query) use ($userPerPage) {
                $query->select('users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.profile_image')
                    ->limit($userPerPage);
            }]);

            // Add user count for reference
            $event->users_count = $event->users()->count();

            return $event;
        });

        return response()->json($events);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventRequest $request)
    {
        $validatedData = $request->validated();

        // Assign the authenticated user's ID as the creator
        $validatedData['created_by'] = Auth::id();

        $event = Event::create($validatedData);

        ActivityLog::create([
            'admin_id' => Auth::id(),
            'action' => 'create',
            'model_type' => Event::class,
            'model_id' => $event->id,
            'old_values' => null,
            'new_values' => $validatedData,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json([
            'message' => 'Event created successfully.',
            'event' => $event,
        ])->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Event $event)
    {
        // Get pagination parameters
        $perPage = $request->get('per_page', 20);
        $page = $request->get('page', 1);

        // Load the createdBy relationship
        $event->load(['createdBy:id,first_name,last_name']);

        // Get paginated users that are registered to this specific event
        $users = $event->users()
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.profile_image')
            ->paginate($perPage, ['*'], 'page', $page);

        // Get count of users who attended (attendance = 1)
        $attendedCount = $event->users()
            ->where('role', 'user')
            ->where('attendance', 1)
            ->count();

        // Convert event to array and add paginated users and attendance count
        $eventData = $event->toArray();
        $eventData['users'] = $users;
        $eventData['attended_count'] = $attendedCount;

        return response()->json($eventData);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEventRequest $request, Event $event)
    {
        $event->update($request->validated());

        ActivityLog::create([
            'admin_id' => Auth::id(),
            'action' => 'update',
            'model_type' => Event::class,
            'model_id' => $event->id,
            'old_values' => $event->getOriginal(),
            'new_values' => $event->getChanges(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json($event);
    }

    public function updateStatus(Request $request, Event $event)
    {
        $validated = $request->validate([
            'status' => 'required|in:upcoming,ongoing,completed,cancelled',
        ]);

        $originalValues = $event->getOriginal();

        $event->update($validated);


        ActivityLog::create([
            'admin_id' => Auth::id(),
            'action' => 'update_status',
            'model_type' => Event::class,
            'model_id' => $event->id,
            'old_values' => ['status' => $originalValues['status']],
            'new_values' => $event->getChanges(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json([
            'message' => 'Event status updated successfully.',
            'event' => $event
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Event $event)
    {
        $event->delete();

        ActivityLog::create([
            'admin_id' => Auth::id(),
            'action' => 'delete',
            'model_type' => Event::class,
            'model_id' => $event->id,
            'old_values' => $event->getOriginal(),
            'new_values' => null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
        return response()->json(null, 204); // 204 No Content
    }

    /**
     * Attach one or multiple users to the specified event.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Event  $event
     * @return \Illuminate\Http\JsonResponse
     */
    public function attachUser(Request $request, Event $event)
    {
        $validated = $request->validate([
            'user_id' => 'required_without:user_ids|exists:users,id',
            'user_ids' => 'required_without:user_id|array|min:1',
            'user_ids.*' => 'exists:users,id',
        ]);

        $userIds = [];
        if (isset($validated['user_id'])) {
            $userIds = [$validated['user_id']];
        } elseif (isset($validated['user_ids'])) {
            $userIds = $validated['user_ids'];
        }

        $existingUserIds = $event->users()->whereIn('users.id', $userIds)->pluck('users.id')->toArray();
        $newUserIds = array_diff($userIds, $existingUserIds);

        // Attach the users
        $event->users()->syncWithoutDetaching($userIds);

        $newlyAttachedCount = count($newUserIds);
        $alreadyAttachedCount = count($existingUserIds);
        $totalAttempted = count($userIds);

        // Eager load the users to return the updated list
        $event->load('users:id,first_name,last_name,email');

        ActivityLog::create([
            'admin_id' => Auth::id(),
            'action' => 'attach_users',
            'model_type' => Event::class,
            'model_id' => $event->id,
            'old_values' => null,
            'new_values' => [
                'attached_user_ids' => $userIds,
                'newly_attached_user_ids' => $newUserIds,
                'total_users_attempted' => $totalAttempted,
                'newly_attached_count' => $newlyAttachedCount,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $message = $totalAttempted === 1
            ? 'User successfully assigned to the event.'
            : "{$totalAttempted} users successfully processed for the event.";

        if ($alreadyAttachedCount > 0) {
            $message .= " ({$newlyAttachedCount} newly attached, {$alreadyAttachedCount} already attached)";
        }

        return response()->json([
            'message' => $message,
            'stats' => [
                'total_attempted' => $totalAttempted,
                'newly_attached' => $newlyAttachedCount,
                'already_attached' => $alreadyAttachedCount,
                'total_attendees' => $event->users->count(),
            ]
        ]);
    }


    /**
     * Detach one or multiple users from the specified event.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Event  $event
     * @return \Illuminate\Http\JsonResponse
     */
    public function detachUser(Request $request, Event $event)
    {
        $validated = $request->validate([
            'user_id' => 'required_without:user_ids|exists:users,id',
            'user_ids' => 'required_without:user_id|array|min:1',
            'user_ids.*' => 'exists:users,id',
        ]);

        $userIds = [];
        if (isset($validated['user_id'])) {
            $userIds = [$validated['user_id']];
        } elseif (isset($validated['user_ids'])) {
            $userIds = $validated['user_ids'];
        }

        $attachedUserIds = $event->users()->whereIn('users.id', $userIds)->pluck('users.id')->toArray();
        $notAttachedUserIds = array_diff($userIds, $attachedUserIds);

        $detachedCount = count($attachedUserIds);
        $notAttachedCount = count($notAttachedUserIds);

        // Detach only the users that are actually attached
        if ($detachedCount > 0) {
            $event->users()->detach($attachedUserIds);
        }

        // Eager load the users to return the updated list
        $event->load('users:id,first_name,last_name,email');

        ActivityLog::create([
            'admin_id' => Auth::id(),
            'action' => 'detach_users',
            'model_type' => Event::class,
            'model_id' => $event->id,
            'old_values' => [
                'detached_user_ids' => $attachedUserIds,
                'not_attached_user_ids' => $notAttachedUserIds,
            ],
            'new_values' => null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $message = count($userIds) === 1
            ? ($detachedCount > 0 ? 'User successfully removed from the event.' : 'User was not attached to this event.')
            : "{$detachedCount} users successfully removed from the event.";

        if ($notAttachedCount > 0 && count($userIds) > 1) {
            $message .= " ({$notAttachedCount} were not attached)";
        }

        return response()->json([
            'message' => $message,
            'stats' => [
                'total_attempted' => count($userIds),
                'actually_detached' => $detachedCount,
                'not_attached' => $notAttachedCount,
                'total_attendees' => $event->users->count(),
            ]
        ]);
    }

    /**
     * Get all users for a specific event
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $eventId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEventUsers(Request $request, $eventId)
    {
        $event = Event::findOrFail($eventId);

        // Basic authorization - only authenticated users
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Create a proper query builder for users that belong to this event
        $query = User::whereHas('events', function ($q) use ($eventId) {
            $q->where('events.id', $eventId);
        })
            ->select('users.id', 'first_name', 'last_name', 'email')
            ->where('role', 'user');

        // Apply search, filter, and sort functionality
        $this->applyFilters($query, $request, $this->userSearchableFields, $this->userFilterableFields);
        $this->applySorting($query, $request, $this->userSortableFields, 'first_name'); // Default sort by first_name

        // Get pagination parameters
        $perPage = $this->getPerPageLimit($request);

        // Apply pagination
        $users = $query->paginate($perPage);

        // Log the activity
        $this->activityLogService->log(
            $request->user(),
            'viewed',
            'Event',
            $event->id,
            null,
            [
                'action' => 'viewed_event_users',
                'total_users' => $users->total(),
                'search_query' => $request->get('search'),
                'filters' => $request->only($this->userFilterableFields)
            ],
            $request
        );

        return response()->json([
            'message' => "Users for event: {$event->name}",
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
                'status' => $event->status,
                'total_users' => $users->total(),
            ],
            'users' => $users,
            'search_info' => [
                'searchable_fields' => $this->userSearchableFields,
                'filterable_fields' => $this->userFilterableFields,
                'sortable_fields' => $this->userSortableFields
            ]
        ]);
    }




    /**
     * This method should go in your EventController
     * Export attendees of a specific event to CSV
     */
    public function exportEventAttendees(Request $request, $eventId)
    {
        $event = Event::with(['users' => function ($query) {
            $query->select(
                'users.id',
                'first_name',
                'last_name',
                'email',
                'mobile_number',
                'church_name',
                'home_address',
                'working_or_student',
                'vocation_work_sphere'
            )->where('role', 'user')->with('spheres:id,name');
        }])->findOrFail($eventId);

        // Prepare CSV headers
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="event_' . $eventId . '_attendees_' . now()->format('Y-m-d_H-i-s') . '.csv"',
        ];

        // Create CSV content
        $callback = function () use ($event) {
            $file = fopen('php://output', 'w');

            // Add event info header
            fputcsv($file, ['EVENT INFORMATION']);
            fputcsv($file, ['Event ID', $event->id]);
            fputcsv($file, ['Event Name', $event->name]);
            fputcsv($file, ['Start Time', $event->start_time ? $event->start_time->format('Y-m-d H:i:s') : 'N/A']);
            fputcsv($file, ['End Time', $event->end_time ? $event->end_time->format('Y-m-d H:i:s') : 'N/A']);
            fputcsv($file, ['Total Attendees', $event->users->count()]);

            // Empty row
            fputcsv($file, []);

            fputcsv($file, ['ATTENDEES LIST']);
            fputcsv($file, [
                'User ID',
                'First Name',
                'Last Name',
                'Email',
                'Mobile Number',
                'Church Name',
                'Home Address',
                'Working/Student Status',
                'Spheres'
            ]);

            foreach ($event->users as $user) {
                // Get sphere names as comma-separated string
                $sphereNames = $user->spheres->pluck('name')->join(', ');

                fputcsv($file, [
                    $user->id,
                    $user->first_name,
                    $user->last_name,
                    $user->email,
                    $user->mobile_number,
                    $user->church_name,
                    $user->home_address,
                    $user->working_or_student,
                    $sphereNames ?: 'No Spheres'
                ]);
            }

            fclose($file);
        };

        $this->activityLogService->log(
            $request->user(),
            'exported',
            'Event',
            $event->id,
            null,
            ['action' => 'event_attendees_csv_export', 'total_attendees' => $event->users->count()],
            $request
        );

        return response()->stream($callback, 200, $headers);
    }

    public function exportEventAttendeesPdf(Event $event)
    {
        try {
            // Significantly increase limits for large exports
            ini_set('memory_limit', '2G');
            ini_set('max_execution_time', 300); // 5 minutes
            set_time_limit(300);

            // Use efficient query to get only needed fields
            $attendees = User::whereHas('events', function ($query) use ($event) {
                $query->where('events.id', $event->id);
            })
                ->select('id', 'first_name', 'last_name', 'email', 'mobile_number', 'gender', 'religion', 'home_address', 'profile_image')
                ->get();

            Log::info("PDF Export Started", [
                'event_id' => $event->id,
                'attendee_count' => $attendees->count(),
                'memory_before' => memory_get_usage(true)
            ]);

            // For very large datasets (500+), consider chunked processing or pagination warning
            if ($attendees->count() > 500) {
                return response()->json([
                    'error' => 'Too many attendees for single PDF export.',
                    'attendee_count' => $attendees->count(),
                    'suggestion' => 'Consider using CSV export for large datasets or contact administrator for batch processing.',
                    'max_recommended' => 500
                ], 422);
            }

            $pdf = Pdf::loadView('exports.event-attendees-pdf', [
                'event' => $event,
                'attendees' => $attendees,
                'generated_at' => now()
            ]);

            // Ultra-optimized PDF settings for large documents
            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'dpi' => 72, // Minimum DPI for faster processing
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => true, // Enable remote for images
                'isHtml5ParserEnabled' => false, // Disable for speed
                'isPhpEnabled' => true,
                'chroot' => public_path(),
                'debugKeepTemp' => false,
                'debugPng' => false,
                'debugLayout' => false,
                'enablePhp' => true,
                'enableJavascript' => false, // Disable JS
                'enableRemote' => true, // Enable remote for images
                'fontSubsetting' => false, // Disable font subsetting for speed
            ]);

            $filename = 'event-' . $event->id . '-attendees-' . now()->format('Y-m-d') . '.pdf';

            Log::info("PDF Export Completed", [
                'event_id' => $event->id,
                'attendee_count' => $attendees->count(),
                'memory_after' => memory_get_usage(true)
            ]);

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('PDF Export Error: ' . $e->getMessage(), [
                'event_id' => $event->id,
                'attendee_count' => $attendees->count() ?? 0,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'stack_trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to generate PDF. Please try again or contact administrator.',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'attendee_count' => $attendees->count() ?? 0
            ], 500);
        }
    }
}
