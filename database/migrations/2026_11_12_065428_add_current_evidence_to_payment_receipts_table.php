<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->boolean('is_current')->default(false)->index();
        });

        DB::table('payment_transactions')->select('id')->orderBy('id')->chunkById(200, function ($payments): void {
            foreach ($payments as $payment) {
                $receipt = DB::table('payment_receipts')->where('payment_transaction_id', $payment->id)->whereNull('payment_refund_id')
                    ->orderByDesc('created_at')->orderByDesc('id')->first(['id']);
                if ($receipt) {
                    DB::table('payment_receipts')->where('id', $receipt->id)->update(['is_current' => true]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->dropIndex(['is_current']);
            $table->dropColumn('is_current');
        });
    }
};
