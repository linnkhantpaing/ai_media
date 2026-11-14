<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_task_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_task_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('action', 20);
            $table->string('fingerprint', 64);
            $table->text('reason');
            $table->json('result');
            $table->timestamps();
            $table->index(['media_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_task_actions');
    }
};
