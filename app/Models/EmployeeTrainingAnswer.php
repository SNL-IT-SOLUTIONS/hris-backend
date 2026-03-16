<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeTrainingAnswer extends Model
{
    protected $fillable = [
        'attempt_id',
        'question_id',
        'choice_id',
        'is_correct'
    ];
}
