<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrainingLesson extends Model
{
    use HasFactory;

    protected $table = 'training_lessons';

    protected $fillable = [
        'lesson_title',
        'lesson_description'
    ];

    public function modules()
    {
        return $this->hasMany(TrainingModule::class, 'lesson_id');
    }
}
