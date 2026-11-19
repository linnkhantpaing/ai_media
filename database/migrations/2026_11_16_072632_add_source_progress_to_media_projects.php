<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_projects', function (Blueprint $table): void {
            $table->unsignedTinyInteger('source_progress')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('media_projects', function (Blueprint $table): void {
            $table->dropColumn('source_progress');
        });
    }
};
