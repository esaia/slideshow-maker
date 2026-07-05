<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segments', function (Blueprint $table) {
            // when set, the clip's own audio plays (music ducks under it)
            // instead of the clip being silent under the full-volume music
            $table->boolean('keep_audio')->default(false)->after('excluded');
        });
    }

    public function down(): void
    {
        Schema::table('segments', function (Blueprint $table) {
            $table->dropColumn('keep_audio');
        });
    }
};
