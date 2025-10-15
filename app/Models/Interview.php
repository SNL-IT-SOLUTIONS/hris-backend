<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Interview extends Model
{
    use HasFactory;

    protected $fillable = [
        'applicant_id',
        'interviewer',
        'mode',
        'scheduled_at',
        'notes',
        'status',
    ];

    // Relationship to Applicant
    public function applicant()
    {
        return $this->belongsTo(Applicant::class);
    }
}
