<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A playlist on your own channel that videos can be added to.
 */
class Playlist extends Model
{
    protected $fillable = ['playlist_id', 'title', 'item_count'];

    /**
     * A color hue (0-359) per playlist ID, spread with the golden angle so
     * neighbours look distinct. Based on the order playlists were first
     * loaded, so existing colors stay the same when new playlists appear.
     *
     * @return Collection<string, int>
     */
    public static function hues(): Collection
    {
        return static::orderBy('id')->pluck('playlist_id')->values()
            ->mapWithKeys(fn (string $id, int $i) => [$id => (int) round(fmod($i * 137.508 + 210, 360))]);
    }
}
