<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    protected $fillable = [
        'name',
        'email',
        'message',
        'status',
        'resolved_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];
}
