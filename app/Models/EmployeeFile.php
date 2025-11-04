<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'file_path',
        'file_name',
        'file_type',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }
    public function benefits()
    {
        return $this->belongsToMany(BenefitType::class, 'employee_benefit', 'employee_id', 'benefit_type_id')
            ->withTimestamps();
    }
}
