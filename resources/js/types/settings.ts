export interface ProviderCredential { id:string;label:string;enabled:boolean;masked?:string;secret?:string }
export interface ProviderDraft {revision:number;enabled:boolean;credentials:ProviderCredential[];text_models:string[];speech_model:string;voice:string;speech_rate:number;voice_volume:number;timeout:number;auto_processing:boolean;worker_idle_seconds:number}
export interface ProviderReport { capability:'text'|'speech';status:'success'|'failed';errorCode:string|null;checkedAt:string;sampleUrl?:string }
export interface ProviderModel {id:string;label:string;kind:'text'|'speech'}
export interface ProviderConfiguration extends ProviderDraft {source:'server'|'workspace';addedAt:string|null;ready:boolean;apiRevision:string;models:ProviderModel[];voices:string[];checks:ProviderReport[]}
export interface ProviderHealth {automation:{enabled:boolean;idleSeconds:number;supported:boolean;state:'manual'|'unsupported'|'online'|'failed'|'starting'|'standby';errorCode:string|null};background:{ready:boolean;engine:string;model:string;checkedAt:string};workers:{total:number;online:number;busy:number;offline:number;checkedAt:string}}
export interface ProjectDefaultsConfiguration {revision:number;settings:import('./media').MediaSettings;inherit_voice:boolean;savedAt:string|null}
