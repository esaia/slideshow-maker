<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_video_id')->constrained()->cascadeOnDelete();
            $table->float('start_s');
            $table->float('end_s');
            $table->float('heuristic_score')->default(0);
            $table->float('ai_score')->nullable();
            $table->string('ai_tag')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->boolean('excluded')->default(false);
            $table->boolean('used_in_render')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
