<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('name');
            $table->text('objective')->nullable();
            $table->string('status')->default('pending');
            $table->string('gateway_test')->nullable();
            $table->json('exit_criteria')->nullable();
            $table->string('prompt_path')->nullable();
            $table->string('summary_path')->nullable();
            $table->foreignId('task_id')
                ->nullable()
                ->constrained('tasks')
                ->nullOnDelete();
            $table->string('outcome')->nullable();
            $table->decimal('cost', 10, 4)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phases');
    }
};
