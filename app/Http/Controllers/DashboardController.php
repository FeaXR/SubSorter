<?php

namespace App\Http\Controllers;

use App\Models\PendingVideo;
use App\Models\Playlist;
use App\Models\Subscription;
use App\Services\NotConnectedException;
use App\Services\PlaylistSync;
use App\Services\YouTubeClient;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private YouTubeClient $youtube,
        private PlaylistSync $sync,
    ) {}

    public function index(): View
    {
        $default = $this->sync->defaultPlaylistId();
        $subscriptions = Subscription::get(['channel_id', 'target_playlist_id', 'skip', 'include_videos', 'include_shorts']);

        return view('dashboard', [
            'configured' => $this->youtube->isConfigured(),
            'connected' => $this->youtube->isConnected(),
            'subscriptionCount' => $subscriptions->count(),
            'activeChannelCount' => $subscriptions->filter(fn (Subscription $s) => $s->effectivePlaylistId($default))->count(),
            'playlistCount' => Playlist::count(),
            'defaultPlaylist' => $default ? Playlist::firstWhere('playlist_id', $default) : null,
            'lastRequestAt' => $this->sync->lastRequestAt(),
            'pendingByPlaylist' => PendingVideo::with(['playlist', 'subscription'])->inAddOrder()->get()->groupBy('playlist_id'),
            'hues' => Playlist::hues(),
            'tz' => config('youtube.display_timezone'),
        ]);
    }

    public function refreshSubscriptions(): RedirectResponse
    {
        return $this->attempt(function () {
            $result = $this->sync->refreshSubscriptions();

            $message = "Loaded {$result['total']} subscriptions.";
            if ($result['new']) {
                $message .= ' New: '.implode(', ', $result['new']).'.';
            }
            if ($result['dropped']) {
                $message .= ' Dropped: '.implode(', ', $result['dropped']).'.';
            }

            return $message;
        });
    }

    public function refreshPlaylists(): RedirectResponse
    {
        return $this->attempt(function () {
            $result = $this->sync->refreshPlaylists();

            $message = "Loaded {$result['total']} playlists.";
            if ($result['removed']) {
                $message .= ' Removed (channels using them now use the default): '.implode(', ', $result['removed']).'.';
            }

            return $message;
        });
    }

    public function loadVideos(): RedirectResponse
    {
        set_time_limit(300);

        return $this->attempt(function () {
            if (Subscription::count() === 0) {
                return 'No subscriptions loaded yet, click "Refresh subscriptions" first.';
            }

            $result = $this->sync->loadNewVideos();

            if ($result['first_run']) {
                return 'First run: tracking new videos from now on. Set the "last check" time below to look further back.';
            }

            if ($result['channels'] === 0) {
                return 'No channel has a target playlist. Choose a default playlist or assign playlists on the Channels page.';
            }

            return "Checked {$result['channels']} channels, found {$result['videos']} new videos.";
        });
    }

    public function accept(): RedirectResponse
    {
        set_time_limit(600);

        return $this->attempt(function () {
            $result = $this->sync->addPendingVideos();

            $message = "Added {$result['added']} videos.";
            if ($result['failed']) {
                $message .= " {$result['failed']} failed and are still in the list.";
            }
            if ($result['stopped']) {
                $message .= " Stopped early: {$result['stopped']}";
            }

            return $message;
        });
    }

    public function discard(): RedirectResponse
    {
        $this->sync->discardPendingVideos();

        return back()->with('status', 'Pending list cleared. The last check time was not changed.');
    }

    public function removePending(PendingVideo $pendingVideo): RedirectResponse
    {
        $pendingVideo->delete();

        return back();
    }

    public function setLastRequest(Request $request): RedirectResponse
    {
        $data = $request->validate(['last_request_at' => ['required', 'date']]);

        $this->sync->setLastRequestAt(Carbon::parse($data['last_request_at'], config('youtube.display_timezone')));

        return back()->with('status', 'Last check time updated.');
    }

    /**
     * Run a YouTube action and turn its result or failure into a flash message.
     */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return back()->with('status', $action());
        } catch (NotConnectedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (RequestException $e) {
            report($e);

            return back()->with('error', 'YouTube API error: '.YouTubeClient::describeError($e)[0]);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }
    }
}
