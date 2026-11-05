<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WikiEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'infobox' => 'array',
        'sections' => 'array',
        'categories' => 'array',
        'images' => 'array',
        'scraped_at' => 'datetime',
    ];
}
