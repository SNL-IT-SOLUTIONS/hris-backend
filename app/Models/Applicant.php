<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Applicant extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'resume',
        'cover_letter',
        'linkedin_profile',
        'portfolio_website',
        'salary_expectations',
        'available_start_date',
        'experience_years',
        'rating',
        'stage',
        'resume',
        'status',
        'is_archived',
    ];
    public function jobPosting()
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id'); // make sure foreign key is correct
    }
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id'); // correct foreign key
    }
}
