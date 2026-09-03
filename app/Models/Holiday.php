<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'holiday_date',
        'holiday_name',
        'holiday_type_id',
        'country',
        'is_archived',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_archived' => 'boolean',
    ];

    public function holidayType()
    {
        return $this->belongsTo(HolidayType::class, 'holiday_type_id');
    }
}
