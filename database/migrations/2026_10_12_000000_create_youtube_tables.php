<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('channel_id')->unique();
            $table->string('title');
            $table->timestamps();
        });

        // Channels routed to the secondary playlist of the original two-playlist
        // setup; replaced by per-channel targets in 2026_10_13_000000
        Schema::create('mc_channels', function (Blueprint $table) {
            $table->id();
            $table->string('channel_id')->unique();
            $table->timestamps();
        });

        Schema::create('pending_videos', function (Blueprint $table) {
            $table->id();
            $table->string('video_id')->unique();
            $table->string('title');
            $table->string('channel_id');
            $table->string('channel_title');
            $table->timestamp('published_at');
            $table->string('target'); // replaced by playlist_id in 2026_10_13_000000
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_videos');
        Schema::dropIfExists('mc_channels');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('settings');
    }
};
