<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollDeduction extends Model
{
    use HasFactory;

    protected $table = 'payroll_deductions';

    protected $fillable = [
        'payroll_record_id',
        'benefit_type_id',
        'deduction_name',
        'deduction_rate',
        'deduction_amount',
    ];

    public function payrollRecord()
    {
        return $this->belongsTo(PayrollRecord::class, 'payroll_record_id');
    }

    public function benefitType()
    {
        return $this->belongsTo(BenefitType::class, 'benefit_type_id');
    }
}
