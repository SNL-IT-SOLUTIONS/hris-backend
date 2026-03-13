<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeModuleProgress extends Model
{
    protected $fillable = [
        'employee_id',
        'module_id',
        'completed',
        'completed_at'
    ];
}
