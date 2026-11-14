<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_projects', function (Blueprint $table): void {
            $table->string('source_type', 16)->default('upload');
            $table->string('source_url', 200)->nullable();
            $table->string('import_stage', 32)->nullable();
            $table->unsignedBigInteger('imported_bytes')->default(0);
            $table->boolean('import_cancel_requested')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('media_projects', function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'source_url', 'import_stage', 'imported_bytes', 'import_cancel_requested']);
        });
    }
};
