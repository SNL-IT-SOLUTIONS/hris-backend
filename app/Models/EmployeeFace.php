<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeFace extends Model
{
    use HasFactory;

    // The table this model is associated with
    protected $table = 'employee_faces';

    // Fillable fields for mass assignment
    protected $fillable = [
        'employee_id',
        'face_image_path',
        'face_encoding', // optional, if you store the encoding
    ];

    // Optional: relationships
    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
