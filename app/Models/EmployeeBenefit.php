<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeBenefit extends Model
{
    use HasFactory;

    protected $table = 'employee_benefit';

    protected $fillable = [
        'employee_id',
        'benefit_type_id',
        'amount',
    ];

    public function benefit()
    {
        return $this->belongsTo(BenefitType::class, 'benefit_type_id');
    }
}
