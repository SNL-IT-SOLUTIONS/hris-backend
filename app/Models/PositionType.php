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
        'is_active',
        'is_archived',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
    ];
}
