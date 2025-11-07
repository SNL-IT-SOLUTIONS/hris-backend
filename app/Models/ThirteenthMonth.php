<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ThirteenthMonth extends Model
{
    use HasFactory;
    protected $table = 'thirteenth_month_pays'; // 👈 fix here

    protected $fillable = [
        'employee_id',
        'start_date',
        'end_date',
        'amount',
        'remarks',
    ];

    /**
     * Relationship: each 13th month belongs to an employee.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Optional: Compute 13th month amount dynamically
     * (if you ever want to calculate on the fly).
     */
    public static function calculateForPeriod($employeeId, $startDate, $endDate)
    {
        // Example logic: total basic salary earned ÷ 12
        $totalSalary = PayrollRecord::where('employee_id', $employeeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('gross_base');

        return round($totalSalary / 12, 2);
    }
}
