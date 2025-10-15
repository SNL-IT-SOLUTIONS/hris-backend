<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Interview extends Model
{
    use HasFactory;

    protected $fillable = [
        'applicant_id',
        'interviewer_id',
        'mode',
        'scheduled_at',
        'notes',
        'status',
        'stage',
        'position',
        'location_link',
    ];

    protected $attributes = [
        'status' => 'scheduled',
    ];

    /**
     * Interview belongs to an applicant
     */
    public function applicant()
    {
        return $this->belongsTo(Applicant::class);
    }

    /**
     * Interviewer relationship (to Employee)
     */
    public function interviewer()
    {
        return $this->belongsTo(Employee::class, 'interviewer_id');
    }

    public function feedback()
    {
        return $this->hasOne(InterviewFeedback::class);
    }
}
