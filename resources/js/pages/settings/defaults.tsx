import {useDisplayDate} from '@/lib/platform-display';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import AppLayout from '@/layouts/app-layout';
import { StudioIcon, type IconName } from '@/components/studio-icon';
import { MediaDialog, SelectField } from '@/components/media-workflow';
import { useDefaultsText } from '@/lib/defaults-i18n';
import { useSettingsText } from '@/lib/settings-i18n';
import { useMediaText } from '@/lib/media-i18n';
import { useCreatorText } from '@/lib/media-creator-i18n';
import { useToast } from '@/lib/toast';
import { useLoading } from '@/lib/loading';
import { MediaApiError, mediaApi } from '@/lib/media-api';
import type { SharedData } from '@/types';
import type { MediaSettings } from '@/types/media';
import type { ProjectDefaultsConfiguration } from '@/types/settings';

type VoiceValues = Pick<MediaSettings,'voice'|'speech_rate'|'voice_volume'>;
type Props = SharedData & {defaults:ProjectDefaultsConfiguration;recommended:MediaSettings;providerVoice:VoiceValues;voices:string[];backgroundReady:boolean;saveUrl:string};
const clone = (value:ProjectDefaultsConfiguration):ProjectDefaultsConfiguration => ({...value,settings:{...value.settings}});
function Card({icon,title,hint,children,action}:{icon:IconName;title:string;hint:string;children:ReactNode;action?:ReactNode}) {
 return <section className="settings-card defaults-card"><header className="settings-card-heading"><span className="settings-section-icon"><StudioIcon name={icon}/></span><div><h2>{title}</h2><p>{hint}</p></div>{action}</header>{children}</section>;
}
function Row({label,hint,error,children,inactive=false}:{label:string;hint?:string;error?:string;children:ReactNode;inactive?:boolean}) {
 return <div className={'defaults-row'+(inactive?' is-inactive':'')+(error?' is-invalid':'')}><div className="defaults-label"><span>{label}</span>{hint&&<small>{hint}</small>}</div><div className="defaults-control">{children}{error&&<small className="defaults-error" role="alert">{error}</small>}</div></div>;
}
function Switch({label,value,onChange,disabled=false}:{label:string;value:boolean;onChange:(value:boolean)=>void;disabled?:boolean}) {
 return <label className="defaults-switch"><input type="checkbox" role="switch" aria-label={label} checked={value} onChange={event=>onChange(event.target.checked)} disabled={disabled}/><span aria-hidden="true"/></label>;
}
function Numeric({label,value,onChange,min,max,step=1,scale=1,suffix='',disabled=false}:{label:string;value:number;onChange:(value:number)=>void;min:number;max:number;step?:number;scale?:number;suffix?:string;disabled?:boolean}) {
 return <span className="defaults-number"><input type="number" aria-label={label} value={Number.isFinite(value)?Math.round(value*scale*10000)/10000:''} min={min} max={max} step={step} onChange={event=>onChange(event.target.valueAsNumber/scale)} disabled={disabled} required/><span>{suffix}</span></span>;
}

export default function Defaults() {
 const displayDate=useDisplayDate();
 const props=usePage<Props>().props;const {t}=useDefaultsText();const {t:m,error:mediaError,locale}=useMediaText();const {t:s}=useSettingsText();const {t:c}=useCreatorText();const toast=useToast();const {begin}=useLoading();
 const [saved,setSaved]=useState(()=>clone(props.defaults)),[draft,setDraft]=useState(()=>clone(props.defaults));
 const [busy,setBusy]=useState(false),[fields,setFields]=useState<Record<string,string[]>>({}),[conflict,setConflict]=useState(false),[resetOpen,setResetOpen]=useState(false),[coverOpen,setCoverOpen]=useState(props.defaults.settings.cover_mode==='manual');
 const [destination,setDestination]=useState<(()=>void)|null>(null);const allowLeave=useRef(false);const formId=useId();
 const value=draft.settings, dirty=JSON.stringify(draft)!==JSON.stringify(saved), video=value.output==='burn', dub=value.mode==='dub'&&value.output!=='srt', audio=['mp3','wav'].includes(value.output), visual=video&&value.burn_subtitles;
 const canBackground=dub&&(!audio||value.audio_track==='mix'), coverInvalid=video&&value.cover_mode==='manual'&&value.cover_top+value.cover_height>1.000001;
 const languageNames=new Intl.DisplayNames([locale],{type:'language'});
 const languages=[{value:'auto',label:m('autoDetect')},...['zh','en','hi','ko','ja','th','es','id','vi','my'].map(code=>({value:code,label:languageNames.of(code)||code}))];
 const errorFor=(key:string)=>fields['settings.'+key]?(key==='cover_height'?t('coverError'):key==='keep_background'?t('separationUnavailable'):key==='output'?t('combination'):t('invalid')):undefined;
 const date=saved.savedAt?displayDate(saved.savedAt,true):'';
 useEffect(()=>{const before=(event:BeforeUnloadEvent)=>{if(dirty&&!allowLeave.current){event.preventDefault();event.returnValue='';}};window.addEventListener('beforeunload',before);const off=router.on('before',event=>{if(!dirty||allowLeave.current||event.detail.visit.url.pathname===location.pathname||event.detail.visit.url.pathname===props.links.locale)return;event.preventDefault();const visit=event.detail.visit;setDestination(()=>()=>router.visit(visit.url,{method:visit.method,data:visit.data}));});return()=>{off();window.removeEventListener('beforeunload',before);};},[dirty,props.links.locale]);
 function change<K extends keyof MediaSettings>(key:K,next:MediaSettings[K]) {
  const settings={...value,[key]:next};let adjusted=false;
  if(key==='mode'&&((next==='dub'&&settings.output==='srt')||(next==='subs'&&['mp3','wav'].includes(settings.output)))){settings.output='burn';adjusted=true;}
  if(key==='output'&&['mp3','wav'].includes(String(next)))settings.mode='dub';
  if(key==='output'&&next==='srt')settings.mode='subs';
  if(audio&&key==='audio_track'&&next==='narration')settings.keep_background=false;
  if(key==='output'&&['mp3','wav'].includes(String(next))&&settings.audio_track==='narration')settings.keep_background=false;
  setDraft({...draft,settings});setFields({});
  if(key==='cover_mode'&&next==='manual')setCoverOpen(true);
  if(adjusted)toast.success(t('corrected'));
 }
 function chooseVoice(voice:string) {const inherit=voice==='provider';setDraft({...draft,inherit_voice:inherit,settings:{...value,...(inherit?props.providerVoice:{voice})}});setFields({});}
 async function save() {
  if(busy)return;
  if(coverInvalid){setFields({'settings.cover_height':['invalid']});setCoverOpen(true);toast.error(t('coverError'));return;}
  setBusy(true);setFields({});setConflict(false);const finish=begin();
  try{const result=await mediaApi<{defaults:ProjectDefaultsConfiguration}>(props.saveUrl,'PUT',{revision:draft.revision,inherit_voice:draft.inherit_voice,settings:value});setSaved(clone(result.defaults));setDraft(clone(result.defaults));toast.success(t('saveSuccess'));}
  catch(reason){if(reason instanceof MediaApiError){setFields(reason.fields);if(reason.status===409){setConflict(true);toast.error(s('conflict'));}else{if(reason.fields['settings.cover_height'])setCoverOpen(true);toast.error(reason.code==='validation_failed'?s('validation'):mediaError(reason.code));}}else toast.error(s('network'));}
  finally{finish();setBusy(false);}
 }
 function reset(){setDraft({...saved,inherit_voice:true,settings:{...props.recommended}});setFields({});setResetOpen(false);setCoverOpen(false);toast.success(t('resetDraft'));}
 const select=(key:keyof MediaSettings,label:string,options:{value:string;label:string}[],disabled=false)=><SelectField label={label} value={String(value[key])} options={options} disabled={busy||disabled} onChange={next=>change(key,next as never)}/>;
 const number=(key:'speech_rate'|'voice_volume'|'max_video_speed'|'background_volume'|'subtitle_lift'|'cover_top'|'cover_height',label:string,min:number,max:number,scale=1,step=1,suffix='',disabled=false)=><Numeric label={label} value={value[key]} min={min} max={max} scale={scale} step={step} suffix={suffix} disabled={busy||disabled} onChange={next=>change(key,next)}/>;
 const voiceOptions=[{value:'provider',label:t('inherit')},...props.voices.map(voice=>({value:voice,label:voice}))];
 const summary=[{icon:'play' as IconName,text:m(value.mode)}, {icon:'file' as IconName,text:value.output==='burn'?m('mp4'):value.output==='srt'?m('srt'):value.output.toUpperCase()+' + SRT'}, {icon:'info' as IconName,text:languages.find(item=>item.value===value.source_lang)?.label??value.source_lang}, {icon:'eye' as IconName,text:m('review')+': '+t(value.review_before_render?'on':'off')}];
 if(dub)summary.push({icon:'mic',text:value.voice+' \u00b7 '+value.speech_rate+'\u00d7'},{icon:'headphones',text:value.keep_background&&canBackground?t('keepBackground'):t('narrationOnly')});
 else if(video)summary.push({icon:'headphones',text:t('originalSound')});
 if(video){summary.push({icon:'monitor',text:value.aspect==='original'?t('originalLabel'):value.aspect},{icon:'captions',text:m('subtitles')+': '+t(value.burn_subtitles?'on':'off')},{icon:'layers',text:m('cover')+': '+t(value.cover_mode==='manual'?'on':'off')});if(visual)summary.push({icon:'captions',text:value.subtitle_color+' \u00b7 '+Math.round(value.subtitle_lift*100)+'%'});}
 return <AppLayout area="admin" adminPage="defaults" frame="projectDefaults" breadcrumbLabel={t('systemTitle')}><Head title={t('systemTitle')}/><div className="project-defaults provider-settings">
  <header className="settings-page-heading defaults-page-heading"><span><StudioIcon name="layers"/></span><div><h1>{t('systemTitle')}</h1><p>{t('description')}</p></div><div className="defaults-heading-actions"><span className={'defaults-save-status'+(dirty?' is-dirty':'')}><i/>{dirty?s('unsaved'):saved.savedAt?t('savedAt')+': '+date:t('notSaved')}</span><button type="button" className="media-button" disabled={busy} onClick={()=>setResetOpen(true)}><StudioIcon name="back"/>{t('reset')}</button></div></header>
  <div className="defaults-scope"><StudioIcon name="info"/><span>{t('scope')}</span></div>
  {conflict&&<div className="defaults-conflict" role="alert"><p>{s('conflict')}</p><button type="button" className="media-button" onClick={()=>{if(!dirty||window.confirm(s('leave'))){allowLeave.current=true;location.reload();}}}>{s('reload')}</button></div>}
  <form id={formId} noValidate onSubmit={event=>{event.preventDefault();void save();}}><fieldset className="settings-editor" disabled={busy}><div className="defaults-main-grid"><div className="defaults-column">
   <Card icon="settings" title={t('processing')} hint={t('processingHint')}>
    <Row label={m('mode')} error={errorFor('mode')}>{select('mode',m('mode'),[{value:'subs',label:m('subs')},{value:'dub',label:m('dub')}])}</Row>
    <Row label={m('language')} error={errorFor('source_lang')}>{select('source_lang',m('language'),languages)}</Row>
    <Row label={m('review')} hint={t('reviewShort')}><Switch label={m('review')} value={value.review_before_render} onChange={next=>change('review_before_render',next)}/></Row>
   </Card>
   <Card icon="mic" title={t('voice')} hint={t('voiceHint')}>
    <Row label={t('useNarration')}><Switch label={t('useNarration')} value={dub} onChange={next=>change('mode',next?'dub':'subs')}/></Row>
    <Row label={m('voice')} inactive={!dub} error={errorFor('voice')}><SelectField label={m('voice')} value={draft.inherit_voice?'provider':value.voice} options={voiceOptions} onChange={chooseVoice} disabled={busy||!dub}/></Row>
    <p className="defaults-hint"><StudioIcon name="info"/>{draft.inherit_voice?t('inheritHint')+' '+props.providerVoice.voice+' \u00b7 '+props.providerVoice.speech_rate+'\u00d7 \u00b7 '+Math.round(props.providerVoice.voice_volume*100)+'%':t('savedVoice')}</p>
    {dub&&<details className="defaults-fine-tune"><summary>{t('voiceAdvanced')}<StudioIcon name="chevron"/></summary>
     <Row label={m('speechRate')} error={errorFor('speech_rate')}>{number('speech_rate',m('speechRate'),0.5,2,1,0.05,'\u00d7',draft.inherit_voice)}</Row>
     <Row label={m('voiceVolume')} error={errorFor('voice_volume')}>{number('voice_volume',m('voiceVolume'),0,200,100,5,'%',draft.inherit_voice)}</Row>
     <label className="defaults-style-label">{m('voiceStyle')}<textarea value={value.voice_style} onChange={event=>change('voice_style',event.target.value)} maxLength={300} rows={3}/><small>{value.voice_style.length}/300</small></label>
     <Row label={m('maxSpeed')} error={errorFor('max_video_speed')}>{number('max_video_speed',m('maxSpeed'),1,2,1,0.05,'\u00d7')}</Row>
    </details>}
   </Card>
   <Card icon="monitor" title={t('video')} hint={t('videoHint')}>
    <Row label={m('aspect')} inactive={!video} error={errorFor('aspect')}>{select('aspect',m('aspect'),[{value:'original',label:t('originalLabel')},{value:'9:16',label:'9:16 \u00b7 '+m('vertical')},{value:'16:9',label:'16:9 \u00b7 '+m('wide')},{value:'1:1',label:'1:1 \u00b7 '+m('square')}],!video)}</Row>
    {video&&value.aspect!=='original'&&<><Row label={m('fill')} error={errorFor('fill')}>{select('fill',m('fill'),[{value:'auto',label:m('auto')},{value:'blur',label:m('blur')},{value:'crop',label:m('crop')},{value:'pad',label:m('pad')}])}</Row><p className="defaults-hint">{t('fillHint')}</p></>}
    {!video&&<p className="defaults-hint">{t('videoInactive')}</p>}
   </Card>
  </div><div className="defaults-column">
   <Card icon="file" title={t('output')} hint={t('outputHint')}>
    <Row label={m('output')} error={errorFor('output')}>{select('output',m('output'),[{value:'burn',label:m('mp4')},{value:'srt',label:m('srt')},{value:'mp3',label:'MP3 \u00b7 '+c('audioOutput')},{value:'wav',label:'WAV \u00b7 '+c('audioOutput')}])}</Row>
    {audio&&<><Row label={c('audioTrack')}>{select('audio_track',c('audioTrack'),[{value:'mix',label:t('finalMix')},{value:'narration',label:t('narrationOnly')}])}</Row><p className="defaults-hint">{t('finalMixHint')}</p></>}
   </Card>
   <Card icon="headphones" title={t('background')} hint={t('backgroundHint')} action={<span className={'defaults-capability '+(props.backgroundReady?'is-ready':'is-unavailable')}>{s(props.backgroundReady?'ready':'unavailable')}</span>}>
    <Row label={t('background')} inactive={!canBackground} error={errorFor('keep_background')}><SelectField label={t('background')} value={value.keep_background?'keep':'off'} options={props.backgroundReady||value.keep_background?[{value:'off',label:t('noBackground')},{value:'keep',label:t('keepBackground')}]:[{value:'off',label:t('noBackground')}]} disabled={busy||!canBackground||(!props.backgroundReady&&!value.keep_background)} onChange={next=>change('keep_background',next==='keep')}/></Row>
    {value.output==='srt'?<p className="defaults-hint">{t('srtAudioInactive')}</p>:!dub?<p className="defaults-hint">{t('originalAudio')}</p>:!canBackground?<p className="defaults-hint">{t('narrationOnly')}</p>:!props.backgroundReady?<p className="defaults-hint is-warning">{t('separationUnavailable')} <Link href={props.adminLinks?.providers??props.links.workspace}>{t('setupLink')}</Link></p>:<p className="defaults-hint">{t('backgroundNote')}</p>}
    {canBackground&&value.keep_background&&<><Row label={m('backgroundVolume')} error={errorFor('background_volume')}>{number('background_volume',m('backgroundVolume'),0,150,100,5,'%')}</Row><Row label={c('duck')}><Switch label={c('duck')} value={value.duck_background} onChange={next=>change('duck_background',next)}/></Row></>}
   </Card>
   <Card icon="captions" title={t('subtitles')} hint={t('subtitleHint')}>
    <Row label={c('captions')} inactive={!video}><Switch label={c('captions')} value={value.burn_subtitles} onChange={next=>change('burn_subtitles',next)} disabled={!video}/></Row>
    <Row label={m('subtitleColor')} inactive={!visual} error={errorFor('subtitle_color')}><div className="defaults-colour"><input type="color" aria-label={m('subtitleColor')} value={/^#[a-f0-9]{6}$/i.test(value.subtitle_color)?value.subtitle_color:'#FFFFFF'} disabled={!visual} onChange={event=>change('subtitle_color',event.target.value.toUpperCase())}/><input type="text" aria-label={m('subtitleColor')+' HEX'} value={value.subtitle_color} disabled={!visual} maxLength={7} pattern="#[a-fA-F0-9]{6}" onChange={event=>change('subtitle_color',event.target.value.toUpperCase())} required={visual}/></div></Row>
    <Row label={m('subtitleLift')} inactive={!visual} error={errorFor('subtitle_lift')}>{number('subtitle_lift',m('subtitleLift'),0,50,100,1,'%',!visual)}</Row>
    {!visual&&<p className="defaults-hint">{t('styleInactive')}</p>}
   </Card>
  </div></div>
  <section className="settings-card defaults-cover"><div className="defaults-cover-heading"><button type="button" className="defaults-cover-expand" aria-expanded={coverOpen} onClick={()=>setCoverOpen(open=>!open)}><span className="settings-section-icon"><StudioIcon name="layers"/></span><span><strong>{t('cover')}</strong><small>{t('coverHint')}</small></span><StudioIcon name="chevron"/></button><Switch label={m('cover')} value={value.cover_mode==='manual'} onChange={next=>change('cover_mode',next?'manual':'off')} disabled={!video}/></div>
   {coverOpen&&<div className="defaults-cover-content"><div><p className="defaults-hint">{t('coverGuide')}</p><Row label={m('coverStyle')} inactive={!video||value.cover_mode==='off'}>{select('cover_style',m('coverStyle'),[{value:'smart_blur',label:m('blur')},{value:'dark_box',label:m('darkBox')},{value:'blur_strip',label:m('blurStrip')}],!video||value.cover_mode==='off')}</Row><Row label={m('coverTop')} error={errorFor('cover_top')}>{number('cover_top',m('coverTop'),0,99,100,1,'%',!video||value.cover_mode==='off')}</Row><Row label={m('coverHeight')} error={coverInvalid?t('coverError'):errorFor('cover_height')}>{number('cover_height',m('coverHeight'),1,100,100,1,'%',!video||value.cover_mode==='off')}</Row></div><figure className="defaults-layout-sample"><div className={'defaults-sample-frame is-'+(value.aspect==='9:16'?'vertical':value.aspect==='1:1'?'square':'wide')}><StudioIcon name="play"/>{value.cover_mode==='manual'&&<span style={{top:Math.min(100,Math.max(0,value.cover_top*100))+'%',height:Math.min(100,Math.max(1,value.cover_height*100))+'%'}}/>}</div><figcaption>{t('sample')}</figcaption></figure></div>}
  </section>
  <section className="defaults-summary"><span className="defaults-summary-icon"><StudioIcon name="check"/></span><div><h2>{t('summary')}</h2><p>{t('summaryHint')}</p><div className="defaults-summary-chips">{summary.map((item,index)=><span key={index}><StudioIcon name={item.icon}/>{item.text}</span>)}</div></div></section>
  </fieldset></form>
  <footer className="defaults-footer"><span>{t('newOnly')}</span><div><button type="button" className="media-button" disabled={busy||!dirty} onClick={()=>{setDraft(clone(saved));setFields({});setCoverOpen(saved.settings.cover_mode==='manual');toast.success(t('discarded'));}}>{s('discard')}</button><button type="submit" form={formId} className="media-button primary defaults-save" disabled={busy}><StudioIcon name="save"/>{s('save')}</button></div></footer>
  <MediaDialog open={resetOpen} title={t('reset')} onClose={()=>setResetOpen(false)}><p>{t('resetQuestion')}</p><p className="defaults-hint">{t('resetHint')}</p><div className="media-actions"><button type="button" className="media-button" onClick={()=>setResetOpen(false)}>{s('cancel')}</button><button type="button" className="media-button primary" onClick={reset}>{t('reset')}</button></div></MediaDialog>
  <MediaDialog open={destination!==null} title={s('unsaved')} onClose={()=>setDestination(null)}><p>{s('leave')}</p><div className="media-actions"><button type="button" className="media-button" onClick={()=>setDestination(null)}>{s('stay')}</button><button type="button" className="media-button primary" onClick={()=>{allowLeave.current=true;destination?.();setDestination(null);}}>{s('discard')}</button></div></MediaDialog>
 </div></AppLayout>;
}
