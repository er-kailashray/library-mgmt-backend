<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = [
        'library_id',
        'member_id',
        'batch_id',
        'date',
        'status',
        'check_in_at',
    ];

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }
}
