<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EventController extends Controller
{
    protected ActivityLogService $activityLogService;

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

        // Convert event to array and add paginated users
        $eventData = $event->toArray();
        $eventData['users'] = $users;

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
     * This method should go in your EventController
     * Export attendees of a specific event to CSV
     */
    public function exportEventAttendees(Request $request, $eventId)
    {

        $event = Event::with(['users' => function ($query) {
            $query->select('users.id', 'first_name', 'last_name', 'email', 'contact_number', 'gender', 'religion')
                ->where('role', 'user');
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
                'Contact Number',
                'Gender',
                'Religion'
            ]);

            foreach ($event->users as $user) {
                fputcsv($file, [
                    $user->id,
                    $user->first_name,
                    $user->last_name,
                    $user->email,
                    "'" . $user->contact_number,
                    ucfirst($user->gender),
                    $user->religion
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
}
