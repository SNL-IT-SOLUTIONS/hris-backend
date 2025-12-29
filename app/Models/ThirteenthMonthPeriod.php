<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThirteenthMonthPeriod extends Model
{
    protected $fillable = [
        'period_name',
        'start_date',
        'end_date',
        'is_locked'
    ];

    public function pays()
    {
        return $this->hasMany(ThirteenthMonth::class, 'period_id');
    }
}
