<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id');
            $table->string('title', 250);
            $table->string('source_name');
            $table->string('source_extension', 10);
            $table->string('source_mime', 100)->nullable();
            $table->string('source_path', 512);
            $table->string('fingerprint', 64)->nullable();
            $table->unsignedBigInteger('expected_bytes');
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->string('status', 32)->default('uploading')->index();
            $table->string('error_code', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->json('settings');
            $table->json('lines')->nullable();
            $table->uuid('active_run_id')->nullable();
            $table->string('output_path', 512)->nullable();
            $table->string('subtitle_path', 512)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'client_id']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_projects');
    }
};
