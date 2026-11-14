<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_package_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('supersedes_id')->nullable()->index();
            $table->string('source', 30)->default('legacy');
            $table->string('closed_kind', 20)->nullable()->index();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('allowance_starts_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_package_assignments', function (Blueprint $table): void {
            $table->dropIndex(['closed_kind']);
            $table->dropIndex(['supersedes_id']);
            $table->dropColumn(['supersedes_id', 'source', 'closed_kind', 'closed_at', 'allowance_starts_at']);
        });
    }
};
