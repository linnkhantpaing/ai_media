<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_projects', function (Blueprint $table): void {
            $table->string('storage_disk', 20)->default('local');
        });
    }

    public function down(): void
    {
        Schema::table('media_projects', function (Blueprint $table): void {
            $table->dropColumn('storage_disk');
        });
    }
};
