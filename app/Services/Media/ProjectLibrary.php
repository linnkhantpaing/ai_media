<?php

namespace App\Services\Media;

use App\Models\MediaProject;
use App\Support\MediaSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ProjectLibrary
{
    public const GROUPS = [
        'draft' => ['uploading', 'importing', 'validating', 'ready'],
        'awaiting_review' => ['awaiting_review'],
        'processing' => ['queued', 'analyzing', 'translating', 'rendering', 'cancel_requested'],
        'completed' => ['completed'],
        'needs_attention' => ['failed', 'invalid', 'cancelled', 'import_failed', 'import_cancelled'],
    ];

    public function browse(Request $request): array
    {
        $status = $request->query('status');
        abort_if($status !== null && (! is_string($status) || ! isset(self::GROUPS[$status])), 404);
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:250'],
            'mode' => ['nullable', Rule::in(['subs', 'dub'])],
            'source' => ['nullable', Rule::in(['upload', 'youtube'])],
            'date' => ['nullable', Rule::in(['today', 'week', 'custom'])],
            'from' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'created', 'title'])],
            'view' => ['nullable', Rule::in(['grid', 'list'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $filters = [
            'status' => $status ?? '', 'q' => trim($input['q'] ?? ''),
            'mode' => $input['mode'] ?? '', 'source' => $input['source'] ?? '',
            'date' => $input['date'] ?? '', 'from' => $input['from'] ?? '', 'to' => $input['to'] ?? '',
            'sort' => $input['sort'] ?? 'newest', 'view' => $input['view'] ?? 'grid',
        ];
        $query = MediaProject::where('user_id', $request->user()->id);
        if ($filters['q'] !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->whereRaw("(title LIKE ? ESCAPE '!' OR source_name LIKE ? ESCAPE '!')", [$term, $term]);
        }
        if ($filters['mode'] !== '') {
            $query->where('settings->mode', $filters['mode']);
        }
        if ($filters['source'] !== '') {
            $query->where('source_type', $filters['source']);
        }
        $timezone = 'Asia/Bangkok';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        if ($filters['date'] !== '') {
            $start = match ($filters['date']) {
                'today' => $today,
                'week' => $today->subDays(6),
                default => CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone),
            };
            $end = $filters['date'] === 'custom'
                ? CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $timezone)->addDay()
                : $today->addDay();
            $query->where('updated_at', '>=', $start->utc())->where('updated_at', '<', $end->utc());
        }
        $grouped = (clone $query)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $counts = ['all' => (int) $grouped->sum()];
        foreach (self::GROUPS as $key => $statuses) {
            $counts[$key] = (int) $grouped->only($statuses)->sum();
        }
        if ($status !== null) {
            $query->whereIn('status', self::GROUPS[$status]);
        }
        match ($filters['sort']) {
            'oldest' => $query->orderBy('updated_at'),
            'created' => $query->orderByDesc('created_at'),
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('updated_at'),
        };
        $query->orderBy('id')->with([
            'activeRun',
            'runs' => fn (HasMany $runs) => $runs->where('status', 'completed')->where('phase', 'generate')->orderByDesc('finished_at'),
        ]);
        $total = $status === null ? $counts['all'] : $counts[$status];
        $page = min((int) ($input['page'] ?? 1), max(1, (int) ceil($total / 12)));
        $projects = $query->paginate(12, ['*'], 'page', $page)->appends(array_filter($filters, fn (string $value): bool => $value !== ''));

        return ['projects' => $projects, 'filters' => $filters, 'counts' => $counts, 'libraryTimezone' => $timezone];
    }

    public function present(MediaProject $project, array $data): array
    {
        $hasFiles = $data['hasOutput'] || $data['hasAudio'] || $data['hasSubtitles'];
        $published = $project->runs->first(function ($run) use ($project): bool {
            $prefix = 'media/'.$project->id.'/runs/'.$run->id.'/';

            return ($project->output_path || $project->subtitle_path)
                && (! $project->output_path || str_starts_with($project->output_path, $prefix))
                && (! $project->subtitle_path || str_starts_with($project->subtitle_path, $prefix))
                && (! $project->audio_path || str_starts_with($project->audio_path, $prefix));
        });
        $same = $published
            && Arr::except(MediaSettings::normalize($project->settings), ['review_before_render']) == Arr::except(MediaSettings::normalize($published->settings), ['review_before_render'])
            && $this->renderLines($project->lines ?? []) == $this->renderLines($published->lines ?? []);
        $previous = $hasFiles && ($published ? ! $same || $project->busy() : $project->status !== 'completed');
        unset($data['lines'], $data['fingerprint'], $data['sourceUrl']);
        $data['latestPreview'] = null;

        return [...$data,
            'hasScript' => ($project->lines ?? []) !== [],
            'createdAt' => $project->created_at->toIso8601String(),
            'outputState' => ! $hasFiles ? 'none' : ($previous ? 'previous' : ($same ? 'current' : 'unknown')),
            'changesPending' => $hasFiles && $published && ! $same,
            'publishedAt' => $published?->finished_at?->toIso8601String(),
        ];
    }

    private function renderLines(array $lines): array
    {
        return array_map(fn (array $line): array => Arr::only($line, ['index', 'start', 'end', 'text']), $lines);
    }
}
