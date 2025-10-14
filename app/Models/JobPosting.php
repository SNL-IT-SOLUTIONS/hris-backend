<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobPosting extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'department_id',
        'location',
        'salary_range',
        'description',
        'status',
        'posted_date',
        'deadline_date',
    ];

    // Relationships
    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
