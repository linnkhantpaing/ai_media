import type {StudioPreferenceValues} from '@/lib/studio-defaults';
import type {NameDictionaryEntry} from './media';

export type PreferencePreset={id:number;name:string;position:number;settings:StudioPreferenceValues;is_default:boolean;created_at:string|null;updated_at:string|null};
export type ProfileDisplay={timezone:string|null;date_format:string|null;time_format:string|null};
export type ProfileDevice={key:string;current:boolean;browser:string;os:string;kind:'mobile'|'desktop';ip:string;last_active:string};
export type PreferencesProfile={
 name:string;email:string;avatar_url:string|null;status:string;
 member_since:string|null;last_login_at:string|null;password_changed_at:string|null;
 plan:{name:string;status:string;ends_at:string|null}|null;
 display:ProfileDisplay;platform_display:{timezone:string;date_format:string;time_format:string};
 devices:ProfileDevice[];photo_storage_ready:boolean;timezones:string[];
};
export type PreferencesData={
 profile:PreferencesProfile;
 locale:'my'|'en'|'th';
 baseline:StudioPreferenceValues;
 saved:Partial<StudioPreferenceValues>;
 dictionary:NameDictionaryEntry[];
 presets:PreferencePreset[];
 /** Package features, or null when no package restricts the account. */
 features:Record<string,boolean>|null;
 voices:string[];
 voiceSampleReady:boolean;
 limits:{presets:number;dictionary:number};
 endpoints:{save:string;presets:string;presetOrder:string;defaultPreset:string;voiceSample:string;newProject:string;billing:string;plan:string;email:string;password:string;photo:string;devices:string};
};
export type PreferencesDraft={profile:{name:string;display:ProfileDisplay};settings:StudioPreferenceValues;dictionary:NameDictionaryEntry[]};
