<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('ref')->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('definition_of_done')->nullable();
            $table->json('constraints')->nullable();
            $table->json('target_paths')->nullable();
            $table->unsignedSmallInteger('priority')->default(50);
            $table->string('status')->default('backlog');
            $table->unsignedSmallInteger('readiness_score')->nullable();
            $table->json('readiness_detail')->nullable();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('tasks')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
