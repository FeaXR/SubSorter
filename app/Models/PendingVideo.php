<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A new video found by "Load videos", waiting to be accepted into its playlist.
 */
class PendingVideo extends Model
{
    protected $fillable = ['video_id', 'title', 'channel_id', 'channel_title', 'published_at', 'playlist_id', 'error'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'playlist_id', 'playlist_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'channel_id', 'channel_id');
    }

    /**
     * Grouped by playlist, oldest first within each playlist.
     */
    public function scopeInAddOrder(Builder $query): void
    {
        $query->orderBy('playlist_id')->orderBy('published_at');
    }
}
