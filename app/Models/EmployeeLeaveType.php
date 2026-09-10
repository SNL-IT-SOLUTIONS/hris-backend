<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeLeaveType extends Model
{
    use HasFactory;

    protected $table = 'employee_leave_types';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'allocated_days',
        'used_days',
        'remaining_days',
        'is_active',
        'is_archived',
    ];

    protected $casts = [
        'allocated_days' => 'decimal:2',
        'used_days' => 'decimal:2',
        'remaining_days' => 'decimal:2',
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
    ];

    // ================================
    // Employee Relationship
    // ================================

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // ================================
    // Leave Type Relationship
    // ================================

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function employeeLeaveTypes()
    {
        return $this->hasMany(EmployeeLeaveType::class, 'leave_type_id');
    }
}
