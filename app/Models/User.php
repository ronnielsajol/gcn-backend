<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'address',
        'gender',
        'religion',
        'contact_number',
        'email',
        'profile_image',
        'role',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pivot'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function userFiles()
    {
        return $this->hasMany(UserFile::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class, 'admin_id');
    }

    public function events()
    {
        return $this->belongsToMany(Event::class);
    }

    public function isSuperAdmin()
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }
    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
