<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('playlist_items', function (Blueprint $table) {
            $table->id();

            // each item belongs to a playlist
            $table->foreignId('playlist_id')
                ->constrained('playlists')
                ->cascadeOnDelete();

            // supported media types
            $table->enum('type', ['image','video','web'])->index();

            // file/url to play (storage path or full URL)
            $table->string('src', 512);

            // duration in seconds
            $table->unsignedInteger('duration');

            // order inside the playlist
            $table->unsignedInteger('sort')->default(0)->index();

            // optional checksum (etag/hash) to detect content change
            $table->string('checksum', 100)->nullable();

            // extra per-item options (e.g., fit mode, background color, etc.)
            $table->json('meta')->nullable();

            $table->timestamps();

            // useful composite index for sorted reads
            $table->index(['playlist_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_items');
    }
};
