<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('subject', 160);
            $table->string('category', 24)->default('general');
            $table->string('priority', 12)->default('normal');
            $table->string('status', 24)->default('open');
            $table->string('related_type', 20)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('last_customer_message_id')->nullable();
            $table->unsignedBigInteger('last_admin_reply_id')->nullable();
            $table->timestamp('last_customer_at')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('request_id')->unique();
            $table->string('fingerprint', 64);
            $table->timestamps();
            $table->index(['customer_id', 'last_activity_at']);
            $table->index(['status', 'last_activity_at']);
            $table->index(['related_type', 'related_id']);
        });
        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_kind', 12);
            $table->string('kind', 12)->default('reply');
            $table->text('body');
            $table->unsignedBigInteger('reply_to')->nullable();
            $table->json('event')->nullable();
            $table->uuid('request_id')->unique();
            $table->string('fingerprint', 64);
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });
        Schema::create('support_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });
        Schema::create('support_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->string('path');
            $table->string('name', 160);
            $table->string('mime', 64);
            $table->unsignedBigInteger('bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_reads');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_conversations');
    }
};
