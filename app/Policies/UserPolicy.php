<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || in_array($user->role, ['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->id === $model->id || in_array($user->role, ['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return in_array($user->role, ['admin', 'super_admin']) && $user->id !== $model->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view any admins.
     */
    public function viewAnyAdmins(User $user): bool
    {
        // Only super admins and admins can view admin list
        return $user->isAdmin() || $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can view a specific admin.
     */
    public function viewAdmin(User $user, User $admin): bool
    {
        // Only super admins can view admin details
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can create admins.
     */
    public function createAdmin(User $user): bool
    {
        // Only super admins can create admins
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can update the admin.
     */
    public function updateAdmin(User $user, User $admin): bool
    {
        // Only super admins can update admins
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the admin.
     */
    public function deleteAdmin(User $user, User $admin): bool
    {

        return $user->isSuperAdmin() && $user->id !== $admin->id;
    }
}
