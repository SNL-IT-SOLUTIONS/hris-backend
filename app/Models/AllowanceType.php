<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AllowanceType extends Model
{
    use HasFactory;

    protected $fillable = [
        'type_name',
        'description',
    ];

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_allowance', 'allowance_type_id', 'employee_id')
            ->withTimestamps();
    }
}
