<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 40);
            $table->string('source_id', 80);
            $table->string('category', 24)->index();
            $table->string('action', 100)->index();
            $table->string('result', 16)->index();
            $table->boolean('sensitive')->default(false);
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_kind', 16);
            $table->string('actor_name', 120);
            $table->string('actor_email', 254)->nullable();
            $table->string('target_type', 40);
            $table->string('target_id', 80)->nullable();
            $table->string('target_label', 254);
            $table->json('details');
            $table->string('ip_address', 64)->nullable();
            $table->string('browser', 40)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('recorded_at');
            $table->unique(['source', 'source_id']);
            $table->index(['category', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
