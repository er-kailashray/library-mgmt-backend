<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change made in the admin panel: who, what, to which library, and the before/after. */
class AdminActivity extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'library_id',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    /** Records a change made by the signed-in admin. */
    public static function record(string $action, ?Library $library = null, array $details = []): self
    {
        return self::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'library_id' => $library?->id,
            'details' => $details,
        ]);
    }
}
