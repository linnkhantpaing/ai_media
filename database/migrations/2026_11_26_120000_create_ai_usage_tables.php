<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI cost tracking: one row per Gemini call (kept 90 days), daily roll-ups kept forever,
 * and the admin-entered prices used to estimate costs in MMK.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tokens = function (Blueprint $table): void {
            foreach (['input_tokens', 'audio_input_tokens', 'cached_tokens', 'output_tokens', 'thought_tokens', 'total_tokens'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
        };

        Schema::create('ai_usage_events', function (Blueprint $table) use ($tokens): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('media_project_id')->nullable()->index();
            $table->uuid('media_run_id')->nullable();
            $table->string('context', 20);
            $table->string('operation', 20);
            $table->string('model', 100);
            $tokens($table);
            $table->timestamp('created_at')->index();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('ai_usage_daily', function (Blueprint $table) use ($tokens): void {
            $table->id();
            $table->date('day');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('media_project_id')->nullable();
            $table->string('context', 20);
            $table->string('operation', 20);
            $table->string('model', 100);
            $table->unsignedInteger('calls')->default(0);
            $tokens($table);
            $table->unique(['day', 'user_id', 'media_project_id', 'context', 'operation', 'model'], 'ai_usage_daily_key');
            $table->index(['user_id', 'day']);
        });

        Schema::create('ai_cost_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('configuration');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_cost_settings');
        Schema::dropIfExists('ai_usage_daily');
        Schema::dropIfExists('ai_usage_events');
    }
};
