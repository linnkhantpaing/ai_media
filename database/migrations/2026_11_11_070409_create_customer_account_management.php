<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_status', 20)->default('active')->index();
            $table->boolean('managed_account')->default(false);
            $table->unsignedInteger('account_revision')->default(1);
            $table->unsignedInteger('auth_version')->default(0);
            $table->text('account_note')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('account_creation_key')->nullable()->unique();
            $table->string('account_creation_fingerprint', 64)->nullable();
        });
        Schema::create('customer_account_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->json('metadata');
            $table->uuid('operation_key')->nullable()->unique();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_events');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_admin_id');
            $table->dropIndex(['account_status']);
            $table->dropUnique(['account_creation_key']);
            $table->dropColumn(['account_status', 'managed_account', 'account_revision', 'auth_version', 'account_note', 'last_login_at', 'account_creation_key', 'account_creation_fingerprint']);
        });
    }
};
