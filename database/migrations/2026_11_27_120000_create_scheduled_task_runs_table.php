<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per run of a scheduled background task (Admin → Operations → Scheduled Tasks).
        // Rows are kept for 30 days.
        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('task', 40);
            $table->string('trigger', 12);
            $table->string('status', 12);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['task', 'id']);
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
