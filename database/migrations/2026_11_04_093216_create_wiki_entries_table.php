<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index(); // resonators|weapons|echoes|article
            $table->unsignedBigInteger('page_id')->unique();
            $table->string('title')->index();
            $table->string('slug', 400);
            $table->string('url', 800);
            $table->text('description')->nullable();
            $table->string('image', 800)->nullable();
            $table->json('infobox')->nullable();
            $table->json('sections')->nullable();
            $table->json('categories')->nullable();
            $table->json('images')->nullable();
            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'slug'], 'type_slug_idx', );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_entries');
    }
};
