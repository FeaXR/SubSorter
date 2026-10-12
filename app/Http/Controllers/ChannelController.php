<?php

namespace App\Http\Controllers;

use App\Models\PendingVideo;
use App\Models\Playlist;
use App\Models\Subscription;
use App\Services\PlaylistSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Choose the target playlist for each subscribed channel.
 */
class ChannelController extends Controller
{
    public function __construct(private PlaylistSync $sync) {}

    public function index(): View
    {
        return view('channels', [
            'subscriptions' => Subscription::orderByRaw('lower(title)')->get(),
            'playlists' => Playlist::orderByRaw('lower(title)')->get(),
            'defaultPlaylistId' => $this->sync->defaultPlaylistId(),
            'hues' => Playlist::hues(),
        ]);
    }

    public function update(Request $request, Subscription $subscription): JsonResponse|RedirectResponse
    {
        // Each field is optional so the target and the Videos / Shorts toggles can be saved independently
        $data = $request->validate([
            'target' => ['sometimes', 'nullable', 'string', Rule::in(['skip', ...Playlist::pluck('playlist_id')])],
            'include_videos' => ['sometimes', 'boolean'],
            'include_shorts' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('target', $data)) {
            $this->sync->setChannelTarget($subscription, $data['target']);
        }

        if ($content = array_intersect_key($data, array_flip(['include_videos', 'include_shorts']))) {
            $this->sync->setChannelContent($subscription, $content);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'pending' => PendingVideo::where('channel_id', $subscription->channel_id)->count(),
            ]);
        }

        return back()->with('status', "Updated {$subscription->title}.");
    }

    public function updateDefault(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'default_playlist_id' => ['nullable', 'string', Rule::exists('playlists', 'playlist_id')],
        ]);

        $this->sync->setDefaultPlaylistId($data['default_playlist_id'] ?? null);

        return back()->with('status', 'Default playlist updated.');
    }
}
