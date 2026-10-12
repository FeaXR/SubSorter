<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['channel_id', 'title', 'thumbnail_url', 'target_playlist_id', 'skip', 'include_videos', 'include_shorts'];

    protected $attributes = [
        'include_videos' => true,
        'include_shorts' => false,
    ];

    protected function casts(): array
    {
        return [
            'skip' => 'boolean',
            'include_videos' => 'boolean',
            'include_shorts' => 'boolean',
        ];
    }

    public function targetPlaylist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'target_playlist_id', 'playlist_id');
    }

    /**
     * The playlist new videos of this channel go to, or null if the channel
     * is not checked (skipped, nothing selected, or no default playlist).
     */
    public function effectivePlaylistId(?string $defaultPlaylistId): ?string
    {
        if ($this->skip || $this->uploadsPlaylist() === null) {
            return null;
        }

        return $this->target_playlist_id ?? $defaultPlaylistId;
    }

    /**
     * The channel's uploads feed matching its Videos / Shorts selection.
     */
    public function uploadsPlaylist(): ?string
    {
        return static::uploadsPlaylistId($this->channel_id, $this->include_videos, $this->include_shorts);
    }

    /**
     * Every channel has auto-generated uploads playlists: its channel ID with
     * "UC" swapped for "UU" (all uploads), "UULF" (long-form videos only) or
     * "UUSH" (Shorts only).
     */
    public static function uploadsPlaylistId(string $channelId, bool $includeVideos = true, bool $includeShorts = false): ?string
    {
        $prefix = match (true) {
            $includeVideos && $includeShorts => 'UU',
            $includeVideos => 'UULF',
            $includeShorts => 'UUSH',
            default => null,
        };

        return $prefix ? $prefix.substr($channelId, 2) : null;
    }
}
