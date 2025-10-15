<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InterviewFeedback extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_id',
        'overall_rating',
        'technical_skills',
        'communication',
        'cultural_fit',
        'problem_solving',
        'experience_level',
        'recommendation',
        'key_strengths',
        'areas_for_improvement',
        'detailed_notes',
    ];

    public function interview()
    {
        return $this->belongsTo(Interview::class);
    }
}
