<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A setting changed from the admin panel instead of a deploy. See App\Services\Settings\AppSettings. */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];
}
