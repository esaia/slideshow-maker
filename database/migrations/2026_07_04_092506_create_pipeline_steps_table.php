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
        Schema::create('pipeline_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // ingest | proxies | candidates | ai_ranking | music | plan | render
            $table->string('name');
            // pending | running | done | failed
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('log')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipeline_steps');
    }
};
