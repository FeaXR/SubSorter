<?php

use App\Services\PlaylistSync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('youtube:refresh-subs', function (PlaylistSync $sync) {
    $result = $sync->refreshSubscriptions();

    $this->info("Loaded {$result['total']} subscriptions.");
    foreach ($result['new'] as $title) {
        $this->line("  + {$title}");
    }
    foreach ($result['dropped'] as $title) {
        $this->line("  - {$title}");
    }
})->purpose('Reload your YouTube subscriptions');

Artisan::command('youtube:refresh-playlists', function (PlaylistSync $sync) {
    $result = $sync->refreshPlaylists();

    $this->info("Loaded {$result['total']} playlists.");
    foreach ($result['removed'] as $title) {
        $this->line("  - {$title} (channels using it now use the default)");
    }
})->purpose('Reload the playlists on your channel');

Artisan::command('youtube:sync {--refresh-subs : Reload subscriptions and playlists first} {--add : Add the found videos to the playlists without review}', function (PlaylistSync $sync) {
    if ($this->option('refresh-subs')) {
        $this->call('youtube:refresh-subs');
        $this->call('youtube:refresh-playlists');
    }

    $result = $sync->loadNewVideos(fn (int $done, int $total) => $this->output->write("\rChecked {$done}/{$total} channels"));
    $this->newLine();

    if ($result['first_run']) {
        $this->info('First run: tracking new videos from now on.');

        return;
    }

    $this->info("Checked {$result['channels']} channels, found {$result['videos']} new videos.");

    if (! $this->option('add')) {
        $this->line('Review and accept them in the web UI, or rerun with --add.');

        return;
    }

    $result = $sync->addPendingVideos();
    $this->info("Added {$result['added']} videos, {$result['failed']} failed.");

    if ($result['stopped']) {
        $this->warn("Stopped early: {$result['stopped']}");
    }
})->purpose('Find new videos from your subscriptions and optionally add them to your playlists');

if ($cron = config('youtube.auto_sync_cron')) {
    Schedule::command('youtube:sync --refresh-subs --add')->cron($cron)->withoutOverlapping();
}
