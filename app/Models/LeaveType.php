<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use HasFactory;

    protected $table = 'leave_types';

    protected $fillable = [
        'leave_name',
        'description',
        'max_days',
        'is_paid',
        'is_active',
        'is_archived',
    ];

    protected $casts = [
        'is_paid'     => 'boolean',
        'is_active'   => 'boolean',
        'is_archived' => 'boolean',
    ];
}
