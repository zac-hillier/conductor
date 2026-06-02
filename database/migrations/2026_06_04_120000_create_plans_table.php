<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('source_task_id')
                ->nullable()
                ->constrained('tasks')
                ->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('drafting');
            $table->string('artifact_dir')->nullable();
            $table->text('concept')->nullable();
            $table->text('summary')->nullable();
            $table->decimal('cost', 10, 4)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
