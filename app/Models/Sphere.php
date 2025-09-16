<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sphere extends Model
{
  use HasFactory;

  protected $fillable = [
    'name',
    'slug',
  ];

  /**
   * Get the users that belong to this sphere.
   */
  public function users()
  {
    return $this->belongsToMany(User::class, 'user_sphere');
  }
}
