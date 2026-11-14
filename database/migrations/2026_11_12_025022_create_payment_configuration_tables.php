<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('revision')->default(0);
            $table->json('currencies');
            $table->json('rules');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->string('currency', 3)->index();
            $table->string('provider', 100);
            $table->string('internal_label', 100)->nullable();
            $table->string('status', 20)->default('inactive')->index();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('details');
            $table->string('identity_hash', 64)->nullable()->index();
            $table->json('instructions');
            $table->string('qr_path')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('payment_method_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->longText('definition');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['payment_method_id', 'number']);
        });
        Schema::create('payment_configuration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->unsignedInteger('revision');
            $table->json('metadata');
            $table->uuid('operation_key')->unique();
            $table->string('fingerprint', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_configuration_events');
        Schema::dropIfExists('payment_method_versions');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('payment_settings');
    }
};
