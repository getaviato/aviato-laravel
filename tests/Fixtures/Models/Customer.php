<?php

namespace Aviato\Laravel\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Customer extends Model
{
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'owner_id', 'uuid');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Invoice::class, 'commentable');
    }
}
