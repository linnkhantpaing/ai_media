import {usePage} from '@inertiajs/react';
import {useEffect,useMemo,useRef,useState,type ReactNode} from 'react';
import {StudioIcon} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {MediaDialog} from '@/components/media-workflow';
import {mediaApi,MediaApiError} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import {formatPlatformDate} from '@/lib/platform-display';
import {usePreferenceText,type PreferenceWord} from '@/lib/preferences-i18n';
import type {SharedData} from '@/types';
import type {PreferencesData,PreferencesDraft,ProfileDevice,ProfileDisplay} from '@/types/preferences';

type Text=(key:PreferenceWord)=>string;
type Errors=Record<string,string[]>;
type Changed=(next:PreferencesData,message:string,refreshShared?:boolean)=>void;
const dateFormats=['medium','dmy','mdy','iso'] as const;

function apiError(error:unknown,t:Text){
 if(error instanceof MediaApiError){if(error.code==='session_expired')return t('sessionExpired');if(error.code==='rate_limited')return t('rateLimited');if(error.status===422)return Object.values(error.fields).flat()[0]??t('failed');}
 return t('failed');
}
function initials(name:string){return name.trim().split(/\s+/u).slice(0,2).map(word=>Array.from(word)[0]??'').join('').toLocaleUpperCase()||'?';}

/** Profile, photo, email, password, date display and signed-in devices for the signed-in customer. */
export function AccountTab({data,draft,onProfile,onChanged,errors}:{data:PreferencesData;draft:PreferencesDraft['profile'];onProfile:(patch:Partial<PreferencesDraft['profile']>)=>void;onChanged:Changed;errors:Errors}){
 const {t}=usePreferenceText();
 return <div className="pref-account">
  <ProfileHero data={data} onChanged={onChanged} t={t}/>
  <div className="pref-account-grid">
   <PersonalCard data={data} name={draft.name} onName={name=>onProfile({name})} error={errors['profile.name']} onChanged={onChanged} t={t}/>
   <SecurityCard data={data} onChanged={onChanged} t={t}/>
   <DisplayCard data={data} display={draft.display} onDisplay={display=>onProfile({display})} errors={errors} t={t}/>
   <DevicesCard data={data} onChanged={onChanged} t={t}/>
  </div>
 </div>;
}

function ProfileHero({data,onChanged,t}:{data:PreferencesData;onChanged:Changed;t:Text}){
 const profile=data.profile;const {locale,app}=usePage<SharedData>().props;const toast=useToast();
 const [file,setFile]=useState<File|null>(null),[removing,setRemoving]=useState(false);
 const picker=useRef<HTMLInputElement>(null);
 const date=(value:string|null)=>value?formatPlatformDate(value,locale,app,false):t('never');
 function choose(selected:File|undefined){
  if(!selected)return;
  if(!['image/jpeg','image/png','image/webp'].includes(selected.type)||selected.size>5*1024*1024){toast.error(t('photoInvalid'));return;}
  setFile(selected);
 }
 async function remove(){setRemoving(true);try{const result=await mediaApi<{preferences:PreferencesData}>(data.endpoints.photo,'DELETE');onChanged(result.preferences,t('photoRemoved'),true);}catch(error){toast.error(apiError(error,t));}finally{setRemoving(false);}}
 const plan=profile.plan;
 return <section className="pref-card pref-hero">
  <div className="pref-hero-photo">
   <span className="pref-avatar">{profile.avatar_url?<img src={profile.avatar_url} alt=""/>:<b>{initials(profile.name)}</b>}</span>
   <ActionTooltip label={profile.photo_storage_ready?(profile.avatar_url?t('changePhoto'):t('addPhoto')):t('photoNeedsCloud')}><span className="pref-disabled-wrap pref-camera-wrap"><button type="button" className="pref-camera" aria-label={profile.avatar_url?t('changePhoto'):t('addPhoto')} disabled={!profile.photo_storage_ready} onClick={()=>picker.current?.click()}><StudioIcon name="image"/></button></span></ActionTooltip>
   <input ref={picker} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={event=>{choose(event.target.files?.[0]);event.target.value='';}}/>
  </div>
  <div className="pref-hero-main">
   <div className="pref-hero-name"><h2>{profile.name}</h2><span className={'pref-status is-'+profile.status}><i/>{t(profile.status==='active'?'active':'suspended')}</span></div>
   <p className="pref-hero-email">{profile.email}</p>
   <dl className="pref-hero-facts"><div><dt>{t('memberSince')}</dt><dd>{date(profile.member_since)}</dd></div><div><dt>{t('lastSignIn')}</dt><dd>{profile.last_login_at?formatPlatformDate(profile.last_login_at,locale,app,true):t('never')}</dd></div></dl>
   <div className="pref-hero-actions">
    <button type="button" className="pref-button" disabled={!profile.photo_storage_ready} onClick={()=>picker.current?.click()}><StudioIcon name="upload"/>{profile.avatar_url?t('changePhoto'):t('addPhoto')}</button>
    {profile.avatar_url&&<button type="button" className="pref-link pref-link-danger" disabled={removing} onClick={()=>void remove()}>{removing&&<StudioIcon name="loader" className="is-spinning"/>}{t('removePhoto')}</button>}
    <small className="pref-muted">{profile.photo_storage_ready?t('photoHint'):t('photoNeedsCloud')}</small>
   </div>
  </div>
  <a className="pref-plan-tile" href={data.endpoints.plan}>
   <span className="pref-plan-icon"><StudioIcon name="crown"/></span>
   <span><small>{t('currentPlan')}</small><strong>{plan?plan.name:t('noPlan')}</strong>{plan&&<em className={'is-'+plan.status}>{plan.status==='active'?t('active'):plan.status}{plan.ends_at&&' · '+t('until')+' '+date(plan.ends_at)}</em>}</span>
   <span className="pref-plan-link">{t('viewMyPlan')}<StudioIcon name="arrow"/></span>
  </a>
  {file&&<PhotoDialog file={file} endpoint={data.endpoints.photo} onClose={()=>setFile(null)} onSaved={next=>{setFile(null);onChanged(next,t('photoSaved'),true);}} t={t}/>}
 </section>;
}

const cropSize=240;
function PhotoDialog({file,endpoint,onClose,onSaved,t}:{file:File;endpoint:string;onClose:()=>void;onSaved:(data:PreferencesData)=>void;t:Text}){
 const toast=useToast();
 const [image,setImage]=useState<HTMLImageElement|null>(null),[zoom,setZoom]=useState(1),[offset,setOffset]=useState({x:0,y:0}),[busy,setBusy]=useState(false);
 const [url,setUrl]=useState('');const drag=useRef<{x:number;y:number;ox:number;oy:number}|null>(null);
 useEffect(()=>{const objectUrl=URL.createObjectURL(file);let active=true;const element=new Image();element.onload=()=>{if(active){setUrl(objectUrl);setImage(element);}};element.onerror=()=>{if(active){toast.error(t('photoInvalid'));onClose();}};element.src=objectUrl;return()=>{active=false;URL.revokeObjectURL(objectUrl);};},[file]);
 const base=image?Math.max(cropSize/image.naturalWidth,cropSize/image.naturalHeight):1;
 const scale=base*zoom;const width=(image?.naturalWidth??0)*scale,height=(image?.naturalHeight??0)*scale;
 const clamp=(value:{x:number;y:number})=>({x:Math.min(0,Math.max(cropSize-width,value.x)),y:Math.min(0,Math.max(cropSize-height,value.y))});
 useEffect(()=>{if(image)setOffset(clamp({x:(cropSize-width)/2,y:(cropSize-height)/2}));},[image]);
 function setZoomCentered(next:number){if(!image)return;const nextScale=base*next,ratio=nextScale/scale;const cx=cropSize/2;setZoom(next);setOffset({x:Math.min(0,Math.max(cropSize-image.naturalWidth*nextScale,cx-(cx-offset.x)*ratio)),y:Math.min(0,Math.max(cropSize-image.naturalHeight*nextScale,cx-(cx-offset.y)*ratio))});}
 async function save(){
  if(!image||busy)return;setBusy(true);
  try{
   const canvas=document.createElement('canvas');canvas.width=canvas.height=512;const context=canvas.getContext('2d')!;const factor=512/cropSize;
   context.imageSmoothingQuality='high';context.drawImage(image,offset.x*factor,offset.y*factor,width*factor,height*factor);
   const blob=await new Promise<Blob|null>(resolve=>canvas.toBlob(resolve,'image/webp',.9));
   const output=blob&&blob.type==='image/webp'?blob:await new Promise<Blob|null>(resolve=>canvas.toBlob(resolve,'image/jpeg',.9));
   if(!output)throw new Error('encode');
   const form=new FormData();form.append('photo',new File([output],output.type==='image/webp'?'avatar.webp':'avatar.jpg',{type:output.type}));
   const result=await mediaApi<{preferences:PreferencesData}>(endpoint,'POST',form);onSaved(result.preferences);
  }catch(error){toast.error(apiError(error,t));}finally{setBusy(false);}
 }
 return <MediaDialog open title={t('adjustPhoto')} headerIcon="image" onClose={()=>{if(!busy)onClose();}} className="pref-photo-dialog" animated>
  <div className="pref-dialog-form"><div className="pref-dialog-body pref-photo-body">
   <div className="pref-crop" style={{width:cropSize,height:cropSize}} onPointerDown={event=>{event.currentTarget.setPointerCapture(event.pointerId);drag.current={x:event.clientX,y:event.clientY,ox:offset.x,oy:offset.y};}}
    onPointerMove={event=>{const start=drag.current;if(start)setOffset(clamp({x:start.ox+event.clientX-start.x,y:start.oy+event.clientY-start.y}));}} onPointerUp={()=>{drag.current=null;}} onPointerCancel={()=>{drag.current=null;}}>
    {image?<img src={url} alt="" draggable={false} style={{width,height,transform:'translate('+offset.x+'px,'+offset.y+'px)'}}/>:<StudioIcon name="loader" className="is-spinning"/>}
    <i aria-hidden="true"/>
   </div>
   <small className="pref-muted">{t('dragToPosition')}</small>
   <label className="pref-zoom"><StudioIcon name="search"/><span className="sr-only">{t('zoom')}</span><input type="range" min={1} max={3} step={.01} value={zoom} aria-label={t('zoom')} style={{'--fill':((zoom-1)/2*100)+'%'} as React.CSSProperties} onChange={event=>setZoomCentered(Number(event.target.value))}/></label>
  </div>
  <footer className="pref-dialog-actions"><button type="button" className="pref-button" disabled={busy} onClick={onClose}>{t('cancel')}</button><button type="button" className="pref-button primary" disabled={busy||!image} onClick={()=>void save()}><StudioIcon name={busy?'loader':'check'} className={busy?'is-spinning':''}/>{busy?t('uploading'):t('savePhoto')}</button></footer></div>
 </MediaDialog>;
}

function Row({icon,label,value,hint,action}:{icon:Parameters<typeof StudioIcon>[0]['name'];label:string;value:ReactNode;hint?:ReactNode;action?:ReactNode}){
 return <div className="pref-row-line"><span className="pref-line-icon"><StudioIcon name={icon}/></span><div className="pref-line-text"><small>{label}</small><strong>{value}</strong>{hint&&<em>{hint}</em>}</div>{action}</div>;
}

function PersonalCard({data,name,onName,error,onChanged,t}:{data:PreferencesData;name:string;onName:(name:string)=>void;error?:string[];onChanged:Changed;t:Text}){
 const [open,setOpen]=useState(false);
 return <section className="pref-card pref-account-card"><h2><StudioIcon name="users"/>{t('personalInfo')}</h2>
  <label className={'pref-text-field'+(error?.length?' has-error':'')}><span>{t('displayName')} <em>*</em></span><input id="pref-display-name" value={name} maxLength={100} autoComplete="name" onChange={event=>onName(event.target.value)}/>
   {error?.map((message,index)=><small key={index} className="pref-error" role="alert">{message}</small>)}<small className="pref-muted">{t('displayNameHint')}</small></label>
  <Row icon="mail" label={t('email')} value={data.profile.email} hint={t('emailHint')} action={<button type="button" className="pref-button" onClick={()=>setOpen(true)}><StudioIcon name="edit"/>{t('changeEmail')}</button>}/>
  {open&&<SecretDialog title={t('changeEmail')} icon="mail" note={t('emailChangeHint')} endpoint={data.endpoints.email} method="PUT"
   fields={[{key:'email',label:t('newEmail'),type:'email',autoComplete:'email'},{key:'password',label:t('currentPassword'),type:'password',autoComplete:'current-password'}]}
   submit={t('changeEmail')} onClose={()=>setOpen(false)} onSaved={next=>{setOpen(false);onChanged(next,t('emailChanged'),true);}} t={t}/>}
 </section>;
}

function SecurityCard({data,onChanged,t}:{data:PreferencesData;onChanged:Changed;t:Text}){
 const {locale,app}=usePage<SharedData>().props;const [open,setOpen]=useState(false);
 const changed=data.profile.password_changed_at;
 return <section className="pref-card pref-account-card"><h2><StudioIcon name="shield"/>{t('security')}</h2>
  <Row icon="key" label={t('password')} value="••••••••••••" hint={changed?t('passwordLastChanged')+' '+formatPlatformDate(changed,locale,app,false):t('passwordSetByAdmin')} action={<button type="button" className="pref-button" onClick={()=>setOpen(true)}><StudioIcon name="edit"/>{t('changePassword')}</button>}/>
  <p className="pref-security-note"><StudioIcon name="info"/>{t('passwordSecurityNote')}</p>
  {open&&<PasswordDialog endpoint={data.endpoints.password} onClose={()=>setOpen(false)} onSaved={next=>{setOpen(false);onChanged(next,t('passwordChanged'));}} t={t}/>}
 </section>;
}

function PasswordInput({label,value,onChange,error,autoComplete,id}:{label:string;value:string;onChange:(value:string)=>void;error?:string[];autoComplete:string;id:string}){
 const {t}=usePreferenceText();const [show,setShow]=useState(false);
 return <label className={'pref-text-field'+(error?.length?' has-error':'')} htmlFor={id}><span>{label} <em>*</em></span>
  <span className="pref-secret"><input id={id} type={show?'text':'password'} value={value} maxLength={255} autoComplete={autoComplete} onChange={event=>onChange(event.target.value)}/><button type="button" aria-label={show?t('hidePassword'):t('showPassword')} onClick={()=>setShow(!show)}><StudioIcon name={show?'eye-off':'eye'}/></button></span>
  {error?.map((message,index)=><small key={index} className="pref-error" role="alert">{message}</small>)}</label>;
}

function PasswordDialog({endpoint,onClose,onSaved,t}:{endpoint:string;onClose:()=>void;onSaved:(data:PreferencesData)=>void;t:Text}){
 const toast=useToast();const [values,setValues]=useState({current_password:'',password:'',password_confirmation:''}),[errors,setErrors]=useState<Errors>({}),[busy,setBusy]=useState(false);
 const rules=[['ruleLength',values.password.length>=12],['ruleCase',/[a-z]/.test(values.password)&&/[A-Z]/.test(values.password)],['ruleNumber',/\d/.test(values.password)],['ruleSymbol',/[^A-Za-z0-9]/.test(values.password)],['ruleMatch',values.password!==''&&values.password===values.password_confirmation]] as const;
 const strength=rules.slice(0,4).filter(([,ok])=>ok).length;
 function set(key:keyof typeof values,value:string){setValues(old=>({...old,[key]:value}));setErrors(old=>({...old,[key]:[]}));}
 async function submit(){
  if(busy)return;const local:Errors={};
  if(!values.current_password)local.current_password=[t('required')];
  if(rules.slice(0,4).some(([,ok])=>!ok))local.password=[t('ruleLength')+' · '+t('ruleCase')+' · '+t('ruleNumber')+' · '+t('ruleSymbol')];
  if(values.password!==values.password_confirmation)local.password_confirmation=[t('ruleMatch')];
  if(Object.keys(local).length){setErrors(local);document.getElementById('pref-'+Object.keys(local)[0].replace('_','-'))?.focus();return;}
  setBusy(true);
  try{const result=await mediaApi<{preferences:PreferencesData}>(endpoint,'PUT',values);onSaved(result.preferences);}
  catch(error){const fields=error instanceof MediaApiError?error.fields:{};setErrors(fields);toast.error(apiError(error,t));}finally{setBusy(false);}
 }
 return <MediaDialog open title={t('changePassword')} headerIcon="key" onClose={()=>{if(!busy)onClose();}} className="pref-secret-dialog" animated>
  <form className="pref-dialog-form" onSubmit={event=>{event.preventDefault();void submit();}}><div className="pref-dialog-body">
   <PasswordInput id="pref-current-password" label={t('currentPassword')} value={values.current_password} onChange={value=>set('current_password',value)} error={errors.current_password} autoComplete="current-password"/>
   <PasswordInput id="pref-password" label={t('newPassword')} value={values.password} onChange={value=>set('password',value)} error={errors.password} autoComplete="new-password"/>
   <div className={'pref-strength is-'+strength} aria-hidden="true"><i/><i/><i/><i/></div>
   <PasswordInput id="pref-password-confirmation" label={t('confirmPassword')} value={values.password_confirmation} onChange={value=>set('password_confirmation',value)} error={errors.password_confirmation} autoComplete="new-password"/>
   <ul className="pref-rules">{rules.map(([key,ok])=><li key={key} className={ok?'is-ok':''}><StudioIcon name={ok?'check':'close'}/>{t(key)}</li>)}</ul>
   <p className="pref-security-note"><StudioIcon name="info"/>{t('passwordSecurityNote')}</p>
  </div>
  <footer className="pref-dialog-actions"><button type="button" className="pref-button" disabled={busy} onClick={onClose}>{t('cancel')}</button><button type="submit" className="pref-button primary" disabled={busy}><StudioIcon name={busy?'loader':'check'} className={busy?'is-spinning':''}/>{t('changePassword')}</button></footer></form>
 </MediaDialog>;
}

type SecretField={key:string;label:string;type:'email'|'password';autoComplete:string};
function SecretDialog({title,icon,note,endpoint,method,fields,submit,onClose,onSaved,t}:{title:string;icon:Parameters<typeof StudioIcon>[0]['name'];note:string;endpoint:string;method:'PUT'|'POST';fields:SecretField[];submit:string;onClose:()=>void;onSaved:(data:PreferencesData)=>void;t:Text}){
 const toast=useToast();const [values,setValues]=useState<Record<string,string>>(()=>Object.fromEntries(fields.map(field=>[field.key,'']))),[errors,setErrors]=useState<Errors>({}),[busy,setBusy]=useState(false);
 async function send(){
  if(busy)return;const missing=fields.find(field=>!values[field.key].trim());
  if(missing){setErrors({[missing.key]:[t('required')]});document.getElementById('pref-secret-'+missing.key)?.focus();return;}
  setBusy(true);
  try{const result=await mediaApi<{preferences:PreferencesData}>(endpoint,method,values);onSaved(result.preferences);}
  catch(error){const next=error instanceof MediaApiError?error.fields:{};setErrors(next);toast.error(apiError(error,t));const first=Object.keys(next)[0];if(first)document.getElementById('pref-secret-'+first)?.focus();}finally{setBusy(false);}
 }
 return <MediaDialog open title={title} headerIcon={icon} onClose={()=>{if(!busy)onClose();}} className="pref-secret-dialog" animated>
  <form className="pref-dialog-form" onSubmit={event=>{event.preventDefault();void send();}}><div className="pref-dialog-body">
   <p className="pref-security-note"><StudioIcon name="info"/>{note}</p>
   {fields.map((field,index)=>field.type==='password'
    ?<PasswordInput key={field.key} id={'pref-secret-'+field.key} label={field.label} value={values[field.key]} onChange={value=>{setValues(old=>({...old,[field.key]:value}));setErrors(old=>({...old,[field.key]:[]}));}} error={errors[field.key]} autoComplete={field.autoComplete}/>
    :<label key={field.key} className={'pref-text-field'+(errors[field.key]?.length?' has-error':'')}><span>{field.label} <em>*</em></span><input id={'pref-secret-'+field.key} type={field.type} autoFocus={index===0} value={values[field.key]} maxLength={254} autoComplete={field.autoComplete} onChange={event=>{const value=event.target.value;setValues(old=>({...old,[field.key]:value}));setErrors(old=>({...old,[field.key]:[]}));}}/>{errors[field.key]?.map((message,position)=><small key={position} className="pref-error" role="alert">{message}</small>)}</label>)}
  </div>
  <footer className="pref-dialog-actions"><button type="button" className="pref-button" disabled={busy} onClick={onClose}>{t('cancel')}</button><button type="submit" className="pref-button primary" disabled={busy}><StudioIcon name={busy?'loader':'check'} className={busy?'is-spinning':''}/>{submit}</button></footer></form>
 </MediaDialog>;
}

function DisplayCard({data,display,onDisplay,errors,t}:{data:PreferencesData;display:ProfileDisplay;onDisplay:(display:ProfileDisplay)=>void;errors:Errors;t:Text}){
 const {locale,app}=usePage<SharedData>().props;const platform=data.profile.platform_display;
 const [now,setNow]=useState(()=>new Date().toISOString());useEffect(()=>{const timer=setInterval(()=>setNow(new Date().toISOString()),30000);return()=>clearInterval(timer);},[]);
 const effective={...app,timezone:display.timezone??platform.timezone,date_format:display.date_format??platform.date_format,time_format:display.time_format??platform.time_format};
 const example=(format:string)=>formatPlatformDate(now,locale,{...effective,date_format:format},false);
 return <section className="pref-card pref-account-card"><h2><StudioIcon name="clock"/>{t('dateTime')}</h2>
  <p className="pref-card-intro">{t('displayHint')}</p>
  <div className="pref-text-field"><span>{t('timezone')}</span><TimezonePicker value={display.timezone} platform={platform.timezone} zones={data.profile.timezones} onChange={timezone=>onDisplay({...display,timezone})} t={t}/>{errors['profile.display.timezone']?.map((message,index)=><small key={index} className="pref-error">{message}</small>)}</div>
  <div className="pref-text-field"><span>{t('dateFormat')}</span><div className="pref-format-grid" role="radiogroup" aria-label={t('dateFormat')}>
   <button type="button" role="radio" aria-checked={display.date_format===null} className={display.date_format===null?'selected':''} onClick={()=>onDisplay({...display,date_format:null})}><strong>{t('platformDefault')}</strong><small>{example(platform.date_format)}</small></button>
   {dateFormats.map(format=><button key={format} type="button" role="radio" aria-checked={display.date_format===format} className={display.date_format===format?'selected':''} onClick={()=>onDisplay({...display,date_format:format})}><strong>{example(format)}</strong><small>{format==='medium'?'Medium':format==='dmy'?'DD/MM/YYYY':format==='mdy'?'MM/DD/YYYY':'YYYY-MM-DD'}</small></button>)}
  </div></div>
  <div className="pref-text-field"><span>{t('timeFormat')}</span><div className="pref-segment" role="radiogroup" aria-label={t('timeFormat')}>
   {([null,'12','24'] as const).map(format=><button key={String(format)} type="button" role="radio" aria-checked={display.time_format===format} className={display.time_format===format?'selected':''} onClick={()=>onDisplay({...display,time_format:format})}>{format===null?t('platformDefault'):format==='12'?t('hour12'):t('hour24')}</button>)}
  </div></div>
  <div className="pref-preview"><small>{t('preview')}</small><strong>{formatPlatformDate(now,locale,effective,true)}</strong><span>{effective.timezone}</span></div>
 </section>;
}

function TimezonePicker({value,platform,zones,onChange,t}:{value:string|null;platform:string;zones:string[];onChange:(value:string|null)=>void;t:Text}){
 const [open,setOpen]=useState(false),[query,setQuery]=useState(''),[active,setActive]=useState(0);
 const root=useRef<HTMLDivElement>(null),list=useRef<HTMLDivElement>(null),search=useRef<HTMLInputElement>(null);
 const term=query.trim().toLocaleLowerCase().replace(/\s+/g,'_');
 const options=useMemo(()=>[...(term?[]:[{value:null as string|null,label:t('platformDefault')+' ('+platform+')'}]),...zones.filter(zone=>!term||zone.toLocaleLowerCase().includes(term)).slice(0,200).map(zone=>({value:zone as string|null,label:zone.replace(/_/g,' ')}))],[zones,term,platform]);
 useEffect(()=>{if(!open)return;const close=(event:PointerEvent)=>{if(!root.current?.contains(event.target as Node))setOpen(false);};document.addEventListener('pointerdown',close);requestAnimationFrame(()=>{search.current?.focus({preventScroll:true});root.current?.querySelector('.pref-picker-panel')?.scrollIntoView({block:'nearest',behavior:'smooth'});});return()=>document.removeEventListener('pointerdown',close);},[open]);
 useEffect(()=>{setActive(0);},[term]);
 useEffect(()=>{list.current?.querySelector<HTMLElement>('[data-active=true]')?.scrollIntoView({block:'nearest'});},[active,open]);
 function pick(next:string|null){onChange(next);setOpen(false);setQuery('');}
 return <div className="pref-picker" ref={root}>
  <button type="button" className="pref-picker-trigger" aria-haspopup="listbox" aria-expanded={open} onClick={()=>setOpen(!open)}><StudioIcon name="globe"/><span>{value?value.replace(/_/g,' '):t('platformDefault')+' ('+platform+')'}</span><StudioIcon name="chevron"/></button>
  {open&&<div className="pref-picker-panel">
   <label className="pref-search"><StudioIcon name="search"/><input ref={search} value={query} placeholder={t('searchTimezone')} aria-label={t('searchTimezone')}
    onChange={event=>setQuery(event.target.value)} onKeyDown={event=>{if(event.key==='ArrowDown'){event.preventDefault();setActive(index=>Math.min(options.length-1,index+1));}else if(event.key==='ArrowUp'){event.preventDefault();setActive(index=>Math.max(0,index-1));}else if(event.key==='Enter'){event.preventDefault();const option=options[active];if(option)pick(option.value);}else if(event.key==='Escape'){event.preventDefault();setOpen(false);}}}/></label>
   <div className="pref-picker-list" role="listbox" ref={list}>{options.length?options.map((option,index)=><button key={String(option.value)} type="button" role="option" aria-selected={option.value===value} data-active={index===active} onPointerEnter={()=>setActive(index)} onClick={()=>pick(option.value)}>{option.label}{option.value===value&&<StudioIcon name="check"/>}</button>):<p className="pref-muted">{t('noTimezoneMatch')}</p>}</div>
  </div>}
 </div>;
}

function DevicesCard({data,onChanged,t}:{data:PreferencesData;onChanged:Changed;t:Text}){
 const {language}=usePreferenceText();const toast=useToast();
 const [busy,setBusy]=useState<string|null>(null),[confirm,setConfirm]=useState(false),[all,setAll]=useState(false);
 const devices=data.profile.devices;const others=devices.filter(device=>!device.current);
 const relative=new Intl.RelativeTimeFormat(language,{numeric:'auto'});
 function ago(value:string){const seconds=(Date.parse(value)-Date.now())/1000;if(seconds>-120)return t('activeNow');const units:[Intl.RelativeTimeFormatUnit,number][]=[['day',86400],['hour',3600],['minute',60]];const [unit,size]=units.find(([,length])=>Math.abs(seconds)>=length)??['minute',60];return relative.format(Math.round(seconds/size),unit);}
 async function signOut(device:ProfileDevice){setBusy(device.key);try{const result=await mediaApi<{preferences:PreferencesData}>(data.endpoints.devices.replace(/sign-out-others$/,'')+device.key,'DELETE');onChanged(result.preferences,t('deviceSignedOut'));}catch(error){toast.error(apiError(error,t));}finally{setBusy(null);}}
 return <section className="pref-card pref-account-card"><h2><StudioIcon name="monitor"/>{t('devices')}</h2>
  <p className="pref-card-intro">{t('devicesHint')}</p>
  {!devices.length?<p className="pref-muted">{t('devicesUnavailable')}</p>:<ul className="pref-devices">{(all?devices:devices.slice(0,5)).map(device=><li key={device.key} className={device.current?'is-current':''}>
   <span className="pref-line-icon"><StudioIcon name={device.kind==='mobile'?'phone':'monitor'}/></span>
   <div className="pref-line-text"><strong>{device.browser}{device.os&&' · '+device.os}{device.current&&<em className="pref-this-device">{t('thisDevice')}</em>}</strong><small>{device.ip} · {device.current?t('activeNow'):ago(device.last_active)}</small></div>
   {!device.current&&<ActionTooltip label={t('signOutDevice')}><button type="button" className="pref-icon danger" aria-label={t('signOutDevice')} disabled={busy!==null} onClick={()=>void signOut(device)}><StudioIcon name={busy===device.key?'loader':'logout'} className={busy===device.key?'is-spinning':''}/></button></ActionTooltip>}
  </li>)}</ul>}
  {devices.length>5&&<button type="button" className="pref-link" onClick={()=>setAll(!all)}><StudioIcon name={all?'move-up':'move-down'}/>{all?t('showFewer'):t('showAllDevices')+' ('+devices.length+')'}</button>}
  {devices.length>0&&!others.length&&<p className="pref-muted pref-devices-empty"><StudioIcon name="check"/>{t('noOtherDevices')}</p>}
  {others.length>0&&<button type="button" className="pref-button pref-danger-soft" onClick={()=>setConfirm(true)}><StudioIcon name="logout"/>{t('signOutOthers')}</button>}
  {confirm&&<SecretDialog title={t('signOutOthers')} icon="logout" note={t('signOutOthersHint')} endpoint={data.endpoints.devices} method="POST"
   fields={[{key:'password',label:t('currentPassword'),type:'password',autoComplete:'current-password'}]} submit={t('signOutOthers')} onClose={()=>setConfirm(false)} onSaved={next=>{setConfirm(false);onChanged(next,t('othersSignedOut'));}} t={t}/>}
 </section>;
}
