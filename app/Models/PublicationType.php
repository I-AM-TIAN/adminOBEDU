<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicationType extends Model
{
    protected $fillable = ['name', 'description'];

    public function publications()
    {
        return $this->hasMany(Publication::class);
    }
}
