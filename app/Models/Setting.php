<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Simple JSON key/value store for app state (last check time, default playlist, OAuth token).
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::query()->whereKey($key)->value('value');

        return $value === null ? $default : json_decode($value, true);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    public static function forget(string $key): void
    {
        static::query()->whereKey($key)->delete();
    }
}
