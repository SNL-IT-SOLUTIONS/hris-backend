<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollDeduction extends Model
{
    use HasFactory;

    protected $table = 'payroll_deductions';

    protected $fillable = [
        'payroll_id',
        'benefit_type_id',
        'deduction_amount',
    ];

    public function payrollRecord()
    {
        return $this->belongsTo(PayrollRecord::class, 'payroll_id');
    }

    public function benefitType()
    {
        return $this->belongsTo(BenefitType::class, 'benefit_type_id');
    }
}
