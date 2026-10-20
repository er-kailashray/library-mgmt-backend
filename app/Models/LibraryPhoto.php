<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibraryPhoto extends Model
{
    protected $fillable = [
        'library_id',
        'path',
    ];

    public function library()
    {
        return $this->belongsTo(Library::class);
    }
}
