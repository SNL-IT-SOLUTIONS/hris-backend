<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnnouncementBoard extends Model
{
    use HasFactory;

    protected $table = 'announcement_board';

    protected $fillable = [
        'title',
        'content',
        'posted_by',
        'is_active',
        'publish_at',
        'expire_at',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'is_active' => 'boolean',
        'publish_at' => 'datetime',
        'expire_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
