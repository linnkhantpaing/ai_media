<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key', 180)->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->uuid('media_project_id')->nullable()->index();
            $table->uuid('media_run_id')->nullable()->unique();
            $table->unsignedBigInteger('assignment_id')->nullable()->index();
            $table->unsignedBigInteger('package_id')->nullable()->index();
            $table->string('kind', 20)->index();
            $table->string('status', 20)->index();
            $table->json('customer_snapshot');
            $table->json('project_snapshot');
            $table->json('package_snapshot')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('allowance_start')->nullable()->index();
            foreach (['reserved_seconds', 'charged_seconds', 'returned_seconds', 'reserved_videos', 'charged_videos', 'returned_videos', 'storage_bytes'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'allowance_start']);
        });
        Schema::create('usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usage_record_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 120)->nullable();
            $table->string('kind', 40);
            $table->json('changes');
            $table->string('reason', 500)->nullable();
            $table->uuid('request_id')->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('usage_records');
    }
};
