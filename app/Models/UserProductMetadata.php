<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProductMetadata extends Model
{
    protected $table = 'user_product_metadata';
    protected $guarded = [];

    // This automatically converts the JSON column to a PHP array and back
    protected $casts = [
        'attributes' => 'array',
    ];
}