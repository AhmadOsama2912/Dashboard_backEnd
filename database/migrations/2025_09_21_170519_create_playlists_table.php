<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->id();

            // each playlist belongs to a customer (company)
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->string('name', 190);

            // default playlist for the customer
            $table->boolean('is_default')->default(false)->index();

            // when this playlist was published (only published ones are served to screens)
            $table->timestamp('published_at')->nullable()->index();

            // version/hash of content to help screens know when to refresh
            $table->string('content_version', 100)->nullable();

            // optional extra data
            $table->json('meta')->nullable();

            $table->timestamps();

            // helpful composite index for queries per customer + default
            $table->index(['customer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlists');
    }
};
