<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skillnotify_skill_completions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->integer('skill_id');
            $table->unsignedTinyInteger('from_level');
            $table->unsignedTinyInteger('to_level');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'skill_id', 'to_level'], 'skillnotify_completions_unique');
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skillnotify_skill_completions');
    }
};
