<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HolidayType extends Model
{
    use HasFactory;

    protected $fillable = [
        'type_name',
        'description',
        'is_archived',
        'rate',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
    ];

    public function holidays()
    {
        return $this->hasMany(Holiday::class, 'holiday_type_id');
    }
}
