<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\FilterSortTrait;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class AdminController extends Controller
{
    use AuthorizesRequests, FilterSortTrait;

    protected ActivityLogService $activityLogService;

    protected array $searchableFields = ['first_name', 'last_name', 'email', 'contact_number'];
    protected array $filterableFields = ['role', 'gender', 'religion'];
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
        'contact_number'
    ];

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    public function index(Request $request)
    {
        // Use the same authorization method as UserController
        $this->authorize('viewAnyAdmins', User::class);

        $query = User::with('userFiles')->whereIn('role', ['admin', 'super_admin']);

        // Use trait methods with controller-specific configuration
        $this->applyFilters($query, $request, $this->searchableFields, $this->filterableFields);
        $this->applySorting($query, $request, $this->sortableFields);

        return response()->json($query->paginate($this->getPerPageLimit($request)));
    }

    public function store(Request $request)
    {
        $this->authorize('createAdmin', User::class);

        $validatedData = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['admin', 'super_admin'])],
            'address' => 'sometimes|string',
            'gender' => 'sometimes|in:male,female,other',
            'religion' => 'sometimes|string|max:255',
            'contact_number' => 'sometimes|string|max:20',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $validatedData['password'] = Hash::make($validatedData['password']);

        if ($request->hasFile('profile_image')) {
            $path = $request->file('profile_image')->store('profile_images', 'public');
            $validatedData['profile_image'] = Storage::url($path);
        }

        $admin = User::create($validatedData);

        $this->activityLogService->log(
            $request->user(),
            'created',
            'Admin',
            $admin->id,
            null,
            $admin->toArray(),
            $request
        );

        return response()->json($admin->load('userFiles'), 201);
    }

    public function show(Request $request, $id)
    {
        $admin = User::with('userFiles')->whereIn('role', ['admin', 'super_admin'])->findOrFail($id);
        $this->authorize('viewAdmin', $admin);

        return response()->json($admin);
    }

    public function update(Request $request, $id)
    {
        $admin = User::whereIn('role', ['admin', 'super_admin'])->findOrFail($id);
        $this->authorize('updateAdmin', $admin);

        $oldValues = $admin->toArray();

        $validatedData = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => "sometimes|required|email|unique:users,email,{$id}",
            'password' => 'sometimes|nullable|string|min:8',
            'role' => ['sometimes', 'required', Rule::in(['admin', 'super_admin'])],
            'address' => 'sometimes|string',
            'gender' => 'sometimes|in:male,female,other',
            'religion' => 'sometimes|string|max:255',
            'contact_number' => 'sometimes|string|max:20',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($request->filled('password')) {
            $validatedData['password'] = Hash::make($validatedData['password']);
        }

        if ($request->hasFile('profile_image')) {
            // Delete old image if exists
            if ($admin->profile_image) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $admin->profile_image));
            }

            $path = $request->file('profile_image')->store('profile_images', 'public');
            $validatedData['profile_image'] = Storage::url($path);
        }

        $admin->update($validatedData);
        $admin->refresh();
        $newValues = $admin->toArray();

        $this->activityLogService->log(
            $request->user(),
            'updated',
            'Admin',
            $admin->id,
            $oldValues,
            $newValues,
            $request
        );

        return response()->json($admin->load('userFiles'));
    }

    public function destroy(Request $request, $id)
    {
        $admin = User::with('userFiles')->whereIn('role', ['admin', 'super_admin'])->findOrFail($id);
        $this->authorize('deleteAdmin', $admin);

        $oldValues = $admin->toArray();

        if ($admin->profile_image) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $admin->profile_image));
        }
        foreach ($admin->userFiles as $file) {
            Storage::disk('public')->delete($file->file_path);
        }

        $admin->delete();

        $this->activityLogService->log(
            $request->user(),
            'deleted',
            'Admin',
            $admin->id,
            $oldValues,
            null,
            $request
        );

        return response()->json(['message' => 'Admin deleted successfully']);
    }
}
