import {Head,router,usePage} from '@inertiajs/react';
import {useEffect,useMemo,useRef,useState,type ReactNode} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {MediaDialog,SelectField} from '@/components/media-workflow';
import {mediaApi,MediaApiError} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import {voiceLabel} from '@/lib/media-voices';
import {languageNames,languageShort,preferenceWord,usePreferenceText,type PreferenceWord} from '@/lib/preferences-i18n';
import {durationChoices,narrationLanguages,narrationStyles,outputFeature,outputsFor,subtitleLookOf,subtitleLooks,withWorkflow,workflowFeature,workflows,type StudioPreferenceValues,type SubtitleLookKey,type Workflow} from '@/lib/studio-defaults';
import type {SharedData} from '@/types';
import type {NameDictionaryEntry} from '@/types/media';
import type {PreferencePreset,PreferencesData,PreferencesDraft} from '@/types/preferences';
import '../../../css/preferences.css';

type Tab='general'|'defaults'|'presets'|'dictionary';
type Errors=Record<string,string[]>;
type Text=(key:PreferenceWord)=>string;
const tabs:Tab[]=['general','defaults','presets','dictionary'];
const flags:Record<string,string>={my:'/images/flags/mm.svg',en:'/images/flags/gb.svg',th:'/images/flags/th.svg'};
const styleImage=(style:string)=>'/images/narration-styles/'+style+'.jpg?v=supplied-20261117';
const pageSize=10;

function draftFrom(data:PreferencesData):PreferencesDraft{
 const settings={...data.baseline,...data.saved} as StudioPreferenceValues;
 return {locale:data.locale,settings:withWorkflow(settings,settings.workflow),dictionary:data.dictionary.map(entry=>({...entry}))};
}
function apiMessage(error:unknown,t:Text){
 if(error instanceof MediaApiError){if(error.code==='session_expired')return t('sessionExpired');if(error.code==='rate_limited')return t('rateLimited');if(error.status===422)return Object.values(error.fields).flat()[0]??t('saveFailed');}
 return t('failed');
}
function initialTab():Tab{const value=new URLSearchParams(window.location.search).get('tab');return tabs.includes(value as Tab)?value as Tab:'general';}

export default function Preferences({preferences}:{preferences:PreferencesData}){
 const {t}=usePreferenceText();const toast=useToast();
 const [data,setData]=useState(preferences);
 const saved=useMemo(()=>draftFrom(data),[data]);
 const [draft,setDraft]=useState(saved);
 const [tab,setTab]=useState<Tab>(initialTab);
 const [errors,setErrors]=useState<Errors>({});
 const [saving,setSaving]=useState(false);
 const [leaving,setLeaving]=useState<(()=>void)|null>(null);
 const dirty=JSON.stringify(draft)!==JSON.stringify(saved);
 const dirtyRef=useRef(dirty);dirtyRef.current=dirty;
 const bypass=useRef(false);

 useEffect(()=>{
  const unload=(event:BeforeUnloadEvent)=>{if(dirtyRef.current&&!bypass.current){event.preventDefault();event.returnValue='';}};
  window.addEventListener('beforeunload',unload);
  const off=router.on('before',event=>{if(dirtyRef.current&&!bypass.current&&event.detail.visit.method==='get'){event.preventDefault();const visit=event.detail.visit;setLeaving(()=>()=>{bypass.current=true;router.visit(visit.url,visit);});}});
  return()=>{window.removeEventListener('beforeunload',unload);off();};
 },[]);

 useEffect(()=>{document.getElementById('pref-tab-'+tab)?.scrollIntoView({block:'nearest',inline:'nearest'});},[tab]);
 function changeTab(next:Tab){setTab(next);const url=new URL(window.location.href);url.searchParams.set('tab',next);window.history.replaceState(window.history.state,'',url);}
 function patchSettings(values:Partial<StudioPreferenceValues>){setDraft(old=>({...old,settings:{...old.settings,...values}}));setErrors(old=>Object.fromEntries(Object.entries(old).filter(([key])=>!key.startsWith('settings.'))));}
 function setDictionary(dictionary:NameDictionaryEntry[]){setDraft(old=>({...old,dictionary}));setErrors(old=>Object.fromEntries(Object.entries(old).filter(([key])=>!key.startsWith('name_dictionary'))));}

 async function save(){
  if(saving||!dirty)return;
  setSaving(true);setErrors({});
  try{
   const result=await mediaApi<{preferences:PreferencesData}>(data.endpoints.save,'PUT',{locale:draft.locale,settings:draft.settings,name_dictionary:draft.dictionary});
   const localeChanged=draft.locale!==data.locale;
   setData(result.preferences);setDraft(draftFrom(result.preferences));toast.success(localeChanged?preferenceWord('savedToast',draft.locale):t('savedToast'));
   if(localeChanged){bypass.current=true;router.visit(window.location.pathname+'?tab='+tab,{preserveScroll:true,onFinish:()=>{bypass.current=false;}});}
  }catch(error){
   const fields=error instanceof MediaApiError?error.fields:{};setErrors(fields);toast.error(apiMessage(error,t));
   const first=Object.keys(fields)[0];
   if(first){changeTab(first.startsWith('name_dictionary')?'dictionary':first.startsWith('settings')?'defaults':'general');}
  }finally{setSaving(false);}
 }
 function discard(){setDraft(saved);setErrors({});}
 function presetsChanged(next:PreferencesData){setData(old=>({...next,saved:old.saved,locale:old.locale,dictionary:old.dictionary}));}

 const defaultPreset=data.presets.find(preset=>preset.is_default)??null;
 const header=<div className={'pref-actions'+(dirty?' is-dirty':'')}>
  <div className="pref-action-buttons">
   <button type="button" className="pref-button" disabled={!dirty||saving} onClick={discard}>{t('discard')}</button>
   <button type="button" className="pref-button primary" disabled={!dirty||saving} onClick={()=>void save()}><StudioIcon name={saving?'loader':'check'} className={saving?'is-spinning':''}/>{t(saving?'saving':'save')}</button>
  </div>
  {dirty&&<span className="pref-dirty" role="status"><i/>{t('unsaved')}</span>}
 </div>;

 return <AppLayout frame="preferences"><Head title={t('title')}/>
  <section className="pref-page">
   <header className="pref-heading"><div><h1>{t('title')}</h1><p>{t('description')}</p></div>{header}</header>
   <nav className="pref-tabs" role="tablist" aria-label={t('title')}>
    {tabs.map(key=><button key={key} type="button" role="tab" id={'pref-tab-'+key} aria-controls={'pref-panel-'+key} aria-selected={tab===key} onClick={()=>changeTab(key)}>
     <span>{t(key==='general'?'general':key==='defaults'?'studioDefaults':key==='presets'?'savedPresets':'nameDictionary')}</span>
     {key==='presets'&&<b>{data.presets.length}/{data.limits.presets}</b>}
     {key==='dictionary'&&<b>{draft.dictionary.length}/{data.limits.dictionary}</b>}
    </button>)}
   </nav>
   <div className="pref-panel" role="tabpanel" id={'pref-panel-'+tab} aria-labelledby={'pref-tab-'+tab}>
    {tab==='general'&&<GeneralTab locale={draft.locale} onLocale={locale=>setDraft(old=>({...old,locale}))} t={t}/>}
    {tab==='defaults'&&<>
     {defaultPreset&&<div className="pref-notice"><StudioIcon name="info"/><div><strong>{t('defaultPresetActive')}: “{defaultPreset.name}”</strong><span>{t('defaultPresetActiveHint')}</span></div><button type="button" className="pref-link" onClick={()=>changeTab('presets')}>{t('viewPresets')}</button></div>}
     <StudioFields values={draft.settings} onChange={patchSettings} data={data} errors={errors} prefix="settings" t={t}/>
     <ResetDefaults onReset={()=>patchSettings(withWorkflow({...data.baseline},data.baseline.workflow))} t={t}/>
    </>}
    {tab==='presets'&&<PresetsTab data={data} current={draft.settings} onChanged={presetsChanged} t={t}/>}
    {tab==='dictionary'&&<DictionaryTab entries={draft.dictionary} onChange={setDictionary} errors={errors} limit={data.limits.dictionary} defaultLanguage={draft.settings.narration_lang} t={t}/>}
   </div>
   <p className="pref-footnote"><StudioIcon name="info"/>{t('dictionaryNewProjects')}</p>
  </section>
  <MediaDialog open={!!leaving} title={t('leaveTitle')} onClose={()=>setLeaving(null)} className="pref-confirm" animated>
   <p className="pref-confirm-body">{t('leaveBody')}</p>
   <div className="pref-dialog-actions"><button type="button" className="pref-button" onClick={()=>setLeaving(null)}>{t('stay')}</button><button type="button" className="pref-button danger" onClick={()=>{const go=leaving;setLeaving(null);go?.();}}>{t('leave')}</button></div>
  </MediaDialog>
 </AppLayout>;
}

function GeneralTab({locale,onLocale,t}:{locale:PreferencesDraft['locale'];onLocale:(value:PreferencesDraft['locale'])=>void;t:Text}){
 const {app}=usePage<SharedData>().props;
 const timezone=(app as {timezone?:string}).timezone??'UTC';
 const format=(app as {time_format?:string}).time_format==='24'?t('hour24'):t('hour12');
 return <div className="pref-card pref-general">
  <div className="pref-setting"><span className="pref-setting-icon"><StudioIcon name="globe"/></span><div className="pref-setting-text"><strong>{t('interfaceLanguage')}</strong><small>{t('interfaceLanguageHint')}</small></div>
   <div className="pref-setting-control"><SelectField label={t('interfaceLanguage')} value={locale} onChange={value=>onLocale(value as PreferencesDraft['locale'])} options={(['my','en','th'] as const).map(value=>({value,label:languageNames[value],image:flags[value]}))}/></div></div>
  <div className="pref-setting"><span className="pref-setting-icon"><StudioIcon name="clock"/></span><div className="pref-setting-text"><strong>{t('dateTime')}</strong><small>{t('dateTimeHint')}</small></div>
   <div className="pref-setting-control"><ActionTooltip label={t('setByAdmin')}><div className="pref-readonly" tabIndex={0} aria-label={t('dateTime')+': '+timezone+' · '+format}><span>{timezone} · {format}</span><StudioIcon name="lock"/></div></ActionTooltip></div></div>
 </div>;
}

function ResetDefaults({onReset,t}:{onReset:()=>void;t:Text}){
 const [open,setOpen]=useState(false);
 return <div className="pref-reset"><button type="button" className="pref-link" onClick={()=>setOpen(true)}><StudioIcon name="reset"/>{t('resetRecommended')}</button>
  <MediaDialog open={open} title={t('resetRecommended')} onClose={()=>setOpen(false)} className="pref-confirm" animated><p className="pref-confirm-body">{t('resetConfirm')}</p>
   <div className="pref-dialog-actions"><button type="button" className="pref-button" onClick={()=>setOpen(false)}>{t('cancel')}</button><button type="button" className="pref-button primary" onClick={()=>{onReset();setOpen(false);}}>{t('reset')}</button></div></MediaDialog></div>;
}

function Locked({billing,t}:{billing:string;t:Text}){
 return <span className="pref-locked"><StudioIcon name="crown"/>{t('notInPlan')}<a href={billing}>{t('openBilling')}</a></span>;
}

function Field({label,children,hint,error,info}:{label:string;children:ReactNode;hint?:ReactNode;error?:string[];info?:string}){
 return <div className={'pref-field'+(error?.length?' has-error':'')}><span className="pref-field-label">{label}{info&&<ActionTooltip label={info}><button type="button" className="pref-info" aria-label={info}><StudioIcon name="info"/></button></ActionTooltip>}</span>
  <div className="pref-field-control">{children}{hint}{error?.map((message,index)=><small key={index} className="pref-error" role="alert">{message}</small>)}</div></div>;
}

/** Creation, Voice and Output cards shared by Studio Defaults and the preset editor. */
function StudioFields({values,onChange,data,errors,prefix,t,stacked=false}:{values:StudioPreferenceValues;onChange:(values:Partial<StudioPreferenceValues>)=>void;data:PreferencesData;errors:Errors;prefix:string;t:Text;stacked?:boolean}){
 const {locale}=usePage<SharedData>().props;
 const has=(feature:string)=>data.features?.[feature]!==false;
 const err=(key:string)=>errors[prefix+'.'+key];
 const subtitles=values.workflow==='subtitles',movie=values.workflow==='movie';
 const minutes=values.target_seconds/60;
 const [customDuration,setCustomDuration]=useState(()=>!durationChoices.includes(values.target_seconds as never));
 const look=subtitleLookOf(values);
 const outputs=outputsFor(values.workflow).filter(value=>has(outputFeature[value])||value===values.output);
 return <div className={'pref-defaults'+(stacked?' is-stacked':'')}>
  <section className="pref-card pref-creation"><h2><StudioIcon name="triangle-play"/>{t('creation')}</h2>
   <Field label={t('defaultCreationType')} error={err('workflow')}><div className="pref-segment" role="group" aria-label={t('defaultCreationType')}>
    {workflows.map(workflow=>{const allowed=has(workflowFeature[workflow]);const button=<button key={workflow} type="button" aria-pressed={values.workflow===workflow} className={values.workflow===workflow?'selected':''} disabled={!allowed} onClick={()=>onChange(withWorkflow(values,workflow as Workflow))}>{!allowed&&<StudioIcon name="crown"/>}{t(workflow)}</button>;
     return allowed?<ActionTooltip key={workflow} label={t(workflow==='movie'?'movieLong':workflow==='translate'?'translateLong':'subtitles')}>{button}</ActionTooltip>:<ActionTooltip key={workflow} label={t('notInPlan')}><span className="pref-disabled-wrap">{button}</span></ActionTooltip>;})}
   </div></Field>
   <Field label={t('narrationLanguage')} error={err('narration_lang')}><SelectField label={t('narrationLanguage')} value={values.narration_lang} onChange={narration_lang=>onChange({narration_lang})} options={narrationLanguages.map(value=>({value,label:languageNames[value],image:flags[value]}))}/></Field>
   <Field label={t('subtitleLanguage')} error={err('subtitle_lang')}><SelectField label={t('subtitleLanguage')} value={values.subtitle_lang} onChange={subtitle_lang=>onChange({subtitle_lang})} options={[{value:'same',label:t('sameAsNarration')},...narrationLanguages.map(value=>({value,label:languageNames[value],image:flags[value]}))]}/></Field>
   <Field label={t('targetDuration')} error={err('target_seconds')} hint={!movie?<small className="pref-muted">{t('onlySummarize')}</small>:undefined}><div className={'pref-chips'+(movie?'':' is-disabled')} role="group" aria-label={t('targetDuration')}>
    {durationChoices.map(seconds=><button key={seconds} type="button" disabled={!movie} aria-pressed={!customDuration&&values.target_seconds===seconds} className={!customDuration&&values.target_seconds===seconds?'selected':''} onClick={()=>{setCustomDuration(false);onChange({target_seconds:seconds});}}>{seconds/60} {t('min')}</button>)}
    <button type="button" disabled={!movie} aria-pressed={customDuration} className={customDuration?'selected':''} onClick={()=>setCustomDuration(true)}>{t('custom')}</button>
    {customDuration&&movie&&<label className="pref-custom-minutes"><input type="number" min={.5} max={60} step={.5} value={Number(minutes.toFixed(2))} aria-label={t('customMinutes')} onChange={event=>{const value=Number(event.target.value);if(Number.isFinite(value))onChange({target_seconds:Math.round(Math.min(60,Math.max(.5,value))*60)});}}/><span>{t('min')}</span></label>}
   </div></Field>
   <Field label={t('narrationStyle')} info={t('narrationStyleHint')} error={err('narration_style')} hint={subtitles?<small className="pref-muted">{t('notForSubtitles')}</small>:undefined}><div className={'pref-styles'+(subtitles?' is-disabled':'')} role="radiogroup" aria-label={t('narrationStyle')}>
    {narrationStyles.map(style=><button key={style} type="button" role="radio" aria-checked={values.narration_style===style} disabled={subtitles} className={values.narration_style===style?'selected':''} onClick={()=>onChange({narration_style:style})}>
     <img src={styleImage(style)} alt="" loading="eager" decoding="async" width={192} height={128}/><span>{t(style==='custom'?'custom':style as PreferenceWord)}</span>{values.narration_style===style&&<i><StudioIcon name="check"/></i>}
    </button>)}
   </div></Field>
  </section>
  <div className="pref-side">
   <VoiceCard values={values} onChange={onChange} data={data} err={err} disabled={subtitles} locale={locale} t={t}/>
   <section className="pref-card pref-output"><h2><StudioIcon name="layers"/>{t('output')}</h2>
    <Field label={t('defaultFormat')} error={err('output')}><SelectField label={t('defaultFormat')} value={values.output} onChange={output=>onChange({output:output as StudioPreferenceValues['output']})} options={outputs.map(value=>({value,label:t(value),detail:value==='burn'?t('burnDetail'):undefined}))}/></Field>
    <Field label={t('defaultQuality')} error={err('quality')} hint={!has('layout')?<Locked billing={data.endpoints.billing} t={t}/>:undefined}><SelectField label={t('defaultQuality')} value={values.quality} disabled={!has('layout')} onChange={quality=>onChange({quality:quality as StudioPreferenceValues['quality']})} options={(['source','720p','1080p'] as const).map(value=>({value,label:t(value==='source'?'qualitySource':value==='720p'?'quality720p':'quality1080p')}))}/></Field>
    <Field label={t('defaultSubtitleStyle')} error={err('subtitle_font')??err('subtitle_position')} hint={!has('subtitle_style')?<Locked billing={data.endpoints.billing} t={t}/>:undefined}><SelectField label={t('defaultSubtitleStyle')} value={look} disabled={!has('subtitle_style')} onChange={value=>{if(value!=='custom')onChange(subtitleLooks[value as keyof typeof subtitleLooks]);}}
     options={[...(Object.keys(subtitleLooks) as (keyof typeof subtitleLooks)[]).map(value=>({value,label:t(value)})),...(look==='custom'?[{value:'custom',label:t('customLook')}]:[])] as {value:SubtitleLookKey;label:string}[]}/></Field>
   </section>
  </div>
 </div>;
}

const sampleCache=new Map<string,string>();
function VoiceCard({values,onChange,data,err,disabled,locale,t}:{values:StudioPreferenceValues;onChange:(values:Partial<StudioPreferenceValues>)=>void;data:PreferencesData;err:(key:string)=>string[]|undefined;disabled:boolean;locale:string;t:Text}){
 const toast=useToast();
 const [state,setState]=useState<'idle'|'loading'|'playing'>('idle');
 const audio=useRef<HTMLAudioElement|null>(null);const request=useRef<AbortController|null>(null);
 const canSample=data.voiceSampleReady&&data.features?.voice_preview!==false&&!disabled;
 useEffect(()=>()=>{request.current?.abort();audio.current?.pause();},[]);
 useEffect(()=>{if(audio.current)audio.current.playbackRate=values.speech_rate;},[values.speech_rate]);
 useEffect(()=>{audio.current?.pause();setState('idle');},[values.voice,values.narration_lang]);
 async function toggle(){
  if(state!=='idle'){request.current?.abort();audio.current?.pause();setState('idle');return;}
  const key=values.voice+'|'+values.narration_lang;setState('loading');
  try{
   let url=sampleCache.get(key);
   if(!url){const controller=new AbortController();request.current=controller;const response=await fetch(data.endpoints.voiceSample+'?voice='+encodeURIComponent(values.voice)+'&lang='+encodeURIComponent(values.narration_lang),{signal:controller.signal,headers:{Accept:'audio/wav, application/json'},credentials:'same-origin'});
    if(!response.ok){toast.error(response.status===429?t('rateLimited'):response.status===401||response.status===419?t('sessionExpired'):t('sampleUnavailable'));setState('idle');return;}
    url=URL.createObjectURL(await response.blob());sampleCache.set(key,url);}
   const player=audio.current??new Audio();audio.current=player;player.src=url;player.playbackRate=values.speech_rate;player.preservesPitch=true;player.onended=()=>setState('idle');
   await player.play();setState('playing');
  }catch(error){if(!(error instanceof DOMException&&error.name==='AbortError')){toast.error(t('sampleUnavailable'));}setState('idle');}
 }
 const sampleLabel=state==='playing'?t('stopListening'):state==='loading'?t('sampleLoading'):t('listen');
 return <section className="pref-card pref-voice"><h2><StudioIcon name="mic"/>{t('voice')}</h2>
  {disabled&&<p className="pref-muted pref-card-note">{t('notForSubtitles')}</p>}
  <Field label={t('narratorVoice')} error={err('voice')}><div className="pref-voice-row"><SelectField label={t('narratorVoice')} value={values.voice} disabled={disabled} onChange={voice=>onChange({voice})} options={data.voices.map(value=>({value,label:voiceLabel(value,locale)}))}/>
   <ActionTooltip label={canSample?sampleLabel+' · '+t('sampleHint'):t('sampleUnavailable')}><button type="button" className={'pref-play'+(state!=='idle'?' is-active':'')} aria-label={sampleLabel} disabled={!canSample} onClick={()=>void toggle()}><StudioIcon name={state==='loading'?'loader':state==='playing'?'pause':'triangle-play'} className={state==='loading'?'is-spinning':''}/></button></ActionTooltip></div></Field>
  <Slider label={t('voiceSpeed')} value={values.speech_rate} min={.5} max={2} step={.05} display={value=>value.toFixed(value%.1===0?1:2).replace(/0$/,'')+'×'} disabled={disabled} error={err('speech_rate')} onChange={speech_rate=>onChange({speech_rate})}/>
  <Slider label={t('voicePitch')} value={values.voice_pitch} min={-4} max={4} step={.5} display={value=>(value>0?'+':'')+value} disabled={disabled} error={err('voice_pitch')} onChange={voice_pitch=>onChange({voice_pitch})}/>
 </section>;
}

function Slider({label,value,min,max,step,display,onChange,disabled,error}:{label:string;value:number;min:number;max:number;step:number;display:(value:number)=>string;onChange:(value:number)=>void;disabled?:boolean;error?:string[]}){
 const percent=(value-min)/(max-min)*100;
 return <Field label={label} error={error}><div className="pref-slider"><input type="range" min={min} max={max} step={step} value={value} disabled={disabled} aria-label={label} style={{'--fill':percent+'%'} as React.CSSProperties} onChange={event=>onChange(Number(event.target.value))}/><output>{display(value)}</output></div></Field>;
}

function presetSummary(settings:StudioPreferenceValues,t:Text){
 const parts=[t(settings.workflow),languageShort[settings.narration_lang]??settings.narration_lang];
 if(settings.workflow==='movie')parts.push(Math.round(settings.target_seconds/60*10)/10+' '+t('min'));
 if(settings.workflow==='subtitles')parts.push(settings.subtitle_lang==='same'?t('sameAsNarration'):languageShort[settings.subtitle_lang]??settings.subtitle_lang);
 else parts.push(t(settings.narration_style as PreferenceWord));
 parts.push(settings.output==='burn'?'MP4 ('+(settings.quality==='source'?t('qualitySource'):settings.quality)+')':t(settings.output));
 return parts;
}
function PresetThumb({settings}:{settings:StudioPreferenceValues}){
 return settings.workflow==='movie'?<img className="pref-thumb" src={styleImage(settings.narration_style)} alt="" decoding="async"/>:<span className={'pref-thumb is-icon is-'+settings.workflow}><StudioIcon name={settings.workflow==='subtitles'?'captions':'globe'}/></span>;
}

type Sort='custom'|'newest'|'oldest'|'name';
function PresetsTab({data,current,onChanged,t}:{data:PreferencesData;current:StudioPreferenceValues;onChanged:(data:PreferencesData)=>void;t:Text}){
 const toast=useToast();
 const [query,setQuery]=useState(''),[sort,setSort]=useState<Sort>('custom'),[page,setPage]=useState(1);
 const [editor,setEditor]=useState<{preset:PreferencePreset|null}|null>(null),[removing,setRemoving]=useState<PreferencePreset|null>(null),[busy,setBusy]=useState<number|'order'|null>(null);
 const [order,setOrder]=useState<number[]>(()=>data.presets.map(preset=>preset.id));const [dragging,setDragging]=useState<number|null>(null);
 const rows=useRef(new Map<number,HTMLElement>());const startOrder=useRef<number[]>([]);
 useEffect(()=>{setOrder(data.presets.map(preset=>preset.id));},[data.presets]);
 const byId=new Map(data.presets.map(preset=>[preset.id,preset]));
 const term=query.trim().toLocaleLowerCase();
 const ordered=order.map(id=>byId.get(id)).filter((preset):preset is PreferencePreset=>!!preset);
 const list=ordered.filter(preset=>!term||preset.name.toLocaleLowerCase().includes(term)||presetSummary(preset.settings,t).join(' ').toLocaleLowerCase().includes(term));
 if(sort!=='custom')list.sort((a,b)=>sort==='name'?a.name.localeCompare(b.name):(sort==='newest'?-1:1)*((Date.parse(a.created_at??'')||a.id)-(Date.parse(b.created_at??'')||b.id)||a.id-b.id));
 const pages=Math.max(1,Math.ceil(list.length/pageSize));const current_page=Math.min(page,pages);
 const visible=list.slice((current_page-1)*pageSize,current_page*pageSize);
 const canReorder=sort==='custom'&&!term&&busy===null;
 const full=data.presets.length>=data.limits.presets;

 async function run(id:number|'order',request:()=>Promise<{preferences:PreferencesData}>,success?:string){
  setBusy(id);try{const result=await request();onChanged(result.preferences);if(success)toast.success(success);return true;}catch(error){toast.error(apiMessage(error,t));return false;}finally{setBusy(null);}
 }
 function persistOrder(next:number[]){if(next.join()===startOrder.current.join())return;void run('order',()=>mediaApi(data.endpoints.presetOrder,'PUT',{ids:next})).then(ok=>{if(!ok)setOrder(startOrder.current);});}
 function move(id:number,delta:number){if(!canReorder)return;const index=order.indexOf(id),target=index+delta;if(target<0||target>=order.length)return;const next=[...order];next.splice(index,1);next.splice(target,0,id);startOrder.current=order;setOrder(next);persistOrder(next);}
 function dragStart(event:React.PointerEvent,id:number){if(!canReorder||event.button!==0)return;event.preventDefault();event.currentTarget.setPointerCapture(event.pointerId);startOrder.current=order;setDragging(id);}
 function dragMove(event:React.PointerEvent){
  if(dragging===null)return;
  const target=visible.find(preset=>{const element=rows.current.get(preset.id);if(!element||preset.id===dragging)return false;const box=element.getBoundingClientRect();return event.clientY>box.top&&event.clientY<box.bottom;});
  if(!target)return;
  setOrder(old=>{const from=old.indexOf(dragging),to=old.indexOf(target.id);if(from<0||to<0||from===to)return old;const next=[...old];next.splice(from,1);next.splice(to,0,dragging);return next;});
 }
 function dragEnd(){if(dragging===null)return;setDragging(null);persistOrder(order);}

 return <section className="pref-card pref-presets">
  <header className="pref-section-head"><span className="pref-head-icon"><StudioIcon name="layers"/></span><div><h2>{t('savedPresets')}</h2><p>{t('presetsHint')}</p></div>
   <div className="pref-section-tools">
    <label className="pref-search"><StudioIcon name="search"/><input value={query} placeholder={t('searchPreset')} aria-label={t('searchPreset')} onChange={event=>{setQuery(event.target.value);setPage(1);}}/>{query&&<button type="button" aria-label={t('clearSearch')} onClick={()=>setQuery('')}><StudioIcon name="close"/></button>}</label>
    <div className="pref-select"><SelectField label={t('sortBy')} value={sort} onChange={value=>{setSort(value as Sort);setPage(1);}} options={(['custom','newest','oldest','name'] as const).map(value=>({value,label:t(value==='custom'?'sortCustom':value==='newest'?'sortNewest':value==='oldest'?'sortOldest':'sortName')}))}/></div>
    <ActionTooltip label={full?t('presetLimit'):t('addPreset')}><span className="pref-disabled-wrap"><button type="button" className="pref-button primary" disabled={full} onClick={()=>setEditor({preset:null})}><StudioIcon name="plus"/>{t('addPreset')}</button></span></ActionTooltip>
   </div>
  </header>
  {full&&<p className="pref-limit"><StudioIcon name="info"/>{t('presetLimit')}</p>}
  {!data.presets.length?<div className="pref-empty"><span><StudioIcon name="layers"/></span><h3>{t('noPresets')}</h3><p>{t('noPresetsHint')}</p><button type="button" className="pref-button primary" onClick={()=>setEditor({preset:null})}><StudioIcon name="plus"/>{t('addPreset')}</button></div>
   :!list.length?<div className="pref-empty"><span><StudioIcon name="search"/></span><h3>{t('noPresetMatches')}</h3><button type="button" className="pref-button" onClick={()=>setQuery('')}>{t('clearSearch')}</button></div>
   :<ol className={'pref-preset-list'+(dragging!==null?' is-dragging':'')} onPointerMove={dragMove} onPointerUp={dragEnd} onPointerCancel={dragEnd}>
    {visible.map(preset=><li key={preset.id} ref={element=>{if(element)rows.current.set(preset.id,element);else rows.current.delete(preset.id);}} className={'pref-preset'+(dragging===preset.id?' is-dragged':'')+(preset.is_default?' is-default':'')}>
     <ActionTooltip label={canReorder?t('dragToReorder'):t('reorderOnlyCustom')}><button type="button" className="pref-drag" aria-label={t('dragToReorder')+' · '+preset.name} disabled={!canReorder} onPointerDown={event=>dragStart(event,preset.id)} onKeyDown={event=>{if(event.key==='ArrowUp'){event.preventDefault();move(preset.id,-1);}if(event.key==='ArrowDown'){event.preventDefault();move(preset.id,1);}}}><StudioIcon name="grip"/></button></ActionTooltip>
     <PresetThumb settings={preset.settings}/>
     <div className="pref-preset-main"><strong>{preset.name}{preset.is_default&&<em>{t('defaultBadge')}</em>}</strong><span>{presetSummary(preset.settings,t).map((part,index)=><span key={index}>{part}</span>)}</span></div>
     <div className="pref-preset-actions">
      <ActionTooltip label={t('startWithPreset')}><button type="button" className="pref-round" aria-label={t('startWithPreset')+' · '+preset.name} onClick={()=>router.visit(data.endpoints.newProject+'?preset='+preset.id)}><StudioIcon name="triangle-play"/></button></ActionTooltip>
      <ActionTooltip label={t('editPreset')}><button type="button" className="pref-icon" aria-label={t('editPreset')+' · '+preset.name} disabled={busy!==null} onClick={()=>setEditor({preset})}><StudioIcon name="edit"/></button></ActionTooltip>
      <ActionTooltip label={full?t('presetLimit'):t('duplicatePreset')}><span className="pref-disabled-wrap"><button type="button" className="pref-icon" aria-label={t('duplicatePreset')+' · '+preset.name} disabled={busy!==null||full} onClick={()=>void run(preset.id,()=>mediaApi(data.endpoints.presets+'/'+preset.id+'/duplicate','POST'),t('presetDuplicated'))}><StudioIcon name={busy===preset.id?'loader':'copy'} className={busy===preset.id?'is-spinning':''}/></button></span></ActionTooltip>
      <ActionTooltip label={t('deletePreset')}><button type="button" className="pref-icon danger" aria-label={t('deletePreset')+' · '+preset.name} disabled={busy!==null} onClick={()=>setRemoving(preset)}><StudioIcon name="trash"/></button></ActionTooltip>
      <ActionTooltip label={preset.is_default?t('defaultOn'):t('useAsDefault')}><label className="pref-switch"><input type="checkbox" role="switch" checked={preset.is_default} disabled={busy!==null} aria-label={t('useAsDefault')+' · '+preset.name}
       onChange={event=>void run(preset.id,()=>mediaApi(data.endpoints.defaultPreset,'PUT',{preset_id:event.target.checked?preset.id:null}),event.target.checked?t('defaultOn'):t('defaultOff'))}/><i/></label></ActionTooltip>
     </div>
    </li>)}
   </ol>}
  {list.length>0&&<Pagination page={current_page} pages={pages} total={list.length} onPage={setPage} t={t}/>}
  {editor&&<PresetEditor preset={editor.preset} start={current} data={data} onClose={()=>setEditor(null)} onSaved={next=>{onChanged(next);setEditor(null);toast.success(t('presetSaved'));}} t={t}/>}
  <MediaDialog open={!!removing} title={t('deletePreset')} onClose={()=>{if(busy===null)setRemoving(null);}} className="pref-confirm" animated>
   <p className="pref-confirm-body"><strong>{removing?.name}</strong><br/>{t('deletePresetBody')}</p>
   <div className="pref-dialog-actions"><button type="button" className="pref-button" disabled={busy!==null} onClick={()=>setRemoving(null)}>{t('cancel')}</button><button type="button" className="pref-button danger" disabled={busy!==null} onClick={()=>{const target=removing;if(target)void run(target.id,()=>mediaApi(data.endpoints.presets+'/'+target.id,'DELETE'),t('presetDeleted')).then(ok=>{if(ok)setRemoving(null);});}}>{busy!==null&&<StudioIcon name="loader" className="is-spinning"/>}{t('delete')}</button></div>
  </MediaDialog>
 </section>;
}

function PresetEditor({preset,start,data,onClose,onSaved,t}:{preset:PreferencePreset|null;start:StudioPreferenceValues;data:PreferencesData;onClose:()=>void;onSaved:(data:PreferencesData)=>void;t:Text}){
 const toast=useToast();
 const [name,setName]=useState(preset?.name??''),[values,setValues]=useState<StudioPreferenceValues>(()=>({...(preset?.settings??start)})),[errors,setErrors]=useState<Errors>({}),[busy,setBusy]=useState(false);
 const nameInput=useRef<HTMLInputElement>(null);
 async function submit(){
  if(busy)return;
  if(!name.trim()){setErrors({name:[t('required')]});nameInput.current?.focus();return;}
  setBusy(true);setErrors({});
  try{const result=await mediaApi<{preferences:PreferencesData}>(preset?data.endpoints.presets+'/'+preset.id:data.endpoints.presets,preset?'PUT':'POST',{name:name.trim(),settings:values});onSaved(result.preferences);}
  catch(error){const fields=error instanceof MediaApiError?error.fields:{};setErrors(fields);toast.error(apiMessage(error,t));if(fields.name)nameInput.current?.focus();}
  finally{setBusy(false);}
 }
 return <MediaDialog open title={preset?t('editPreset'):t('newPreset')} headerIcon="layers" onClose={()=>{if(!busy)onClose();}} className="pref-preset-dialog" animated>
  <form className="pref-dialog-form" onSubmit={event=>{event.preventDefault();void submit();}}>
   <div className="pref-dialog-body">
    <label className={'pref-text-field'+(errors.name?' has-error':'')}><span>{t('presetName')} <em>*</em></span><input ref={nameInput} value={name} maxLength={60} placeholder={t('presetNamePlaceholder')} autoFocus onChange={event=>{setName(event.target.value);setErrors(old=>({...old,name:[]}));}}/>{errors.name?.map((message,index)=><small key={index} className="pref-error" role="alert">{message}</small>)}</label>
    {!preset&&<p className="pref-muted">{t('presetFromDefaults')}</p>}
    <StudioFields values={values} onChange={patch=>setValues(old=>({...old,...patch}))} data={data} errors={errors} prefix="settings" t={t} stacked/>
   </div>
   <footer className="pref-dialog-actions"><button type="button" className="pref-button" disabled={busy} onClick={onClose}>{t('cancel')}</button><button type="submit" className="pref-button primary" disabled={busy}><StudioIcon name={busy?'loader':'check'} className={busy?'is-spinning':''}/>{t('savePreset')}</button></footer>
  </form>
 </MediaDialog>;
}

function Pagination({page,pages,total,onPage,t}:{page:number;pages:number;total:number;onPage:(page:number)=>void;t:Text}){
 const from=(page-1)*pageSize+1,to=Math.min(total,page*pageSize);
 const numbers=Array.from(new Set([1,page-1,page,page+1,pages])).filter(value=>value>=1&&value<=pages).sort((a,b)=>a-b);
 return <footer className="pref-pagination"><span>{t('showing')} {from}–{to} {t('of')} {total}</span>
  <nav aria-label={t('showing')}>
   <button type="button" disabled={page<=1} onClick={()=>onPage(1)}>{t('first')}</button>
   <button type="button" disabled={page<=1} onClick={()=>onPage(page-1)}><StudioIcon name="back"/>{t('previous')}</button>
   {numbers.map((value,index)=><span key={value}>{index>0&&value>numbers[index-1]+1&&<i>…</i>}<button type="button" aria-current={value===page?'page':undefined} onClick={()=>onPage(value)}>{value}</button></span>)}
   <button type="button" disabled={page>=pages} onClick={()=>onPage(page+1)}>{t('next')}<StudioIcon name="arrow"/></button>
   <button type="button" disabled={page>=pages} onClick={()=>onPage(pages)}>{t('last')}</button>
  </nav></footer>;
}

type EntryType=NonNullable<NameDictionaryEntry['type']>;
const entryTypes:EntryType[]=['character','place','term','other'];
const typeIcons:Record<EntryType,IconName>={character:'users',place:'map-pin',term:'book',other:'more'};
type DictSort={key:'original'|'translated'|'type'|'language';direction:1|-1};

function csvCell(value:string){return /[",\n\r]/.test(value)?'"'+value.replace(/"/g,'""')+'"':value;}
function parseCsv(text:string):string[][]{
 const rows:string[][]=[];let row:string[]=[],cell='',quoted=false;
 for(let index=0;index<text.length;index++){const char=text[index];
  if(quoted){if(char==='"'&&text[index+1]==='"'){cell+='"';index++;}else if(char==='"')quoted=false;else cell+=char;continue;}
  if(char==='"')quoted=true;else if(char===','){row.push(cell);cell='';}else if(char==='\n'||char==='\r'){if(char==='\r'&&text[index+1]==='\n')index++;row.push(cell);rows.push(row);row=[];cell='';}else cell+=char;}
 if(cell!==''||row.length){row.push(cell);rows.push(row);}
 return rows.filter(cells=>cells.some(value=>value.trim()!==''));
}
function download(name:string,content:string){const url=URL.createObjectURL(new Blob(['﻿'+content],{type:'text/csv;charset=utf-8'}));const link=document.createElement('a');link.href=url;link.download=name;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
const csvColumns=['original','translation','type','language','note','pronunciation'];

function DictionaryTab({entries,onChange,errors,limit,defaultLanguage,t}:{entries:NameDictionaryEntry[];onChange:(entries:NameDictionaryEntry[])=>void;errors:Errors;limit:number;defaultLanguage:string;t:Text}){
 const toast=useToast();
 const [query,setQuery]=useState(''),[type,setType]=useState(''),[language,setLanguage]=useState(''),[sort,setSort]=useState<DictSort|null>(null),[page,setPage]=useState(1);
 const [selected,setSelected]=useState<Set<number>>(new Set()),[editing,setEditing]=useState<{index:number|null}|null>(null),[confirm,setConfirm]=useState(false),[importOpen,setImportOpen]=useState(false);
 const picker=useRef<HTMLInputElement>(null);const importMenu=useRef<HTMLDivElement>(null);
 useEffect(()=>{if(!importOpen)return;const close=(event:PointerEvent)=>{if(!importMenu.current?.contains(event.target as Node))setImportOpen(false);};const escape=(event:KeyboardEvent)=>{if(event.key==='Escape')setImportOpen(false);};document.addEventListener('pointerdown',close);document.addEventListener('keydown',escape);return()=>{document.removeEventListener('pointerdown',close);document.removeEventListener('keydown',escape);};},[importOpen]);
 useEffect(()=>{setSelected(old=>new Set([...old].filter(index=>index<entries.length)));},[entries.length]);
 const term=query.trim().toLocaleLowerCase();
 const rows=entries.map((entry,index)=>({entry,index})).filter(({entry})=>(!term||[entry.original,entry.translated,entry.note??'',entry.pronunciation??''].some(value=>value.toLocaleLowerCase().includes(term)))&&(!type||(entry.type??'other')===type)&&(!language||entry.language===language));
 if(sort)rows.sort((a,b)=>sort.direction*String(sort.key==='type'?a.entry.type??'':sort.key==='language'?a.entry.language??'':a.entry[sort.key]).localeCompare(String(sort.key==='type'?b.entry.type??'':sort.key==='language'?b.entry.language??'':b.entry[sort.key])));
 const pages=Math.max(1,Math.ceil(rows.length/pageSize));const current=Math.min(page,pages);
 const visible=rows.slice((current-1)*pageSize,current*pageSize);
 const errorIndexes=new Set(Object.keys(errors).filter(key=>key.startsWith('name_dictionary.')).map(key=>Number(key.split('.')[1])));
 const full=entries.length>=limit;
 const filtersActive=!!(term||type||language);
 const usedLanguages=Array.from(new Set(entries.map(entry=>entry.language).filter((value):value is string=>!!value)));
 const allVisibleSelected=visible.length>0&&visible.every(row=>selected.has(row.index));

 function header(key:DictSort['key'],label:string){const active=sort?.key===key;return <button type="button" className={'pref-sort'+(active?' is-active':'')} aria-label={t('sortBy')+' '+label} onClick={()=>setSort(active&&sort.direction===-1?null:{key,direction:active?-1:1})}>{label}<StudioIcon name={active?(sort.direction===1?'move-up':'move-down'):'sort'}/></button>;}
 function toggleSelected(index:number){setSelected(old=>{const next=new Set(old);if(next.has(index))next.delete(index);else next.add(index);return next;});}
 function removeIndexes(indexes:Set<number>){onChange(entries.filter((_,index)=>!indexes.has(index)));setSelected(new Set());}
 function exportCsv(){download('zatlan-name-dictionary.csv',[csvColumns.join(','),...entries.map(entry=>[entry.original,entry.translated,entry.type??'',entry.language??'',entry.note??'',entry.pronunciation??''].map(csvCell).join(','))].join('\r\n'));}
 async function importCsv(file:File){
  const table=parseCsv(await file.text());const head=(table[0]??[]).map(value=>value.trim().toLowerCase());
  const column=(...names:string[])=>head.findIndex(value=>names.includes(value));
  const originalAt=column('original','name','name (original)'),translatedAt=column('translation','translated','preferred','preferred name','translation / preferred name');
  if(originalAt<0||translatedAt<0){toast.error(t('importInvalid'));return;}
  const typeAt=column('type'),languageAt=column('language'),noteAt=column('note'),pronunciationAt=column('pronunciation');
  const next=[...entries];const known=new Set(entries.map(entry=>entry.original.toLocaleLowerCase()));let skipped=0;
  for(const cells of table.slice(1)){
   const original=(cells[originalAt]??'').trim().slice(0,100),translated=(cells[translatedAt]??'').trim().slice(0,100);
   if(!original||!translated||known.has(original.toLocaleLowerCase())||next.length>=limit){skipped++;continue;}
   const entryType=(cells[typeAt]??'').trim().toLowerCase();const entryLanguage=(cells[languageAt]??'').trim().toLowerCase();
   next.push({original,translated,pronunciation:(cells[pronunciationAt]??'').trim().slice(0,100),type:entryTypes.includes(entryType as EntryType)?entryType as EntryType:'other',language:(narrationLanguages as readonly string[]).includes(entryLanguage)?entryLanguage:defaultLanguage,note:(cells[noteAt]??'').trim().slice(0,160)});
   known.add(original.toLocaleLowerCase());
  }
  onChange(next);toast.success(t('importDone')+(skipped?' · '+skipped+' '+t('importSkipped'):''));
 }

 return <section className="pref-card pref-dictionary">
  <header className="pref-section-head"><span className="pref-head-icon"><StudioIcon name="book"/></span><div><h2>{t('nameDictionary')}</h2><p>{t('dictionaryHint')}</p></div>
   <div className="pref-section-tools">
    <div className="pref-menu-wrap" ref={importMenu}><button type="button" className="pref-button" aria-haspopup="menu" aria-expanded={importOpen} onClick={()=>setImportOpen(!importOpen)}>{t('import')}<StudioIcon name="chevron"/></button>
     {importOpen&&<div className="pref-menu" role="menu"><button type="button" role="menuitem" disabled={full} onClick={()=>{setImportOpen(false);picker.current?.click();}}><StudioIcon name="upload"/>{t('importCsv')}</button><button type="button" role="menuitem" onClick={()=>{setImportOpen(false);download('zatlan-name-dictionary-template.csv',csvColumns.join(',')+'\r\nSiddhartha,သိဒ္ဓတ္ထ,character,my,The Buddha before enlightenment,');}}><StudioIcon name="download"/>{t('downloadTemplate')}</button></div>}
     <input ref={picker} type="file" accept=".csv,text/csv" hidden onChange={event=>{const file=event.target.files?.[0];event.target.value='';if(file)void importCsv(file);}}/></div>
    <button type="button" className="pref-button" disabled={!entries.length} onClick={exportCsv}><StudioIcon name="download"/>{t('export')}</button>
    <ActionTooltip label={full?t('dictionaryFull'):t('addName')}><span className="pref-disabled-wrap"><button type="button" className="pref-button primary" disabled={full} onClick={()=>setEditing({index:null})}><StudioIcon name="plus"/>{t('addName')}</button></span></ActionTooltip>
   </div>
  </header>
  {full&&<p className="pref-limit"><StudioIcon name="info"/>{t('dictionaryFull')}</p>}
  {entries.length>0&&<div className="pref-filters">
   <label className="pref-search"><StudioIcon name="search"/><input value={query} placeholder={t('searchName')} aria-label={t('searchName')} onChange={event=>{setQuery(event.target.value);setPage(1);}}/>{query&&<button type="button" aria-label={t('clearSearch')} onClick={()=>setQuery('')}><StudioIcon name="close"/></button>}</label>
   <div className="pref-select"><SelectField label={t('type')} value={type} onChange={value=>{setType(value);setPage(1);}} options={[{value:'',label:t('typeAll')},...entryTypes.map(value=>({value,label:t(value)}))]}/></div>
   <div className="pref-select"><SelectField label={t('language')} value={language} onChange={value=>{setLanguage(value);setPage(1);}} options={[{value:'',label:t('languageAll')},...usedLanguages.map(value=>({value,label:languageShort[value]??value}))]}/></div>
   <span className="pref-count">{rows.length?t('showing')+' '+((current-1)*pageSize+1)+'–'+Math.min(rows.length,current*pageSize)+' '+t('of')+' '+rows.length:''}</span>
  </div>}
  {!entries.length?<div className="pref-empty"><span><StudioIcon name="file"/></span><h3>{t('noNames')}</h3><p>{t('noNamesHint')}</p><button type="button" className="pref-button primary" onClick={()=>setEditing({index:null})}><StudioIcon name="plus"/>{t('addName')}</button></div>
   :!rows.length?<div className="pref-empty"><span><StudioIcon name="search"/></span><h3>{t('noNameMatches')}</h3><button type="button" className="pref-button" onClick={()=>{setQuery('');setType('');setLanguage('');}}>{t('clearFilters')}</button></div>
   :<div className="pref-table" role="table" aria-label={t('nameDictionary')}>
    <div className="pref-row pref-row-head" role="row"><span role="columnheader"><input type="checkbox" checked={allVisibleSelected} aria-label={t('selectAll')} onChange={()=>setSelected(old=>{const next=new Set(old);visible.forEach(row=>allVisibleSelected?next.delete(row.index):next.add(row.index));return next;})}/></span>
     <span role="columnheader">{header('original',t('nameOriginal'))}</span><span role="columnheader">{header('translated',t('translation'))}</span><span role="columnheader">{header('type',t('type'))}</span><span role="columnheader">{header('language',t('language'))}</span><span role="columnheader">{t('note')}</span><span role="columnheader" className="sr-only">{t('actions')}</span></div>
    {visible.map(({entry,index})=><div key={index} role="row" className={'pref-row'+(selected.has(index)?' is-selected':'')+(errorIndexes.has(index)?' has-error':'')}>
     <span role="cell"><input type="checkbox" checked={selected.has(index)} aria-label={t('selectRow')+' '+entry.original} onChange={()=>toggleSelected(index)}/></span>
     <span role="cell" className="pref-cell-name" dir="auto">{entry.original}{entry.pronunciation&&<small>/{entry.pronunciation}/</small>}</span>
     <span role="cell" className="pref-cell-translation" dir="auto">{entry.translated}</span>
     <span role="cell"><span className={'pref-type is-'+(entry.type??'other')}><StudioIcon name={typeIcons[entry.type??'other']}/>{t(entry.type??'other')}</span></span>
     <span role="cell">{entry.language?<span className="pref-lang">{languageShort[entry.language]??entry.language}</span>:<span className="pref-muted">—</span>}</span>
     <span role="cell" className="pref-cell-note" dir="auto">{entry.note||<span className="pref-muted">—</span>}</span>
     <span role="cell" className="pref-row-actions">
      <ActionTooltip label={t('editName')}><button type="button" className="pref-icon" aria-label={t('editName')+' '+entry.original} onClick={()=>setEditing({index})}><StudioIcon name="edit"/></button></ActionTooltip>
      <ActionTooltip label={t('deleteName')}><button type="button" className="pref-icon danger" aria-label={t('deleteName')+' '+entry.original} onClick={()=>removeIndexes(new Set([index]))}><StudioIcon name="trash"/></button></ActionTooltip>
     </span>
     {errorIndexes.has(index)&&<small className="pref-error pref-row-error" role="alert">{Object.entries(errors).filter(([key])=>key.startsWith('name_dictionary.'+index+'.')).flatMap(([,messages])=>messages)[0]}</small>}
    </div>)}
   </div>}
  {rows.length>0&&<div className="pref-table-footer">
   <label className="pref-select-all"><input type="checkbox" checked={selected.size>0&&selected.size===rows.length} onChange={()=>setSelected(selected.size===rows.length?new Set():new Set(rows.map(row=>row.index)))}/>{t('selectAll')}</label>
   <button type="button" className="pref-button" disabled={!selected.size} onClick={()=>setConfirm(true)}><StudioIcon name="trash"/>{t('deleteSelected')}{selected.size>0&&' ('+selected.size+')'}</button>
   <Pagination page={current} pages={pages} total={rows.length} onPage={setPage} t={t}/>
  </div>}
  {editing&&<NameEditor entries={entries} index={editing.index} defaultLanguage={defaultLanguage} onClose={()=>setEditing(null)} onApply={entry=>{onChange(editing.index===null?[...entries,entry]:entries.map((old,index)=>index===editing.index?entry:old));setEditing(null);}} t={t}/>}
  <MediaDialog open={confirm} title={t('deleteSelected')} onClose={()=>setConfirm(false)} className="pref-confirm" animated><p className="pref-confirm-body">{t('deleteSelectedBody')}</p>
   <div className="pref-dialog-actions"><button type="button" className="pref-button" onClick={()=>setConfirm(false)}>{t('cancel')}</button><button type="button" className="pref-button danger" onClick={()=>{removeIndexes(selected);setConfirm(false);}}>{t('delete')} ({selected.size})</button></div></MediaDialog>
 </section>;
}

function NameEditor({entries,index,defaultLanguage,onClose,onApply,t}:{entries:NameDictionaryEntry[];index:number|null;defaultLanguage:string;onClose:()=>void;onApply:(entry:NameDictionaryEntry)=>void;t:Text}){
 const existing=index===null?null:entries[index];
 const [entry,setEntry]=useState<NameDictionaryEntry>(()=>({original:'',translated:'',pronunciation:'',type:'character',language:defaultLanguage,note:'',...existing}));
 const [errors,setErrors]=useState<Record<string,string>>({});
 const original=useRef<HTMLInputElement>(null),translated=useRef<HTMLInputElement>(null);
 function apply(){
  const next:Record<string,string>={};const name=entry.original.trim();
  if(!name)next.original=t('required');else if(entries.some((other,position)=>position!==index&&other.original.trim().toLocaleLowerCase()===name.toLocaleLowerCase()))next.original=t('duplicateName');
  if(!entry.translated.trim())next.translated=t('required');
  setErrors(next);
  if(next.original){original.current?.focus();return;}if(next.translated){translated.current?.focus();return;}
  onApply({original:name,translated:entry.translated.trim(),pronunciation:entry.pronunciation?.trim()??'',type:entry.type??'other',language:entry.language,note:entry.note?.trim()??''});
 }
 const text=(key:keyof NameDictionaryEntry,label:string,ref?:React.RefObject<HTMLInputElement|null>,required=false,max=100,placeholder?:string)=><label className={'pref-text-field'+(errors[key]?' has-error':'')}><span>{label}{required&&<em> *</em>}</span>
  <input ref={ref} dir="auto" value={String(entry[key]??'')} maxLength={max} placeholder={placeholder} onChange={event=>{setEntry(old=>({...old,[key]:event.target.value}));setErrors(old=>({...old,[key]:''}));}}/>{errors[key]&&<small className="pref-error" role="alert">{errors[key]}</small>}</label>;
 return <MediaDialog open title={index===null?t('addNameTitle'):t('editName')} headerIcon="file" onClose={onClose} className="pref-name-dialog" animated>
  <form className="pref-dialog-form" onSubmit={event=>{event.preventDefault();apply();}}>
   <div className="pref-dialog-body pref-name-grid">
    {text('original',t('nameOriginal'),original,true)}
    {text('translated',t('translation'),translated,true)}
    <div className="pref-text-field"><span>{t('type')}</span><SelectField label={t('type')} value={entry.type??'other'} onChange={value=>setEntry(old=>({...old,type:value as EntryType}))} options={entryTypes.map(value=>({value,label:t(value)}))}/></div>
    <div className="pref-text-field"><span>{t('language')}</span><SelectField label={t('language')} value={entry.language??defaultLanguage} onChange={value=>setEntry(old=>({...old,language:value}))} options={narrationLanguages.map(value=>({value,label:languageNames[value],image:flags[value]}))}/></div>
    {text('pronunciation',t('pronunciation'))}
    {text('note',t('noteOptional'),undefined,false,160,t('notePlaceholder'))}
   </div>
   <footer className="pref-dialog-actions"><button type="button" className="pref-button" onClick={onClose}>{t('cancel')}</button><button type="submit" className="pref-button primary"><StudioIcon name="check"/>{t('apply')}</button></footer>
  </form>
 </MediaDialog>;
}
