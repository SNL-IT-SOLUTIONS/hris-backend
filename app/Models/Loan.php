<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'loan_type_id',
        'principal_amount',
        'balance_amount',
        'monthly_amortization',
        'interest_rate',
        'start_date',
        'end_date',
        'status',
        'remarks',
    ];

    public function loanType()
    {
        return $this->belongsTo(LoanType::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
