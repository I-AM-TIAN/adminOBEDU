<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Publication extends Model
{
    protected $fillable = [
        'title',
        'abstract',
        'content',
        'publication_date',
        'author_id',
        'publication_type_id',
        // 'image_id', // opcional: quitar si ya no lo usas
    ];

    public function author()
    {
        return $this->belongsTo(Author::class);
    }

    public function publicationType()
    {
        return $this->belongsTo(PublicationType::class);
    }

    public function images()
    {
        return $this->hasMany(\App\Models\Image::class);
    }
}
