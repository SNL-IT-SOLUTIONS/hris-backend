<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    use HasFactory;

    protected $table = 'payroll_periods';

    protected $fillable = [
        'period_name',
        'pay_date',
        'cutoff_start_date',
        'cutoff_end_date',
    ];

    // Relationships
    public function payrollRecords()
    {
        return $this->hasMany(PayrollRecord::class, 'payroll_period_id');
    }
}
