<?php

namespace App\Services;

use App\Models\PendingVideo;
use App\Models\Playlist;
use App\Models\Setting;
use App\Models\Subscription;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The use case of the app: find videos uploaded by subscribed channels since
 * the last run and add each one to the playlist chosen for its channel.
 */
class PlaylistSync
{
    public const LAST_REQUEST = 'last_request_at';

    /** Newest publish time among the pending videos; becomes LAST_REQUEST on accept. */
    public const PENDING_UNTIL = 'pending_until';

    /** Playlist for channels without their own target. */
    public const DEFAULT_PLAYLIST = 'default_playlist_id';

    public function __construct(private YouTubeClient $youtube) {}

    public function lastRequestAt(): ?Carbon
    {
        $value = Setting::get(self::LAST_REQUEST);

        return $value ? Carbon::parse($value) : null;
    }

    public function setLastRequestAt(Carbon $time): void
    {
        Setting::put(self::LAST_REQUEST, $time->utc()->toIso8601String());
    }

    public function defaultPlaylistId(): ?string
    {
        return Setting::get(self::DEFAULT_PLAYLIST);
    }

    public function setDefaultPlaylistId(?string $playlistId): void
    {
        $playlistId ? Setting::put(self::DEFAULT_PLAYLIST, $playlistId) : Setting::forget(self::DEFAULT_PLAYLIST);

        $this->retargetPendingVideos(Subscription::whereNull('target_playlist_id')->where('skip', false)->get());
    }

    /**
     * Reload the subscription list from YouTube, keeping each channel's target.
     *
     * @return array{total: int, new: list<string>, dropped: list<string>}
     */
    public function refreshSubscriptions(): array
    {
        $current = collect($this->youtube->subscriptions())->keyBy('channel_id');
        $stored = Subscription::pluck('title', 'channel_id');

        $new = $current->diffKeys($stored)->pluck('title')->values()->all();
        $dropped = $stored->diffKeys($current)->values()->all();

        DB::transaction(function () use ($current) {
            $now = now();
            // Only used for newly found channels, existing ones keep their own setting
            $includeShorts = (bool) config('youtube.include_shorts');

            foreach ($current->chunk(200) as $chunk) {
                Subscription::upsert(
                    $chunk->map(fn (array $sub) => $sub + ['include_shorts' => $includeShorts, 'created_at' => $now, 'updated_at' => $now])->values()->all(),
                    ['channel_id'],
                    ['title', 'thumbnail_url', 'updated_at'],
                );
            }

            Subscription::whereNotIn('channel_id', $current->keys())->delete();
        });

        return ['total' => $current->count(), 'new' => $new, 'dropped' => $dropped];
    }

    /**
     * Reload your own playlists from YouTube. Channels targeting a playlist
     * that no longer exists fall back to the default playlist.
     *
     * @return array{total: int, removed: list<string>}
     */
    public function refreshPlaylists(): array
    {
        $current = collect($this->youtube->playlists())->keyBy('playlist_id');
        $removed = Playlist::whereNotIn('playlist_id', $current->keys())->pluck('title')->all();

        DB::transaction(function () use ($current) {
            $now = now();

            if ($current->isNotEmpty()) {
                Playlist::upsert(
                    $current->map(fn (array $p) => $p + ['created_at' => $now, 'updated_at' => $now])->values()->all(),
                    ['playlist_id'],
                    ['title', 'item_count', 'updated_at'],
                );
            }

            Playlist::whereNotIn('playlist_id', $current->keys())->delete();
            Subscription::whereNotNull('target_playlist_id')
                ->whereNotIn('target_playlist_id', $current->keys())
                ->update(['target_playlist_id' => null]);

            if ($this->defaultPlaylistId() && ! $current->has($this->defaultPlaylistId())) {
                Setting::forget(self::DEFAULT_PLAYLIST);
            }
        });

        return ['total' => $current->count(), 'removed' => $removed];
    }

    /**
     * @param  string|null  $target  a playlist ID, "skip", or null for the default playlist
     */
    public function setChannelTarget(Subscription $subscription, ?string $target): void
    {
        $subscription->update([
            'skip' => $target === 'skip',
            'target_playlist_id' => $target === 'skip' ? null : $target,
        ]);

        $this->retargetPendingVideos(collect([$subscription]));
    }

    /**
     * Choose which uploads of a channel are picked up.
     *
     * @param  array{include_videos?: bool, include_shorts?: bool}  $options
     */
    public function setChannelContent(Subscription $subscription, array $options): void
    {
        $subscription->update($options);

        // Unticking both means the channel isn't checked anymore
        $this->retargetPendingVideos(collect([$subscription]));
    }

    /**
     * Keep already loaded videos in line with changed channel targets.
     *
     * @param  iterable<Subscription>  $subscriptions
     */
    private function retargetPendingVideos(iterable $subscriptions): void
    {
        $default = $this->defaultPlaylistId();

        foreach ($subscriptions as $subscription) {
            $pending = PendingVideo::where('channel_id', $subscription->channel_id);
            $playlistId = $subscription->effectivePlaylistId($default);

            $playlistId ? $pending->update(['playlist_id' => $playlistId, 'error' => null]) : $pending->delete();
        }
    }

    /**
     * Fetch videos published since the last request into the pending list.
     * Skipped channels (and channels without any target) are not checked.
     *
     * @return array{videos: int, channels: int, first_run: bool}
     */
    public function loadNewVideos(?callable $progress = null): array
    {
        $since = $this->lastRequestAt();

        if ($since === null) {
            // First run: start tracking from now instead of importing the whole back catalogue
            $this->setLastRequestAt(now());

            return ['videos' => 0, 'channels' => 0, 'first_run' => true];
        }

        $default = $this->defaultPlaylistId();
        $active = Subscription::all()->filter(fn (Subscription $s) => $s->effectivePlaylistId($default) !== null);
        $targets = $active->mapWithKeys(fn (Subscription $s) => [$s->channel_id => $s->effectivePlaylistId($default)]);
        $uploadFeeds = $active->map(fn (Subscription $s) => $s->uploadsPlaylist())->values()->all();

        $videos = $this->youtube->videosPublishedBetween($uploadFeeds, $since, now(), $progress);

        $count = 0;
        $pendingUntil = ($stored = Setting::get(self::PENDING_UNTIL)) ? Carbon::parse($stored) : $since;

        foreach ($videos as $video) {
            if (! $playlistId = $targets->get($video['channel_id'])) {
                continue;
            }

            PendingVideo::updateOrCreate(
                ['video_id' => $video['video_id']],
                ['playlist_id' => $playlistId, 'error' => null] + $video,
            );

            $count++;
            $pendingUntil = $pendingUntil->max($video['published_at']);
        }

        Setting::put(self::PENDING_UNTIL, $pendingUntil->utc()->toIso8601String());

        return ['videos' => $count, 'channels' => $targets->count(), 'first_run' => false];
    }

    /**
     * Add all pending videos to their playlists. Videos that fail stay pending
     * (with the error message) so they can be retried.
     *
     * @return array{added: int, failed: int, stopped: ?string}
     */
    public function addPendingVideos(?callable $progress = null): array
    {
        $videos = PendingVideo::inAddOrder()->get();
        $result = ['added' => 0, 'failed' => 0, 'stopped' => null];

        foreach ($videos as $i => $video) {
            try {
                $this->youtube->addToPlaylist($video->playlist_id, $video->video_id);
                $video->delete();
                $result['added']++;
            } catch (RequestException $e) {
                [$message, $reason] = YouTubeClient::describeError($e);
                $video->update(['error' => $message]);
                $result['failed']++;

                if (in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'dailyLimitExceeded'], true)) {
                    $result['stopped'] = $message;
                    break;
                }
            }

            if ($progress) {
                $progress($i + 1, $videos->count());
            }
        }

        // Everything up to the newest loaded video has now been handled
        if ($until = Setting::get(self::PENDING_UNTIL)) {
            $this->setLastRequestAt(Carbon::parse($until));
            Setting::forget(self::PENDING_UNTIL);
        }

        return $result;
    }

    public function discardPendingVideos(): void
    {
        PendingVideo::query()->delete();
        Setting::forget(self::PENDING_UNTIL);
    }
}
