<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('aspect')->nullable(); // landscape | vertical (chosen at render time)
        });

        Schema::table('source_videos', function (Blueprint $table) {
            $table->timestamp('shot_at')->nullable(); // capture time from metadata
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('aspect'));
        Schema::table('source_videos', fn (Blueprint $t) => $t->dropColumn('shot_at'));
    }
};
