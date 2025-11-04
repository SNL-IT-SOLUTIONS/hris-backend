<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollAllowance extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_record_id',
        'allowance_name',
        'allowance_amount',
    ];

    // Relationship: belongs to Payroll Record
    public function payrollRecord()
    {
        return $this->belongsTo(PayrollRecord::class, 'payroll_record_id');
    }
}
