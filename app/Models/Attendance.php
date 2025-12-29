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
        'hours_worked',
        'status',
        'remarks',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function calculateHoursWorked()
    {
        if ($this->clock_in && $this->clock_out) {
            $in = Carbon::parse($this->clock_in);
            $out = Carbon::parse($this->clock_out);
            $this->hours_worked = $out->diffInMinutes($in) / 60;
        }
    }
}
