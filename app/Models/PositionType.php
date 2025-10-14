<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PositionType extends Model
{
    use HasFactory;

    protected $table = 'position_types';

    protected $fillable = [
        'position_name',
        'description',
        'department_id',
        'is_active',
        'is_archived',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
    ];

    // PositionType.php
    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
