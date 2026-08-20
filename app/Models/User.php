<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject; // Assuming JWT-Auth

class User extends Authenticatable implements JWTSubject
{
    protected $fillable = ['mobile_number', 'name', 'email', 'emp_code', 'status'];

    public function productAccess(): HasMany
    {
        return $this->hasMany(UserProductAccess::class);
    }

    public function productMetadata(): HasMany
    {
        return $this->hasMany(UserProductMetadata::class);
    }

    public function getJWTIdentifier() { return $this->getKey(); }
    public function getJWTCustomClaims() { return []; }
}