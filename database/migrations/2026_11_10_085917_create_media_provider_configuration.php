<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_provider_revisions', function (Blueprint $table): void {
            $table->id();
            $table->longText('configuration');
            $table->string('fingerprint', 64)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('media_provider_settings', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->foreignId('revision_id')->nullable()->constrained('media_provider_revisions')->restrictOnDelete();
        });
        DB::table('media_provider_settings')->insert(['id' => 1, 'revision_id' => null]);
        Schema::create('media_provider_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->index();
            $table->string('capability', 16);
            $table->string('status', 16);
            $table->string('error_code', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at');
        });
        foreach (['media_runs', 'media_previews'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('provider_revision_id')->nullable()->constrained('media_provider_revisions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['media_runs', 'media_previews'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('provider_revision_id'));
        }
        Schema::dropIfExists('media_provider_checks');
        Schema::dropIfExists('media_provider_settings');
        Schema::dropIfExists('media_provider_revisions');
    }
};
