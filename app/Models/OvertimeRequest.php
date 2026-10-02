<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OvertimeRequest extends Model
{
    use HasFactory;

    protected $table = 'overtime_requests';

    protected $fillable = [
        'employee_id',
        'overtime_date',
        'start_time',
        'end_time',
        'total_hours',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'is_archived',
    ];

    protected $casts = [
        'overtime_date' => 'date',
        'total_hours'   => 'decimal:2',
        'approved_at'   => 'datetime',
        'is_archived'   => 'boolean',
    ];

    // Employee who requested the overtime
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // Employee/admin who approved the overtime
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
