<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model; // If you're using Auth, extend Authenticatable
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        // Basic user info
        'first_name',
        'last_name',
        'middle_initial',
        'email',
        'profile_image',
        'role',
        'password',
        'is_active',

        // Additional registration info
        'title',
        'mobile_number',

        // Address / church
        'home_address',
        'church_name',
        'church_address',

        // Status / classification
        'working_or_student',

        // Vocation
        'vocation_work_sphere',

        // Payment
        'mode_of_payment',
        'proof_of_payment_path',
        'proof_of_payment_url',
        'notes',

        // Misc
        'group_id',
        'reference_number',

        // Status flags
        'reconciled',
        'finance_checked',
        'email_confirmed',
        'attendance',
        'id_issued',
        'book_given',

    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'reconciled' => 'boolean',
        'finance_checked' => 'boolean',
        'email_confirmed' => 'boolean',
        'attendance' => 'boolean',
        'id_issued' => 'boolean',
        'book_given' => 'boolean',
        'needs_review' => 'boolean',
    ];

    /**
     * Temporary storage for event ID to check attendance
     */
    protected ?int $checkEventId = null;

    /* =========================
     | Relationships
     * ========================= */
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
        return $this->belongsToMany(Event::class, 'event_user');
    }

    public function spheres()
    {
        // Pivot table renamed to user_sphere
        return $this->belongsToMany(Sphere::class, 'user_sphere');
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    /* =========================
     | Query Scopes (optional)
     * ========================= */
    public function scopeNeedsReview($q)
    {
        return $q->where('needs_review', true);
    }

    public function scopeDuplicateBuckets($q)
    {
        // Buckets where same normalized first+last appear > 1
        return $q->select('name_key')
            ->whereNotNull('name_key')
            ->groupBy('name_key')
            ->havingRaw('COUNT(*) > 1');
    }

    /* =========================
     | Helpers
     * ========================= */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Check if user is an attendee of a specific event
     *
     * @return bool
     */
    public function getIsEventAttendeeAttribute(): bool
    {
        if ($this->checkEventId === null) {
            return false;
        }

        return $this->events()->where('events.id', $this->checkEventId)->exists();
    }

    /**
     * Set the event ID to check attendance for
     *
     * @param int|null $eventId
     * @return self
     */
    public function setCheckEventId(?int $eventId): self
    {
        $this->checkEventId = $eventId;

        // Dynamically add the attribute to appends if event ID is set
        if ($eventId !== null && !in_array('is_event_attendee', $this->appends)) {
            $this->appends[] = 'is_event_attendee';
        }

        return $this;
    }

    public function isSuperAdmin()
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }
}
