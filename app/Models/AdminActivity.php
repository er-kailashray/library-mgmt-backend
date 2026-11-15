<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

    /** The change in plain words, for the admin panel's activity lists: [text, optional note]. */
    public function describe(): array
    {
        $d = $this->details ?? [];
        $date = fn ($value) => $value ? Carbon::parse($value)->format('j M Y') : '?';

        switch ($this->action) {
            case 'subscription.adjusted':
                $verb = ($d['days'] ?? 0) > 0 ? 'Extended' : 'Shortened';
                $what = ($d['state_before'] ?? '') === 'trial' ? 'trial' : 'subscription';

                return [
                    'text' => "{$verb} {$what} by ".abs($d['days'] ?? 0).' days: '.$date($d['from'] ?? null).' → '.$date($d['to'] ?? null),
                    'note' => $d['reason'] ?? null,
                ];
            case 'settings.trial_days':
                return ['text' => "Free trial for new libraries: {$d['from']} → {$d['to']} days", 'note' => null];
            case 'plan.updated':
                $changes = [];
                foreach (['name' => 'name', 'price' => 'price', 'is_active' => 'available'] as $field => $word) {
                    $before = $d['before'][$field] ?? null;
                    $after = $d['after'][$field] ?? null;
                    if ($before != $after) {
                        $show = fn ($v) => $field === 'is_active' ? ($v ? 'yes' : 'no') : $v;
                        $changes[] = "{$word} {$show($before)} → {$show($after)}";
                    }
                }

                return ['text' => ucfirst($d['plan'] ?? 'Plan').' plan: '.(implode('; ', $changes) ?: 'saved'), 'note' => null];
            default:
                return ['text' => $this->action, 'note' => null];
        }
    }

    /** One row for the admin panel's activity tables. */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'when' => $this->created_at->format('j M Y, g:i A'),
            'who' => $this->user?->name ?? 'Deleted admin',
            'library' => $this->library ? ['id' => $this->library->id, 'name' => $this->library->library_name] : null,
            ...$this->describe(),
        ];
    }
}
