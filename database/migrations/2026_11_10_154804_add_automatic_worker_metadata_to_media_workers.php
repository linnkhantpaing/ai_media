<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_workers', function (Blueprint $table): void {
            $table->boolean('automatic')->default(false);
            $table->unsignedInteger('process_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_workers', fn (Blueprint $table) => $table->dropColumn(['automatic', 'process_id']));
    }
};
