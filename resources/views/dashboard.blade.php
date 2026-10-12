@extends('layout')

@section('styles')
    ul.videos { list-style: none; margin: 0 0 16px; padding: 0; }
    ul.videos li { display: flex; gap: 12px; align-items: center; padding: 8px 0; border-top: 1px solid var(--border); }
    ul.videos .thumb { flex: none; display: block; width: 160px; aspect-ratio: 16 / 9; border-radius: 6px; overflow: hidden; background: var(--border); }
    ul.videos .thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    ul.videos .info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
    ul.videos .video-title { font-weight: 500; text-decoration: none; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    ul.videos .video-title:hover { text-decoration: underline; }
    ul.videos .meta { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--muted); }
    ul.videos .meta .avatar { width: 20px; height: 20px; font-size: 11px; }
    @media (max-width: 520px) { ul.videos .thumb { width: 112px; } }
@endsection

@section('content')
    {{-- Login --}}
    <section class="card">
        <div class="row">
            @if (! $configured)
                <span>Set <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code> in <code>.env</code> to enable login.</span>
            @elseif ($connected)
                <span>✅ Connected to YouTube</span>
                <span class="spacer"></span>
                <form method="post" action="{{ route('auth.logout') }}">@csrf<button>Disconnect</button></form>
            @else
                <span>Not connected.</span>
                <span class="spacer"></span>
                <a class="button primary" href="{{ route('auth.google') }}">Log in with Google</a>
            @endif
        </div>
    </section>

    {{-- Subscriptions, playlists, last check --}}
    <section class="card">
        <div class="row">
            <form method="post" action="{{ route('subscriptions.refresh') }}" data-busy="Loading subscriptions…">
                @csrf<button @disabled(! $connected)>Refresh subscriptions</button>
            </form>
            <span><span class="stat">{{ $subscriptionCount }}</span> <span class="muted">subscriptions,</span>
                <span class="stat">{{ $activeChannelCount }}</span> <span class="muted">with a target playlist</span></span>
        </div>
        <div class="row">
            <form method="post" action="{{ route('playlists.refresh') }}" data-busy="Loading playlists…">
                @csrf<button @disabled(! $connected)>Refresh playlists</button>
            </form>
            <span><span class="stat">{{ $playlistCount }}</span> <span class="muted">playlists, default:</span>
                <span class="stat">{{ $defaultPlaylist?->title ?? 'none' }}</span></span>
            <a href="{{ route('channels.index') }}">Manage channels →</a>
        </div>
        <div class="row">
            <form method="post" action="{{ route('videos.load') }}" data-busy="Checking channels for new videos…">
                @csrf<button class="primary" @disabled(! $connected)>Load new videos</button>
            </form>
            <span class="muted">Last check:</span>
            <span class="stat">{{ $lastRequestAt?->setTimezone($tz)->format('Y-m-d H:i') ?? 'never' }}</span>
        </div>
        <details class="row" style="margin-top: 12px">
            <summary class="muted">Change last check time</summary>
            <form method="post" action="{{ route('last-request.update') }}" class="row" style="margin-top: 8px">
                @csrf
                <input type="datetime-local" name="last_request_at" required
                       value="{{ ($lastRequestAt ?? now())->setTimezone($tz)->format('Y-m-d\TH:i') }}">
                <span class="muted">{{ $tz }}</span>
                <button>Save</button>
            </form>
        </details>
    </section>

    {{-- Pending videos, grouped by target playlist --}}
    @if ($pendingByPlaylist->isNotEmpty())
        <section class="card">
            @foreach ($pendingByPlaylist as $playlistId => $videos)
                <h2 class="row" style="gap: 8px">
                    @if ($hues->has($playlistId))<span class="dot" style="--h: {{ $hues[$playlistId] }}"></span>@endif
                    {{ $videos->count() }} → {{ $videos->first()->playlist?->title ?? $playlistId }}
                </h2>
                <ul class="videos">
                    @foreach ($videos as $video)
                        <li>
                            {{-- Thumbnail straight from YouTube's image CDN, no API call needed --}}
                            <a class="thumb" href="https://www.youtube.com/watch?v={{ $video->video_id }}" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
                                <img src="https://i.ytimg.com/vi/{{ $video->video_id }}/mqdefault.jpg" alt="" loading="lazy" referrerpolicy="no-referrer">
                            </a>
                            <span class="info">
                                <a class="video-title" href="https://www.youtube.com/watch?v={{ $video->video_id }}" target="_blank" rel="noopener">{{ $video->title }}</a>
                                <span class="meta">
                                    <x-avatar :url="$video->subscription?->thumbnail_url" :title="$video->channel_title" />
                                    {{ $video->channel_title }} · {{ $video->published_at->setTimezone($tz)->format('m-d H:i') }}
                                </span>
                                @if ($video->error)<span class="error">{{ $video->error }}</span>@endif
                            </span>
                            <form method="post" action="{{ route('videos.remove', $video) }}">
                                @csrf @method('delete')<button class="link" title="Skip this video">✕</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endforeach
            <div class="row">
                <form method="post" action="{{ route('videos.accept') }}" data-busy="Adding videos to your playlists…">
                    @csrf<button class="primary">Accept – add to playlists</button>
                </form>
                <form method="post" action="{{ route('videos.discard') }}">
                    @csrf<button>Discard</button>
                </form>
            </div>
        </section>
    @endif
@endsection
