<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skillnotify_skill_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id');
            $table->integer('skill_id');
            $table->unsignedTinyInteger('trained_skill_level');
            $table->timestamp('updated_at')->nullable();

            $table->primary(['character_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skillnotify_skill_snapshots');
    }
};
