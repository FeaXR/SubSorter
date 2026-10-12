<?php

namespace Tests\Feature;

use App\Models\PendingVideo;
use App\Models\Playlist;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\PlaylistSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaylistSyncTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL_A = 'UCaaaaaaaaaaaaaaaaaaaaaa';

    private const CHANNEL_B = 'UCbbbbbbbbbbbbbbbbbbbbbb';

    private const CHANNEL_C = 'UCcccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();

        config(['youtube.client_id' => 'id', 'youtube.client_secret' => 'secret']);

        Setting::put('google_token', Crypt::encrypt([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]));

        Carbon::setTestNow('2026-10-12 12:00:00');
    }

    private function sync(): PlaylistSync
    {
        return app(PlaylistSync::class);
    }

    private function item(string $videoId, string $channelId, string $publishedAt): array
    {
        return ['snippet' => [
            'publishedAt' => $publishedAt,
            'title' => "Video {$videoId}",
            'channelId' => $channelId,
            'channelTitle' => "Channel {$channelId}",
            'videoOwnerChannelId' => $channelId,
            'videoOwnerChannelTitle' => "Channel {$channelId}",
            'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
        ]];
    }

    private function uploads(string $channelId): string
    {
        return '*playlistId='.Subscription::uploadsPlaylistId($channelId).'*';
    }

    /**
     * A -> own playlist PL_A, B -> default PL_DEFAULT, C -> skipped.
     */
    private function setUpChannels(): void
    {
        Playlist::create(['playlist_id' => 'PL_A', 'title' => 'Playlist A']);
        Playlist::create(['playlist_id' => 'PL_DEFAULT', 'title' => 'Default']);
        Subscription::create(['channel_id' => self::CHANNEL_A, 'title' => 'A', 'target_playlist_id' => 'PL_A']);
        Subscription::create(['channel_id' => self::CHANNEL_B, 'title' => 'B']);
        Subscription::create(['channel_id' => self::CHANNEL_C, 'title' => 'C', 'skip' => true]);
        $this->sync()->setDefaultPlaylistId('PL_DEFAULT');
        $this->sync()->setLastRequestAt(Carbon::parse('2026-10-10 00:00:00'));
    }

    public function test_refresh_subscriptions_keeps_targets_and_reports_changes(): void
    {
        Subscription::create(['channel_id' => self::CHANNEL_A, 'title' => 'A old name', 'target_playlist_id' => 'PL_A']);
        Subscription::create(['channel_id' => self::CHANNEL_C, 'title' => 'Old one']);
        config(['youtube.include_shorts' => true]); // default for new channels only

        Http::fake([
            '*/subscriptions*' => Http::sequence()
                ->push(['items' => [['snippet' => [
                    'title' => 'A',
                    'resourceId' => ['channelId' => self::CHANNEL_A],
                    'thumbnails' => ['default' => ['url' => 'https://yt3.ggpht.com/a.jpg']],
                ]]], 'nextPageToken' => 'p2'])
                ->push(['items' => [['snippet' => ['title' => 'B', 'resourceId' => ['channelId' => self::CHANNEL_B]]]]]),
        ]);

        $result = $this->sync()->refreshSubscriptions();

        $this->assertSame(2, $result['total']);
        $this->assertSame(['B'], $result['new']);
        $this->assertSame(['Old one'], $result['dropped']);
        $this->assertEqualsCanonicalizing([self::CHANNEL_A, self::CHANNEL_B], Subscription::pluck('channel_id')->all());

        $a = Subscription::firstWhere('channel_id', self::CHANNEL_A);
        $this->assertSame('A', $a->title);
        $this->assertSame('PL_A', $a->target_playlist_id);
        $this->assertSame('https://yt3.ggpht.com/a.jpg', $a->thumbnail_url);
        $this->assertNull(Subscription::firstWhere('channel_id', self::CHANNEL_B)->thumbnail_url);
        $this->assertFalse($a->include_shorts);
        $this->assertTrue(Subscription::firstWhere('channel_id', self::CHANNEL_B)->include_shorts);
    }

    public function test_each_videos_shorts_combination_reads_the_matching_uploads_feed(): void
    {
        $this->setUpChannels();
        Subscription::where('channel_id', self::CHANNEL_B)->update(['include_shorts' => true]); // videos + shorts
        Subscription::where('channel_id', self::CHANNEL_C)->update(['skip' => false, 'include_videos' => false, 'include_shorts' => true]); // shorts only
        Subscription::create(['channel_id' => 'UCdddddddddddddddddddddd', 'title' => 'D', 'include_videos' => false]); // nothing
        Http::fake(['*playlistItems*' => Http::response(['items' => []])]);

        $result = $this->sync()->loadNewVideos();

        $this->assertSame(3, $result['channels']);
        $requested = Http::recorded()->map(fn ($pair) => $pair[0]->data()['playlistId'] ?? null)->filter()->sort()->values()->all();
        $this->assertSame([
            'UULFaaaaaaaaaaaaaaaaaaaaaa', // A: videos only (default)
            'UUSHcccccccccccccccccccccc', // C: shorts only
            'UUbbbbbbbbbbbbbbbbbbbbbb',   // B: videos + shorts
        ], $requested);
    }

    public function test_content_toggles_are_saved_without_touching_the_target(): void
    {
        $this->setUpChannels();
        $a = Subscription::firstWhere('channel_id', self::CHANNEL_A);
        $this->assertTrue($a->include_videos);

        $this->patchJson(route('channels.update', $a), ['include_shorts' => true])->assertOk();
        $this->patchJson(route('channels.update', $a), ['include_videos' => false])->assertOk();

        $a->refresh();
        $this->assertFalse($a->include_videos);
        $this->assertTrue($a->include_shorts);
        $this->assertSame('PL_A', $a->target_playlist_id);
    }

    public function test_unticking_videos_and_shorts_drops_the_channels_pending_videos(): void
    {
        $this->setUpChannels();
        $a = Subscription::firstWhere('channel_id', self::CHANNEL_A);
        PendingVideo::create([
            'video_id' => 'a1', 'title' => 'a1', 'channel_id' => self::CHANNEL_A, 'channel_title' => 'A',
            'published_at' => now(), 'playlist_id' => 'PL_A',
        ]);

        $this->patchJson(route('channels.update', $a), ['include_videos' => false])->assertOk();

        $this->assertSame(0, PendingVideo::count());
        $this->assertNull($a->fresh()->effectivePlaylistId('PL_DEFAULT'));
    }

    public function test_refresh_playlists_resets_targets_of_removed_playlists(): void
    {
        Playlist::create(['playlist_id' => 'PL_GONE', 'title' => 'Gone']);
        Subscription::create(['channel_id' => self::CHANNEL_A, 'title' => 'A', 'target_playlist_id' => 'PL_GONE']);
        $this->sync()->setDefaultPlaylistId('PL_GONE');

        Http::fake([
            '*/playlists*' => Http::response(['items' => [
                ['id' => 'PL_NEW', 'snippet' => ['title' => 'New'], 'contentDetails' => ['itemCount' => 5]],
            ]]),
        ]);

        $result = $this->sync()->refreshPlaylists();

        $this->assertSame(['total' => 1, 'removed' => ['Gone']], $result);
        $this->assertSame(['PL_NEW'], Playlist::pluck('playlist_id')->all());
        $this->assertNull(Subscription::first()->target_playlist_id);
        $this->assertNull($this->sync()->defaultPlaylistId());
    }

    public function test_loads_videos_into_each_channels_playlist_and_skips_ignored_channels(): void
    {
        $this->setUpChannels();

        Http::fake([
            $this->uploads(self::CHANNEL_A) => Http::response(['items' => [
                $this->item('a2', self::CHANNEL_A, '2026-10-11T10:00:00Z'),
                $this->item('a1', self::CHANNEL_A, '2026-10-10T10:00:00Z'),
                $this->item('old', self::CHANNEL_A, '2026-10-09T10:00:00Z'),
            ], 'nextPageToken' => 'should-not-be-followed']),
            $this->uploads(self::CHANNEL_B) => Http::response(['items' => [
                $this->item('b1', self::CHANNEL_B, '2026-10-11T20:00:00Z'),
            ]]),
        ]);

        $result = $this->sync()->loadNewVideos();

        $this->assertSame(['videos' => 3, 'channels' => 2, 'first_run' => false], $result);
        $this->assertSame(
            ['a1' => 'PL_A', 'a2' => 'PL_A', 'b1' => 'PL_DEFAULT'],
            PendingVideo::orderBy('video_id')->pluck('playlist_id', 'video_id')->all(),
        );
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'should-not-be-followed'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), Subscription::uploadsPlaylistId(self::CHANNEL_C)));
    }

    public function test_follows_pages_when_all_items_are_new(): void
    {
        $this->setUpChannels();
        Subscription::where('channel_id', '!=', self::CHANNEL_B)->update(['skip' => true]);

        Http::fake([
            '*pageToken=p2*' => Http::response(['items' => [
                $this->item('b', self::CHANNEL_B, '2026-10-10T12:00:00Z'),
                $this->item('old', self::CHANNEL_B, '2026-10-01T12:00:00Z'),
            ]]),
            '*playlistItems*' => Http::response(['items' => [
                $this->item('a', self::CHANNEL_B, '2026-10-11T12:00:00Z'),
            ], 'nextPageToken' => 'p2']),
        ]);

        $this->sync()->loadNewVideos();

        $this->assertEqualsCanonicalizing(['a', 'b'], PendingVideo::pluck('video_id')->all());
    }

    public function test_changing_a_channel_target_updates_its_pending_videos(): void
    {
        $this->setUpChannels();
        $b = Subscription::firstWhere('channel_id', self::CHANNEL_B);
        PendingVideo::create([
            'video_id' => 'b1', 'title' => 'b1', 'channel_id' => self::CHANNEL_B, 'channel_title' => 'B',
            'published_at' => now(), 'playlist_id' => 'PL_DEFAULT',
        ]);

        $this->sync()->setChannelTarget($b, 'PL_A');
        $this->assertSame('PL_A', PendingVideo::first()->playlist_id);

        $this->sync()->setChannelTarget($b->fresh(), 'skip');
        $this->assertSame(0, PendingVideo::count());
        $this->assertTrue($b->fresh()->skip);
    }

    public function test_accept_adds_each_video_to_its_playlist_and_advances_last_request(): void
    {
        $this->setUpChannels();

        Http::fake([
            $this->uploads(self::CHANNEL_A) => Http::response(['items' => [
                $this->item('a2', self::CHANNEL_A, '2026-10-11T10:00:00Z'),
                $this->item('a1', self::CHANNEL_A, '2026-10-10T10:00:00Z'),
            ]]),
            $this->uploads(self::CHANNEL_B) => Http::response(['items' => [
                $this->item('b1', self::CHANNEL_B, '2026-10-11T20:00:00Z'),
            ]]),
            'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet' => Http::response([]),
        ]);

        $this->sync()->loadNewVideos();
        $result = $this->sync()->addPendingVideos();

        $this->assertSame(['added' => 3, 'failed' => 0, 'stopped' => null], $result);
        $this->assertSame(0, PendingVideo::count());
        $this->assertTrue($this->sync()->lastRequestAt()->equalTo(Carbon::parse('2026-10-11T20:00:00Z')));

        $inserted = Http::recorded(fn (Request $r) => $r->method() === 'POST')
            ->map(fn ($pair) => [$pair[0]['snippet']['playlistId'], $pair[0]['snippet']['resourceId']['videoId']])
            ->values()
            ->all();
        $this->assertSame([['PL_A', 'a1'], ['PL_A', 'a2'], ['PL_DEFAULT', 'b1']], $inserted);
    }

    public function test_quota_error_stops_and_keeps_remaining_videos(): void
    {
        foreach (['v1', 'v2'] as $i => $id) {
            PendingVideo::create([
                'video_id' => $id, 'title' => $id, 'channel_id' => self::CHANNEL_A, 'channel_title' => 'x',
                'published_at' => now()->subHours(2 - $i), 'playlist_id' => 'PL_A',
            ]);
        }

        Http::fake([
            '*playlistItems*' => Http::response(['error' => [
                'message' => 'The request cannot be completed because you have exceeded your quota.',
                'errors' => [['reason' => 'quotaExceeded']],
            ]], 403),
        ]);

        $result = $this->sync()->addPendingVideos();

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['failed']);
        $this->assertNotNull($result['stopped']);
        $this->assertSame(2, PendingVideo::count());
        $this->assertStringContainsString('quota', PendingVideo::firstWhere('video_id', 'v1')->error);
        Http::assertSentCount(1);
    }

    public function test_pages_render(): void
    {
        $this->setUpChannels();
        Subscription::where('channel_id', self::CHANNEL_A)->update(['thumbnail_url' => 'https://yt3.ggpht.com/a.jpg']);
        PendingVideo::create([
            'video_id' => 'a1', 'title' => 'Pending one', 'channel_id' => self::CHANNEL_A, 'channel_title' => 'A',
            'published_at' => now(), 'playlist_id' => 'PL_A',
        ]);

        $this->get('/')->assertOk()->assertSee('Pending one')->assertSee('https://yt3.ggpht.com/a.jpg')
            ->assertSee('https://i.ytimg.com/vi/a1/mqdefault.jpg')
            ->assertSee('Load new videos')->assertSee('Load new videos')->assertSee('Connected to YouTube');
        $this->get('/channels')->assertOk()->assertSee('Playlist A')
            ->assertSee('https://yt3.ggpht.com/a.jpg')
            ->assertSee('<span class="avatar placeholder" aria-hidden="true">B</span>', false)->assertSee('Skip – don’t check', false);
    }

    public function test_channel_target_can_be_updated_via_json(): void
    {
        $this->setUpChannels();
        $b = Subscription::firstWhere('channel_id', self::CHANNEL_B);

        $this->patchJson(route('channels.update', $b), ['target' => 'PL_A'])->assertOk();
        $this->assertSame('PL_A', $b->fresh()->target_playlist_id);

        $this->patchJson(route('channels.update', $b), ['target' => 'PL_UNKNOWN'])->assertUnprocessable();
        $this->patchJson(route('channels.update', $b), ['target' => null])->assertOk();
        $this->assertNull($b->fresh()->target_playlist_id);
    }
}
