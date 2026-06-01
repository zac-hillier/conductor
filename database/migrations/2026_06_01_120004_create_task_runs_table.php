<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('outcome')->nullable();
            $table->text('summary')->nullable();
            $table->json('files_changed')->nullable();
            $table->json('approvals')->nullable();
            $table->unsignedInteger('token_count')->nullable();
            $table->decimal('cost', 10, 4)->nullable();
            $table->string('session_id')->nullable();
            $table->string('claude_session_path')->nullable();
            $table->string('log_ref')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_runs');
    }
};
