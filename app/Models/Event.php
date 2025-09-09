<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'location',
        'status',
        'start_time',
        'end_time',
        'created_by',
    ];

    protected $hidden = [
        'pivot'
    ];
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',

    ];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
