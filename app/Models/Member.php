<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'library_id',
        'name',
        'phone',
        'gender',
        'address',
        'join_date',
        'status',
        'outstanding_balance',
        'id_proof_type',
        'id_proof_front_path',
        'id_proof_back_path',
    ];

    protected $casts = [
        'outstanding_balance' => 'float',
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

    /**
     * The day they joined: the recorded joining date, or for members added before that was saved,
     * the start of their first seat (else the day they were added).
     */
    public function joinedOn(): ?string
    {
        $date = $this->join_date
            ?? $this->allocations()->min('start_date')
            ?? $this->created_at?->toDateString();

        return $date ? substr((string) $date, 0, 10) : null;
    }
}
