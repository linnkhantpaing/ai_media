<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personal Studio defaults, an optional default preset and a custom preset order.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('studio_preferences')->nullable();
            $table->foreignId('default_preset_id')->nullable()->constrained('media_presets')->nullOnDelete();
        });
        Schema::table('media_presets', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_preset_id');
            $table->dropColumn('studio_preferences');
        });
        Schema::table('media_presets', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
