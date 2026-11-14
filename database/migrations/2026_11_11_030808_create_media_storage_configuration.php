<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_storage_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->longText('configuration');
            $table->timestamp('created_at');
        });
        Schema::create('media_storage_settings', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('profile_id')->nullable()->constrained('media_storage_profiles');
            $table->json('policy')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('media_storage_settings')->insert(['id' => 1, 'revision' => 0]);
        Schema::create('media_storage_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('success');
            $table->timestamp('checked_at');
        });
        Schema::table('media_projects', function (Blueprint $table): void {
            $table->foreignId('storage_profile_id')->nullable()->constrained('media_storage_profiles');
            $table->json('storage_policy')->nullable();
            $table->timestamp('source_expires_at')->nullable();
            $table->timestamp('artifacts_expires_at')->nullable();
            $table->timestamp('source_expired_at')->nullable();
        });
        Schema::table('media_files', function (Blueprint $table): void {
            $table->string('state', 20)->default('active');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
        });
        Schema::create('media_cleanup_previews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('candidates');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('media_cleanup_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->json('candidates');
            $table->json('results')->nullable();
            $table->unsignedBigInteger('reclaimed_bytes')->default(0);
            $table->timestamp('created_at');
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_cleanup_runs');
        Schema::dropIfExists('media_cleanup_previews');
        Schema::table('media_files', function (Blueprint $t): void {
            $t->dropIndex(['expires_at']);
            $t->dropColumn(['state', 'verified_at', 'expires_at']);
        });
        Schema::table('media_projects', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('storage_profile_id');
            $t->dropColumn(['storage_policy', 'source_expires_at', 'artifacts_expires_at', 'source_expired_at']);
        });
        Schema::dropIfExists('media_storage_checks');
        Schema::dropIfExists('media_storage_settings');
        Schema::dropIfExists('media_storage_profiles');
    }
};
