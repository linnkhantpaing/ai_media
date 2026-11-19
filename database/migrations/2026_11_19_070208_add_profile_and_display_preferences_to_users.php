<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profile photo stored in cloud storage, personal date/time display and password age.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable();
            $table->unsignedBigInteger('avatar_profile_id')->nullable();
            $table->json('display_preferences')->nullable();
            $table->timestamp('password_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'avatar_profile_id', 'display_preferences', 'password_changed_at']);
        });
    }
};
