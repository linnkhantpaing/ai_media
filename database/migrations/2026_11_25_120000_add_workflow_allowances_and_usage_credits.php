<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workflow processing allowances: usage records remember which Studio workflow
 * (movie, translate, subtitles) they belong to, and purchased minute top-ups are
 * stored as credits tied to the customer's allowance period. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->string('workflow', 12)->nullable()->after('kind');
            $table->index(['user_id', 'allowance_start', 'workflow']);
        });

        DB::table('usage_records')->where('kind', 'processing')->whereNotNull('media_run_id')->orderBy('id')
            ->chunkById(500, function ($records): void {
                $runs = DB::table('media_runs')->whereIn('id', $records->pluck('media_run_id'))->pluck('settings', 'id');
                foreach ($records as $record) {
                    $settings = json_decode((string) ($runs[$record->media_run_id] ?? ''), true);
                    if (! is_array($settings)) {
                        continue;
                    }
                    $workflow = ($settings['workflow'] ?? '') === 'movie' ? 'movie' : (($settings['mode'] ?? 'subs') === 'dub' ? 'translate' : 'subtitles');
                    DB::table('usage_records')->where('id', $record->id)->update(['workflow' => $workflow]);
                }
            });

        Schema::create('usage_credits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('payment_transaction_id')->nullable()->unique();
            $table->unsignedBigInteger('assignment_id')->nullable()->index();
            $table->unsignedBigInteger('package_id')->nullable()->index();
            $table->string('workflow', 12);
            $table->unsignedBigInteger('seconds');
            $table->timestamp('allowance_start')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('package_snapshot')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'allowance_start', 'workflow']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_credits');
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'allowance_start', 'workflow']);
            $table->dropColumn('workflow');
        });
    }
};
