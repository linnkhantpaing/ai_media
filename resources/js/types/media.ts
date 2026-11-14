export interface MediaSettings {
    mode: 'subs' | 'dub'; source_lang: string; output: 'burn' | 'srt' | 'mp3' | 'wav'; burn_subtitles: boolean; audio_track: 'mix' | 'narration'; duck_background: boolean; review_before_render: boolean;
    aspect: 'original' | '9:16' | '16:9' | '1:1'; fill: 'auto' | 'blur' | 'crop' | 'pad';
    voice: string; voice_style: string; speech_rate: number; voice_volume: number;
    keep_background: boolean; background_volume: number; cover_mode: 'off' | 'manual';
    cover_style: 'smart_blur' | 'dark_box' | 'blur_strip'; cover_top: number; cover_height: number;
    subtitle_color: string; subtitle_lift: number; max_video_speed: number;
}
export interface MediaLine { index: number; start: number; end: number; source_text: string; text: string }
export interface MediaProject {
    id: string; title: string; filename: string; bytes: number; uploadedBytes: number;
    status: string; errorCode: string | null; storageDisk: string; fingerprint: string | null;
    allowance?: {estimated_seconds:number;videos:number;available_seconds:number}|null;
    sourceType: 'upload' | 'youtube'; sourceUrl: string | null;
    import: { stage: string; downloadedBytes: number; cancelRequested: boolean } | null;
    metadata: { duration: number; width: number; height: number; aspect: string; audio: boolean; poster: boolean; output?: { format?: string; duration?: number; bytes: number; width?: number; height?: number; segments: number } } | null;
    settings: MediaSettings; lines: MediaLine[]; updatedAt: string; hasOutput: boolean; hasAudio: boolean; hasSubtitles: boolean; previewBusy: boolean; latestPreview: MediaPreview | null;
    run: { id: string; status: string; phase: string; stage: string; progress: number; stages: Record<string, string>; errorCode: string | null; cancelRequested: boolean } | null;
    links: Record<'status' | 'upload' | 'complete' | 'update' | 'destroy' | 'uploadPage' | 'setup' | 'analysis' | 'start' | 'generate' | 'script' | 'retry' | 'cancel' | 'reopen' | 'importRetry' | 'importCancel' | 'source' | 'poster' | 'video' | 'audio' | 'previews' | 'subtitles', string>;
}
export interface MediaPageProps {
    initialProject: MediaProject | null; recentProjects?: MediaProject[];
    runtime: { mediaReady: boolean; aiReady: boolean; backgroundSeparation: boolean; voices: string[]; youtubeReady: boolean };
    limits: { bytes: number; duration: number; chunkBytes: number };
    storage: { disk: string; ready: boolean; used: number; limit: number };
    presetsUrl: string; youtubeImportUrl: string; initializeUrl: string; resumeUrlTemplate: string;
}
export interface LibraryFilters {
    status: string; q: string; mode: string; source: string; date: string; from: string; to: string; sort: string; view: string;
}
export type LibraryProject = Omit<MediaProject, 'lines' | 'fingerprint' | 'sourceUrl'> & {
    hasScript: boolean; createdAt: string; outputState: 'none' | 'current' | 'previous' | 'unknown'; changesPending: boolean; publishedAt: string | null;
};
export interface LibraryPageProps {
    frame: import('@/types').FrameKey;
    projects: { data: LibraryProject[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null };
    filters: LibraryFilters; counts: Record<string, number>; libraryTimezone: string;
    storage: MediaPageProps['storage'];
}
export interface MediaPreset {id:number;name:string;settings:MediaSettings}
export interface TimingLine {index:number;original:number;voice:number;output:number;extra:number;textHash:string}
export interface MediaPreview {id:string;kind:'voice'|'video'|'timing'|'shorten';status:'queued'|'running'|'completed'|'failed'|'cancelled';progress:number;errorCode:string|null;cancelRequested:boolean;matchesCurrent:boolean;result:{duration?:number;line?:number;text?:string;originalHash?:string;lines?:TimingLine[];warningCount?:number}|null;links:{status:string;cancel:string;file:string|null}}
