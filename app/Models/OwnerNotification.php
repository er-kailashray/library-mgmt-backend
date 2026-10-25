<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One entry in the owner app's Alerts inbox. */
class OwnerNotification extends Model
{
    public const TYPE_RENEWALS_DUE = 'renewals_due';

    public const TYPE_DUES_SUMMARY = 'dues_summary';

    public const TYPE_SUBSCRIPTION = 'subscription';

    protected $fillable = [
        'user_id',
        'library_id',
        'type',
        'title',
        'body',
        'url',
        'dedupe_key',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    /** @return array<string, mixed> the shape GET /notifications returns */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
