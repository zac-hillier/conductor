<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('run_id')
                ->nullable()
                ->constrained('task_runs')
                ->nullOnDelete();
            $table->string('capability');
            $table->string('command')->nullable();
            $table->text('reason')->nullable();
            $table->string('decision')->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
