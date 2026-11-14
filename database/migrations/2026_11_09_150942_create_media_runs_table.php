<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('media_project_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 20)->default('analysis');
            $table->string('status', 32)->default('queued')->index();
            $table->string('stage', 40)->default('preparing');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->boolean('cancel_requested')->default(false);
            $table->json('settings');
            $table->json('lines')->nullable();
            $table->json('stages')->nullable();
            $table->json('artifacts')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_runs');
    }
};
