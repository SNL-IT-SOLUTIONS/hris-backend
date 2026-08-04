<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnouncementView extends Model
{
    protected $fillable = [
        'announcement_id',
        'employee_id',
        'seen_at',
    ];
    public function announcement()
    {
        return $this->belongsTo(AnnouncementBoard::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
