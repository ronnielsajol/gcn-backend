<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class UserFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'uploaded_by',
    ];

    public function getFileUrlAttribute()
    {
        return Storage::url($this->file_path);
    }

    protected $appends = ['file_url'];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
