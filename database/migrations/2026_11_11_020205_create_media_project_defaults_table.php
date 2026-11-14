<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_project_defaults', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('revision')->default(0);
            $table->json('settings')->nullable();
            $table->boolean('inherit_voice')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('media_project_defaults')->insert(['id' => 1, 'revision' => 0, 'inherit_voice' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('media_project_defaults');
    }
};
