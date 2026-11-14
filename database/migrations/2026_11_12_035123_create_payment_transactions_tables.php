<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('package_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->foreignId('subscription_id')->nullable()->constrained('customer_package_assignments')->restrictOnDelete();
            $t->json('package_snapshot');
            $t->longText('receiving_snapshot');
            $t->string('currency', 3)->index();
            $t->unsignedBigInteger('expected_minor');
            $t->unsignedBigInteger('received_minor')->nullable();
            $t->string('purpose', 15);
            $t->string('status', 24)->index();
            $t->unsignedInteger('revision')->default(1);
            $t->text('transfer')->nullable();
            $t->string('reference_hash', 64)->nullable()->index();
            $t->string('reference_lookup', 64)->nullable()->index();
            $t->string('approved_reference_hash', 64)->nullable()->unique();
            $t->text('internal_note')->nullable();
            $t->dateTime('expires_at');
            $t->dateTime('submitted_at')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('payment_refunds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_transaction_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_minor');
            $t->string('status', 20)->index();
            $t->string('access_action', 10)->default('keep');
            $t->text('details');
            $t->string('reason', 500);
            $t->dateTime('completed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('payment_receipts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('payment_transaction_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_refund_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('path');
            $t->string('mime', 40);
            $t->unsignedInteger('bytes');
            $t->string('sha256', 64)->index();
            $t->string('original_name', 150);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('payment_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_transaction_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 30);
            $t->json('metadata');
            $t->uuid('operation_key')->unique();
            $t->string('fingerprint', 64);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_transactions');
    }
};
