<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('revid')->nullable()->after('page_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('wiki_entries', function (Blueprint $table) {
            $table->dropColumn('revid');
        });
    }
};
