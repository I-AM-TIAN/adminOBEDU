<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected $fillable = [
        'publication_id',
        'file_id',
        'url',
        'provider',
        'width',
        'height',
        'size',
        'mime',
        'alt',
        'caption',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function publication()
    {
        return $this->belongsTo(Publication::class);
    }
}
