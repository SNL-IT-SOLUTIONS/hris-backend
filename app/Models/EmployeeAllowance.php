<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeAllowance extends Model
{
    use HasFactory;

    protected $table = 'employee_allowance';

    protected $fillable = [
        'employee_id',
        'allowance_type_id',
        'amount',
    ];

    public function allowance()
    {
        return $this->belongsTo(AllowanceType::class, 'allowance_type_id');
    }
}
