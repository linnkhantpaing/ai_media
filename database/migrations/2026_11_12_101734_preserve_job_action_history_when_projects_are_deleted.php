<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_task_actions', function (Blueprint $table): void {
            $table->dropForeign(['media_task_id']);
            $table->unsignedBigInteger('media_task_id')->nullable()->change();
            $table->foreign('media_task_id')->references('id')->on('media_tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_task_actions', function (Blueprint $table): void {
            $table->dropForeign(['media_task_id']);
            $table->foreign('media_task_id')->references('id')->on('media_tasks')->restrictOnDelete();
        });
    }
};
