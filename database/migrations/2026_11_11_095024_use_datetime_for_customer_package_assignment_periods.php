<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_package_assignments', function (Blueprint $table): void {
            $table->dateTime('starts_at')->change();
            $table->dateTime('ends_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_package_assignments', function (Blueprint $table): void {
            $table->timestamp('starts_at')->change();
            $table->timestamp('ends_at')->change();
        });
    }
};
