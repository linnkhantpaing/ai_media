<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_workers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->json('queues');
            $table->unsignedTinyInteger('capacity')->default(1);
            $table->unsignedBigInteger('current_task_id')->nullable();
            $table->timestamp('heartbeat_at')->nullable()->index();
            $table->timestamp('stopped_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
        });
        Schema::create('media_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 120)->unique();
            $table->foreignUuid('media_project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('media_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('media_worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('mode', 8)->nullable();
            $table->string('status', 24)->index();
            $table->string('stage', 40)->nullable();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->json('stages')->nullable();
            $table->unsignedSmallInteger('attempts')->nullable();
            $table->unsignedSmallInteger('max_attempts')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('attempt_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->double('processing_seconds')->nullable();
            $table->double('queue_wait_seconds')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->boolean('history_available')->default(true);
            $table->timestamps();
            $table->index(['media_project_id', 'type', 'id']);
            $table->index(['status', 'updated_at']);
        });
        Schema::create('media_task_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_task_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24);
            $table->string('stage', 40)->nullable();
            $table->unsignedSmallInteger('attempt')->nullable();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('recorded_at');
            $table->index(['media_task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_task_events');
        Schema::dropIfExists('media_tasks');
        Schema::dropIfExists('media_workers');
    }
};
