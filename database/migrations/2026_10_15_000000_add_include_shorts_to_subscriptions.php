<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('include_shorts')->default(false);
        });

        // Existing channels keep the behaviour of the global setting
        DB::table('subscriptions')->update(['include_shorts' => (bool) config('youtube.include_shorts')]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('include_shorts');
        });
    }
};
