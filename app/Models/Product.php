<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'slug',
        'redirect_url',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}