<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BenefitType extends Model
{
    use HasFactory;

    protected $fillable = [
        'benefit_name',
        'category',
        'description',
        'deduction',
        'is_active',
    ];
    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_benefit', 'benefit_type_id', 'employee_id');
    }
}
