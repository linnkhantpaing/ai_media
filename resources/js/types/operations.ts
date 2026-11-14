export type JobStatus = 'queued' | 'running' | 'retry_waiting' | 'awaiting_review' | 'completed' | 'failed' | 'cancelled' | 'stopping';
export type JobType = 'import' | 'validation' | 'analysis' | 'generate' | 'preview';
export interface OperationJob {
    id: number; reference: string; title: string; type: JobType; mode: 'subs' | 'dub' | null;
    status: JobStatus; stage: string | null; progress: number | null; stages: Record<string, string>;
    attempt: number | null; maxAttempts: number | null; errorCode: string | null; isCurrent: boolean;
    historyAvailable: boolean; hasPoster: boolean; sourceDuration: number | null; downloadedBytes: number | null;
    elapsedSeconds: number | null; queueWaitSeconds: number | null;
    queuedAt: string | null; startedAt: string | null; finishedAt: string | null; updatedAt: string;
    nextRetryAt: string | null; workerName: string | null; canRetry: boolean; canCancel: boolean;
    hasVideo: boolean; hasAudio: boolean; hasSubtitles: boolean;
    links: { details: string; action: string; project: string | null; poster: string; audio: string; video: string; subtitles: string };
    events?: { status: JobStatus; stage: string | null; attempt: number | null; progress: number | null; errorCode: string | null; at: string }[];
}
export interface OperationFilters { q: string; status: string; type: string; mode: string; date: string; from: string; to: string; sort: string; view: string }
export interface JobPageProps {
    jobs: { data: OperationJob[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null };
    filters: OperationFilters; counts: Record<string, number>; refreshedAt: string; timezone: string; pollMilliseconds: number;
}
export interface OperationWorker {
    id: string; name: string; state: 'busy' | 'idle' | 'stale' | 'stopped' | 'issue' | 'unknown'; queues: string[];
    capacity: number; usedSlots: number | null; heartbeatAt: string | null; registeredAt: string; stoppedAt: string | null;
    currentJob: { id: number; reference: string; title: string } | null;
}
export interface WorkerPageProps {
    workers: OperationWorker[]; queues: { name: string; ready: number; delayed: number; reserved: number; oldestReadyAt: string | null }[];
    queueReported: boolean; heartbeatTtl: number; heartbeatInterval: number; refreshedAt: string; timezone: string; pollMilliseconds: number;
}