<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('dedupe_key', 190)->unique();
            $table->string('category', 24)->index();
            $table->string('kind', 50);
            $table->string('priority', 12)->index();
            $table->string('issue', 16)->index();
            $table->unsignedInteger('revision')->default(1);
            $table->string('target_type', 24);
            $table->string('target_id', 80)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_email', 254)->nullable();
            $table->string('reference', 100);
            $table->json('context');
            $table->timestamp('received_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['category', 'received_at']);
        });
        Schema::create('admin_notice_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_id')->constrained('admin_notices')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('read_revision')->default(0);
            $table->unsignedInteger('archived_revision')->nullable();
            $table->timestamps();
            $table->unique(['notice_id', 'admin_id']);
            $table->index(['admin_id', 'archived_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notice_states');
        Schema::dropIfExists('admin_notices');
    }
};
