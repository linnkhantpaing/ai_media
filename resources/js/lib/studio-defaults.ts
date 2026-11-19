import type {MediaSettings} from '@/types/media';

/** Render defaults used before the server's System Project Defaults are applied. */
export const renderDefaults:MediaSettings={mode:'dub',source_lang:'auto',output:'burn',burn_subtitles:true,audio_track:'mix',duck_background:true,review_before_render:true,aspect:'original',fill:'auto',voice:'Kore',voice_style:'',speech_rate:1,voice_volume:1,keep_background:false,background_volume:.35,cover_mode:'off',cover_style:'smart_blur',cover_top:.78,cover_height:.16,subtitle_color:'#FFFFFF',subtitle_lift:.08,max_video_speed:1.35};

/** Recap Studio V1 starting values. Mirrors App\Services\StudioPreferences::STARTING. */
export const studioStart={workflow:'movie' as const,narration_lang:'my',subtitle_lang:'same',target_seconds:600,narration_style:'storyteller',story_order:'chronological',spoilers:'include',custom_style:'',character_focus:'',instructions:'',name_dictionary:[],voice_delivery:'storyteller',voice_pitch:0,subtitle_font:'Padauk',subtitle_size:0,subtitle_bold:false,subtitle_outline:2,subtitle_shadow:1,subtitle_background:false,subtitle_position:'bottom',subtitle_opacity:.5,quality:'source',fit_script_to_speed:true};

export type Workflow='movie'|'translate'|'subtitles';
export const workflows:Workflow[]=['movie','translate','subtitles'];
/** Package feature that allows each creation workflow. */
export const workflowFeature={movie:'movie',translate:'dub',subtitles:'subs'} as const;
export const outputFeature={burn:'mp4',srt:'srt',mp3:'audio',wav:'audio'} as const;

export const narrationLanguages=['my','en','th','zh','hi','ko','ja','es','id','vi'] as const;
export const narrationStyles=['standard','storyteller','suspense','punchy','emotional','funny','documentary','custom'] as const;
export const durationChoices=[180,300,600,900,1200] as const;

/** Preference values a customer can save as defaults or in a preset. */
export type StudioPreferenceValues={workflow:Workflow;mode:'subs'|'dub';narration_lang:string;subtitle_lang:string;target_seconds:number;narration_style:string;voice:string;speech_rate:number;voice_pitch:number;output:'burn'|'srt'|'mp3'|'wav';quality:'source'|'720p'|'1080p';subtitle_font:string;subtitle_bold:boolean;subtitle_outline:number;subtitle_shadow:number;subtitle_background:boolean;subtitle_opacity:number;subtitle_position:'top'|'middle'|'bottom'};

type SubtitleLook=Pick<StudioPreferenceValues,'subtitle_font'|'subtitle_bold'|'subtitle_outline'|'subtitle_shadow'|'subtitle_background'|'subtitle_opacity'|'subtitle_position'>;
/** Named subtitle looks offered as a single "Default subtitle style" choice. */
export const subtitleLooks:Record<'classic'|'modern'|'boxed'|'top',SubtitleLook>={
 classic:{subtitle_font:'Padauk',subtitle_bold:false,subtitle_outline:2,subtitle_shadow:1,subtitle_background:false,subtitle_opacity:.5,subtitle_position:'bottom'},
 modern:{subtitle_font:'Padauk',subtitle_bold:true,subtitle_outline:1,subtitle_shadow:2,subtitle_background:false,subtitle_opacity:.5,subtitle_position:'bottom'},
 boxed:{subtitle_font:'Padauk',subtitle_bold:false,subtitle_outline:0,subtitle_shadow:0,subtitle_background:true,subtitle_opacity:.6,subtitle_position:'bottom'},
 top:{subtitle_font:'Padauk',subtitle_bold:false,subtitle_outline:2,subtitle_shadow:1,subtitle_background:false,subtitle_opacity:.5,subtitle_position:'top'},
};
export type SubtitleLookKey=keyof typeof subtitleLooks|'custom';
export function subtitleLookOf(values:SubtitleLook):SubtitleLookKey{
 const near=(a:unknown,b:unknown)=>typeof a==='number'&&typeof b==='number'?Math.abs(a-b)<.0001:a===b;
 return (Object.keys(subtitleLooks) as (keyof typeof subtitleLooks)[]).find(key=>(Object.keys(subtitleLooks[key]) as (keyof SubtitleLook)[]).every(field=>near(values[field],subtitleLooks[key][field])))??'custom';
}

/** Outputs that make sense for a workflow: narration exports audio, subtitles export SRT. */
export function outputsFor(workflow:Workflow):StudioPreferenceValues['output'][]{return workflow==='subtitles'?['burn','srt']:['burn','mp3','wav'];}

/** Keeps the mode and output consistent after the workflow changes. */
export function withWorkflow(values:StudioPreferenceValues,workflow:Workflow):StudioPreferenceValues{
 const output=outputsFor(workflow).includes(values.output)?values.output:'burn';
 return {...values,workflow,mode:workflow==='subtitles'?'subs':'dub',output};
}
