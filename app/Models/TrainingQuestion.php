<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingQuestion extends Model
{
    protected $fillable = [
        'module_id',
        'question'
    ];

    public function module()
    {
        return $this->belongsTo(TrainingModule::class, 'module_id');
    }

    public function choices()
    {
        return $this->hasMany(TrainingChoice::class, 'question_id');
    }
}
