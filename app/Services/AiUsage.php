<?php

namespace App\Services;

use App\Models\MediaProject;
use App\Models\MediaRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records the tokens of every Gemini call against the customer, project and step that
 * caused it. Queue jobs set the context around their work; recording never breaks the
 * media work itself.
 */
class AiUsage
{
    public const RETENTION_DAYS = 90;

    /** @var array{user_id: ?int, media_project_id: ?string, media_run_id: ?string, context: string} */
    private array $context = ['user_id' => null, 'media_project_id' => null, 'media_run_id' => null, 'context' => 'other'];

    /**
     * Runs $work with the AI usage context of a project (and run), restoring the previous context afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function within(?MediaProject $project, ?MediaRun $run, string $context, callable $work): mixed
    {
        $previous = $this->context;
        $this->context = ['user_id' => $project?->user_id, 'media_project_id' => $project?->id, 'media_run_id' => $run?->id, 'context' => $context];
        try {
            return $work();
        } finally {
            $this->context = $previous;
        }
    }

    /** Sets the context for the rest of the current job or request (reset after every queue job). */
    public function begin(?MediaProject $project, ?MediaRun $run, string $context, ?int $userId = null): void
    {
        $this->context = ['user_id' => $project?->user_id ?? $userId, 'media_project_id' => $project?->id, 'media_run_id' => $run?->id, 'context' => mb_substr($context, 0, 20)];
    }

    public function reset(): void
    {
        $this->context = ['user_id' => null, 'media_project_id' => null, 'media_run_id' => null, 'context' => 'other'];
    }

    public function record(string $model, string $operation, mixed $usage): void
    {
        try {
            $usage = is_array($usage) ? $usage : [];
            $audio = 0;
            foreach (is_array($usage['input_tokens_by_modality'] ?? null) ? $usage['input_tokens_by_modality'] : [] as $part) {
                if (is_array($part) && ($part['modality'] ?? null) === 'audio') {
                    $audio += (int) ($part['tokens'] ?? 0);
                }
            }
            $input = max(0, (int) ($usage['total_input_tokens'] ?? 0));
            $output = max(0, (int) ($usage['total_output_tokens'] ?? 0));
            $thought = max(0, (int) ($usage['total_thought_tokens'] ?? 0));
            DB::table('ai_usage_events')->insert([...$this->context, 'operation' => mb_substr($operation, 0, 20), 'model' => mb_substr($model, 0, 100),
                'input_tokens' => $input, 'audio_input_tokens' => min($input, max(0, $audio)), 'cached_tokens' => min($input, max(0, (int) ($usage['total_cached_tokens'] ?? 0))),
                'output_tokens' => $output, 'thought_tokens' => $thought, 'total_tokens' => max((int) ($usage['total_tokens'] ?? 0), $input + $output + $thought),
                'created_at' => now()]);
        } catch (Throwable $error) {
            Log::warning('AI usage could not be recorded.', ['model' => $model, 'operation' => $operation, 'error' => $error::class]);
        }
    }

    /**
     * Rolls call rows older than the retention window into daily totals (kept forever) and removes them.
     */
    public function prune(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS)->startOfDay();
        $columns = ['input_tokens', 'audio_input_tokens', 'cached_tokens', 'output_tokens', 'thought_tokens', 'total_tokens'];

        // One transaction, so a crash can never count a day twice or lose it.
        return DB::transaction(function () use ($cutoff, $columns): int {
            $removed = 0;
            DB::table('ai_usage_events')->where('created_at', '<', $cutoff)
                ->selectRaw('DATE(created_at) as day, user_id, media_project_id, context, operation, model, COUNT(*) as calls, '.implode(', ', array_map(fn ($c) => "SUM($c) as $c", $columns)))
                ->groupByRaw('DATE(created_at), user_id, media_project_id, context, operation, model')->orderBy('day')
                ->get()->each(function ($row) use ($columns): void {
                    $key = ['day' => $row->day, 'user_id' => $row->user_id, 'media_project_id' => $row->media_project_id, 'context' => $row->context, 'operation' => $row->operation, 'model' => $row->model];
                    $query = DB::table('ai_usage_daily');
                    foreach ($key as $field => $value) {
                        $value === null ? $query->whereNull($field) : $query->where($field, $value);
                    }
                    $existing = $query->first();
                    $values = ['calls' => (int) $row->calls + (int) ($existing->calls ?? 0)];
                    foreach ($columns as $column) {
                        $values[$column] = (int) $row->{$column} + (int) ($existing->{$column} ?? 0);
                    }
                    $existing ? DB::table('ai_usage_daily')->where('id', $existing->id)->update($values) : DB::table('ai_usage_daily')->insert([...$key, ...$values]);
                });
            do {
                $batch = DB::table('ai_usage_events')->where('created_at', '<', $cutoff)->limit(1000)->pluck('id');
                $removed += $batch->isEmpty() ? 0 : DB::table('ai_usage_events')->whereIn('id', $batch)->delete();
            } while ($batch->isNotEmpty());

            return $removed;
        });
    }
}
