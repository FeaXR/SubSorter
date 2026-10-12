<?php

namespace App\Http\Controllers;

use App\Services\YouTubeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class GoogleAuthController extends Controller
{
    public function __construct(private YouTubeClient $youtube) {}

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->youtube->isConfigured()) {
            return to_route('dashboard')->with('error', 'Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in your .env file first.');
        }

        $state = Str::random(40);
        $request->session()->put('oauth_state', $state);

        return redirect()->away($this->youtube->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('oauth_state');

        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return to_route('dashboard')->with('error', 'Invalid login state, please try again.');
        }

        if ($request->filled('error')) {
            return to_route('dashboard')->with('error', 'Google login failed: '.$request->query('error'));
        }

        try {
            $this->youtube->exchangeCode((string) $request->query('code'));
            $channel = $this->youtube->channelTitle();
        } catch (Throwable $e) {
            report($e);

            return to_route('dashboard')->with('error', 'Google login failed: '.$e->getMessage());
        }

        return to_route('dashboard')->with('status', 'Logged in'.($channel ? " as {$channel}" : '').'.');
    }

    public function logout(): RedirectResponse
    {
        $this->youtube->disconnect();

        return to_route('dashboard')->with('status', 'Disconnected from YouTube.');
    }
}
