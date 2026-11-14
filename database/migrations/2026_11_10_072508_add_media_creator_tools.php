<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_projects', function (Blueprint $table): void {
            $table->string('audio_path')->nullable();
        });
        Schema::create('media_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('settings');
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });
        Schema::create('media_previews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('media_project_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('queued');
            $table->string('cache_key', 64);
            $table->json('settings');
            $table->json('lines');
            $table->json('options');
            $table->json('result')->nullable();
            $table->string('path')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->boolean('cancel_requested')->default(false);
            $table->timestamps();
            $table->index(['media_project_id', 'status']);
            $table->index(['media_project_id', 'cache_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_previews');
        Schema::dropIfExists('media_presets');
        Schema::table('media_projects', function (Blueprint $table): void {
            $table->dropColumn('audio_path');
        });
    }
};
