<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'clock_in',
        'clock_out',
        'adjusted_clock_in',
        'adjusted_clock_out',
        'adjustment_reason',
        'adjustment_status',
        'adjusted_by',
        'hours_worked',
        'status',
        'remarks',
        'clock_in_image',
        'clock_out_image',
        'method',
    ];

    // Relationships
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // Recursive relationship if needed (e.g., attendance adjustments linked to original)
    public function adjustments()
    {
        return $this->hasMany(Attendance::class, 'original_attendance_id');
    }

    // Calculate worked hours using clock_in and clock_out, fallback to adjusted times if available
    public function calculateHoursWorked()
    {
        $in  = $this->adjusted_clock_in ?? $this->clock_in;
        $out = $this->adjusted_clock_out ?? $this->clock_out;

        if ($in && $out) {
            $this->hours_worked = Carbon::parse($out)->diffInMinutes(Carbon::parse($in)) / 60;
        }
    }
}
