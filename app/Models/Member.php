<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'library_id',
        'name',
        'phone',
        'join_date',
        'status',
        'id_proof_type',
        'id_proof_front_path',
        'id_proof_back_path',
    ];

    // Internal storage paths - never meant to reach the client directly, which
    // uses the authenticated GET /members/{id}/id-proof/{side} endpoint instead.
    protected $hidden = [
        'id_proof_front_path',
        'id_proof_back_path',
    ];

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function allocations()
    {
        return $this->hasMany(SeatAllocation::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
