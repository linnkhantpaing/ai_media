import type {StudioPreferenceValues} from '@/lib/studio-defaults';
import type {NameDictionaryEntry} from './media';

export type PreferencePreset={id:number;name:string;position:number;settings:StudioPreferenceValues;is_default:boolean;created_at:string|null;updated_at:string|null};
export type PreferencesData={
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
 endpoints:{save:string;presets:string;presetOrder:string;defaultPreset:string;voiceSample:string;newProject:string;billing:string};
};
export type PreferencesDraft={locale:'my'|'en'|'th';settings:StudioPreferenceValues;dictionary:NameDictionaryEntry[]};
