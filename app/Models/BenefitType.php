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
        'rate',
        'is_active',
    ];
}
