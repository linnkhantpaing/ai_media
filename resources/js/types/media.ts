export interface NameDictionaryEntry { original: string; translated: string; pronunciation?: string }
export interface MediaSettings {
    fit_script_to_speed?:boolean; workflow?:'movie'|'translate'|'subtitles'; narration_lang?:string; subtitle_lang?:string; target_seconds?:number;
    narration_style?:string; story_order?:string; spoilers?:string; custom_style?:string; character_focus?:string; instructions?:string;
    name_dictionary?:NameDictionaryEntry[]; voice_delivery?:string; voice_pitch?:number;
    subtitle_font?:string; subtitle_size?:number; subtitle_bold?:boolean; subtitle_outline?:number; subtitle_shadow?:number;
    subtitle_background?:boolean; subtitle_position?:string; subtitle_opacity?:number; quality?:string;
    mode: 'subs' | 'dub'; source_lang: string; output: 'burn' | 'srt' | 'mp3' | 'wav'; burn_subtitles: boolean; audio_track: 'mix' | 'narration'; duck_background: boolean; review_before_render: boolean;
    aspect: 'original' | '9:16' | '16:9' | '1:1'; fill: 'auto' | 'blur' | 'crop' | 'pad';
    voice: string; voice_style: string; speech_rate: number; voice_volume: number;
    keep_background: boolean; background_volume: number; cover_mode: 'off' | 'manual';
    cover_style: 'smart_blur' | 'dark_box' | 'blur_strip'; cover_top: number; cover_height: number;
    subtitle_color: string; subtitle_lift: number; max_video_speed: number;
}
export interface MediaLine { scene_id?: string; audio_muted?:boolean; subtitle_hidden?:boolean; index: number; start: number; end: number; source_text: string; text: string; subtitle_text?:string; planned_duration?:number; included?:boolean; locked?:boolean; note?:string }
export interface MediaProject {
    narrationFitReady?:boolean; scriptRevision?:string; id: string; title: string; filename: string; bytes: number; uploadedBytes: number;
    sourceProgress?:number; status: string; errorCode: string | null; storageDisk: string; fingerprint: string | null;
    allowance?: {estimated_seconds:number;videos:number;available_seconds:number}|null;
    sourceType: 'upload' | 'youtube'; sourceUrl: string | null;
    import: { stage: string; downloadedBytes: number; cancelRequested: boolean } | null;
    metadata: { duration: number; width: number; height: number; aspect: string; audio: boolean; poster: boolean; output?: { format?: string; duration?: number; bytes: number; width?: number; height?: number; segments: number } } | null;
    settings: MediaSettings; lines: MediaLine[]; updatedAt: string; hasOutput: boolean; hasAudio: boolean; hasSubtitles: boolean; previewBusy: boolean; latestPreview: MediaPreview | null;
    run: { id: string; status: string; phase: string; stage: string; progress: number; stageProgress?:number; stages: Record<string, string>; errorCode: string | null; cancelRequested: boolean } | null;
    links: Record<'scenes' | 'styledSubtitles' | 'status' | 'upload' | 'complete' | 'update' | 'destroy' | 'uploadPage' | 'setup' | 'analysis' | 'start' | 'generate' | 'script' | 'retry' | 'cancel' | 'reopen' | 'importRetry' | 'importCancel' | 'source' | 'poster' | 'video' | 'audio' | 'previews' | 'subtitles', string>;
}
export interface MediaPageProps {
    testingMode?:boolean; features?:Record<string,boolean>|null; planActive?:boolean; billingUrl?:string; studioDefaults?:MediaSettings; initialProject: MediaProject | null; recentProjects?: MediaProject[];
    runtime: { mediaReady: boolean; aiReady: boolean; backgroundSeparation: boolean; voices: string[]; youtubeReady: boolean };
    limits: { bytes: number; duration: number; chunkBytes: number };
    storage: { disk: string; ready: boolean; used: number; limit: number };
    dictionaryUrl: string; presetsUrl: string; youtubeImportUrl: string; initializeUrl: string; resumeUrlTemplate: string;
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
export interface MediaPreview {id:string;kind:'voice'|'video'|'timing'|'shorten'|'rewrite'|'fit';status:'queued'|'running'|'completed'|'failed'|'cancelled';progress:number;errorCode:string|null;cancelRequested:boolean;matchesCurrent:boolean;result:{retryAt?:string;work?:{phase:string;scene?:number;total:number;completed:number;attempt?:number};ready?:boolean;signature?:string;scenes?:MediaLine[];measurements?:{index:number;target:number;actual:number;ready:boolean;locked?:boolean;muted?:boolean}[];rate?:number;target?:number;sceneId?:string|null;duration?:number;line?:number;text?:string;originalHash?:string;lines?:TimingLine[];warningCount?:number}|null;links:{status:string;cancel:string;file:string|null}}
