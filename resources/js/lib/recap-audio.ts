import type {MediaSettings} from '../types/media';
/** The mix selector and background switch describe the same output, including video. */
export function syncAudioControls(settings: MediaSettings): MediaSettings {
    return {...settings,audio_track:settings.keep_background?'mix':'narration'};
}
export function updateAudioControls<K extends keyof MediaSettings>(settings: MediaSettings,key: K,value: MediaSettings[K]): MediaSettings {
    const next={...settings,[key]:value};
    if(key==='audio_track') next.keep_background=value==='mix';
    if(key==='keep_background') next.audio_track=value?'mix':'narration';
    return next;
}
