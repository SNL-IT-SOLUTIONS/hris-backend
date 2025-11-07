<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollRecord extends Model
{
    use HasFactory;

    protected $table = 'payroll_records';

    protected $fillable = [
        'payroll_period_id',
        'employee_id',
        'daily_rate',
        'days_worked',
        'overtime_hours',
        'absences',
        'gross_base',
        'gross_pay',
        'total_deductions',
        'net_pay',
    ];

    // Relationships
    public function payrollPeriod()
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function deductions()
    {
        return $this->hasMany(PayrollDeduction::class, 'payroll_record_id');
    }

    public function allowances()
    {
        return $this->hasMany(PayrollAllowance::class, 'payroll_record_id')->with('allowanceType');
    }
}
