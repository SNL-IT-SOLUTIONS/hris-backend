<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeTrainingAttempt extends Model
{
    protected $fillable = [
        'employee_id',
        'module_id',
        'score',
        'passed',
        'completed_at'
    ];

    public function answers()
    {
        return $this->hasMany(EmployeeTrainingAnswer::class, 'attempt_id');
    }

    public function module()
    {
        return $this->belongsTo(TrainingModule::class, 'module_id');
    }
}
