<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('media_project_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 20);
            $table->string('path', 512);
            $table->unsignedBigInteger('bytes');
            $table->boolean('is_source')->default(false);
            $table->unique(['disk', 'path']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
