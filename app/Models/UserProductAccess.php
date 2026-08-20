<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProductAccess extends Model
{
    // Explicitly tell Laravel the exact table name
    protected $table = 'user_product_access';
    
    // Allow the seeder to insert data
    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
