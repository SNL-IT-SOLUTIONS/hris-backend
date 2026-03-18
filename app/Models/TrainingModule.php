<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingModule extends Model
{
    protected $fillable = [
        'title',
        'description'
    ];

    public function questions()
    {
        return $this->hasMany(TrainingQuestion::class, 'module_id');
    }

    public function lesson()
    {
        return $this->belongsTo(TrainingLesson::class, 'lesson_id');
    }
}
