<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class Employee extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        '201_file',
        'first_name',
        'last_name',
        'email',
        'phone',
        'department_id',
        'position_id',
        'base_salary',
        'hire_date',
        'supervisor_id',
        'manager_id',
        'resume',
        'password',
        'is_active',
        'is_archived',
    ];

    protected $hidden = ['password']; // hide password from JSON responses

    protected $casts = [
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
        'hire_date' => 'date',
    ];

    // Relationships
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function position()
    {
        return $this->belongsTo(PositionType::class, 'position_id');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }
}
