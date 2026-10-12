<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the YouTube Data API v3 and Google OAuth 2.0 endpoints.
 */
class YouTubeClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    private const SCOPE = 'https://www.googleapis.com/auth/youtube';

    private const TOKEN_SETTING = 'google_token';

    // ---------------------------------------------------------------------
    // OAuth
    // ---------------------------------------------------------------------

    public function isConfigured(): bool
    {
        return filled(config('youtube.client_id')) && filled(config('youtube.client_secret'));
    }

    public function isConnected(): bool
    {
        return $this->storedToken() !== null;
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('youtube.client_id'),
            'redirect_uri' => config('youtube.redirect_uri'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            // offline + consent so Google always hands out a refresh token
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): void
    {
        $token = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('youtube.client_id'),
            'client_secret' => config('youtube.client_secret'),
            'redirect_uri' => config('youtube.redirect_uri'),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Google did not return a refresh token. Remove the app at https://myaccount.google.com/permissions and log in again.');
        }

        $this->storeToken($token);
    }

    public function disconnect(): void
    {
        $token = $this->storedToken();

        if ($token !== null) {
            // Best effort, the local token is removed either way
            rescue(fn () => Http::asForm()->post(self::REVOKE_URL, ['token' => $token['refresh_token']]), report: false);
        }

        Setting::forget(self::TOKEN_SETTING);
    }

    private function storedToken(): ?array
    {
        $encrypted = Setting::get(self::TOKEN_SETTING);

        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decrypt($encrypted);
        } catch (DecryptException) {
            // APP_KEY changed, the token is unusable
            return null;
        }
    }

    private function storeToken(array $response): void
    {
        $previous = $this->storedToken();

        Setting::put(self::TOKEN_SETTING, Crypt::encrypt([
            'access_token' => $response['access_token'],
            // Refresh responses don't include a new refresh token
            'refresh_token' => $response['refresh_token'] ?? $previous['refresh_token'],
            'expires_at' => now()->addSeconds(($response['expires_in'] ?? 3600) - 60)->toIso8601String(),
        ]));
    }

    private function accessToken(): string
    {
        $token = $this->storedToken() ?? throw new NotConnectedException;

        if (Carbon::parse($token['expires_at'])->isFuture()) {
            return $token['access_token'];
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('youtube.client_id'),
            'client_secret' => config('youtube.client_secret'),
            'refresh_token' => $token['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        if ($response->json('error') === 'invalid_grant') {
            // Revoked, or expired (refresh tokens of apps in "Testing" mode expire after 7 days)
            Setting::forget(self::TOKEN_SETTING);
            throw new NotConnectedException('Your Google login expired or was revoked. Please log in again.');
        }

        $this->storeToken($response->throw()->json());

        return $this->storedToken()['access_token'];
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl(self::API_URL)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30);
    }

    // ---------------------------------------------------------------------
    // YouTube Data API
    // ---------------------------------------------------------------------

    public function channelTitle(): ?string
    {
        return $this->api()
            ->get('channels', ['part' => 'snippet', 'mine' => 'true'])
            ->throw()
            ->json('items.0.snippet.title');
    }

    /**
     * @return list<array{channel_id: string, title: string, thumbnail_url: ?string}>
     */
    public function subscriptions(): array
    {
        $subscriptions = [];
        $pageToken = null;

        do {
            $page = $this->api()->get('subscriptions', array_filter([
                'part' => 'snippet',
                'mine' => 'true',
                'maxResults' => 50,
                'order' => 'alphabetical',
                'pageToken' => $pageToken,
            ]))->throw()->json();

            foreach ($page['items'] ?? [] as $item) {
                $subscriptions[] = [
                    'channel_id' => $item['snippet']['resourceId']['channelId'],
                    'title' => $item['snippet']['title'],
                    // 88x88 channel avatar
                    'thumbnail_url' => $item['snippet']['thumbnails']['default']['url'] ?? null,
                ];
            }

            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken);

        return $subscriptions;
    }

    /**
     * Playlists on your own channel.
     *
     * @return list<array{playlist_id: string, title: string, item_count: int}>
     */
    public function playlists(): array
    {
        $playlists = [];
        $pageToken = null;

        do {
            $page = $this->api()->get('playlists', array_filter([
                'part' => 'snippet,contentDetails',
                'mine' => 'true',
                'maxResults' => 50,
                'pageToken' => $pageToken,
            ]))->throw()->json();

            foreach ($page['items'] ?? [] as $item) {
                $playlists[] = [
                    'playlist_id' => $item['id'],
                    'title' => $item['snippet']['title'],
                    'item_count' => (int) ($item['contentDetails']['itemCount'] ?? 0),
                ];
            }

            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken);

        return $playlists;
    }

    /**
     * Videos added to any of the given playlists after $after and up to $until.
     *
     * Upload playlists are ordered newest first, so paging stops at the first
     * item that is older than $after.
     *
     * @param  list<string>  $playlistIds
     * @return list<array{video_id: string, title: string, channel_id: string, channel_title: string, published_at: Carbon}>
     */
    public function videosPublishedBetween(array $playlistIds, Carbon $after, Carbon $until, ?callable $progress = null): array
    {
        $videos = [];
        $done = 0;
        $token = $this->accessToken();
        $query = ['part' => 'snippet', 'maxResults' => 50];

        foreach (array_chunk($playlistIds, max(1, config('youtube.concurrency'))) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $playlistId) => $pool->as($playlistId)
                    ->baseUrl(self::API_URL)
                    ->withToken($token)
                    ->acceptJson()
                    ->timeout(30)
                    ->get('playlistItems', $query + ['playlistId' => $playlistId]),
                $chunk,
            ));

            foreach ($responses as $playlistId => $response) {
                // 404 = the channel has no uploads of that kind; other failures are skipped so one bad channel doesn't stop the run
                $page = $response instanceof Response && $response->successful() ? $response->json() : null;

                while ($page !== null) {
                    $reachedOlder = false;

                    foreach ($page['items'] ?? [] as $item) {
                        $snippet = $item['snippet'];
                        $publishedAt = Carbon::parse($snippet['publishedAt']);

                        if ($publishedAt->lessThanOrEqualTo($after)) {
                            $reachedOlder = true;
                            break;
                        }

                        if ($publishedAt->lessThanOrEqualTo($until)) {
                            $videos[] = [
                                'video_id' => $snippet['resourceId']['videoId'],
                                'title' => $snippet['title'],
                                'channel_id' => $snippet['videoOwnerChannelId'] ?? $snippet['channelId'],
                                'channel_title' => $snippet['videoOwnerChannelTitle'] ?? $snippet['channelTitle'],
                                'published_at' => $publishedAt,
                            ];
                        }
                    }

                    $page = ! $reachedOlder && ! empty($page['nextPageToken'])
                        ? $this->api()->get('playlistItems', $query + ['playlistId' => $playlistId, 'pageToken' => $page['nextPageToken']])->json()
                        : null;
                }

                $done++;
            }

            if ($progress) {
                $progress($done, count($playlistIds));
            }
        }

        return $videos;
    }

    public function addToPlaylist(string $playlistId, string $videoId): void
    {
        $this->api()
            ->withQueryParameters(['part' => 'snippet'])
            ->post('playlistItems', [
                'snippet' => [
                    'playlistId' => $playlistId,
                    'resourceId' => [
                        'kind' => 'youtube#video',
                        'videoId' => $videoId,
                    ],
                ],
            ])
            ->throw();
    }

    /**
     * Human readable message + machine reason from a failed API request.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function describeError(RequestException $e): array
    {
        $message = $e->response->json('error.message') ?? $e->getMessage();
        $reason = $e->response->json('error.errors.0.reason');

        return [strip_tags((string) $message), $reason];
    }
}
