<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the two fixed target playlists with a target playlist per channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->string('playlist_id')->unique();
            $table->string('title');
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('target_playlist_id')->nullable(); // null = default playlist
            $table->boolean('skip')->default(false);
        });

        // Pending videos are re-fetched on the next load (the last check time only moves on accept)
        DB::table('pending_videos')->delete();
        DB::table('settings')->where('key', 'pending_until')->delete();

        Schema::table('pending_videos', function (Blueprint $table) {
            $table->dropColumn('target');
        });
        Schema::table('pending_videos', function (Blueprint $table) {
            $table->string('playlist_id')->default('');
        });

        Schema::dropIfExists('mc_channels');
    }

    public function down(): void
    {
        Schema::create('mc_channels', function (Blueprint $table) {
            $table->id();
            $table->string('channel_id')->unique();
            $table->timestamps();
        });

        DB::table('pending_videos')->delete();
        Schema::table('pending_videos', function (Blueprint $table) {
            $table->dropColumn('playlist_id');
        });
        Schema::table('pending_videos', function (Blueprint $table) {
            $table->string('target')->default('regular');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['target_playlist_id', 'skip']);
        });

        Schema::dropIfExists('playlists');
    }
};
