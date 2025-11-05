<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanType extends Model
{
    use HasFactory;

    protected $fillable = [
        'type_name',
        'description',
        'amount',
        'amount_limit',
        'is_archived',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'amount' => 'decimal:2',
        'amount_limit' => 'decimal:2',
    ];
}
