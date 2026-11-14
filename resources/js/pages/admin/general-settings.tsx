import {Head,router,usePage} from '@inertiajs/react';
import {useEffect,useRef,useState,type ReactNode} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {MediaDialog,SelectField} from '@/components/media-workflow';
import {useGeneralText} from '@/lib/general-settings-i18n';
import {mediaApi,MediaApiError} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import {useLoading} from '@/lib/loading';
import type {SharedData} from '@/types';
import type {BrandingAsset,GeneralConfiguration,GeneralState,MailReport} from '@/types/general-settings';
import {formatPlatformDate} from '@/lib/platform-display';


type AssetKey='main_logo'|'light_logo'|'favicon';
type Errors=Record<string,string[]>;
function Card({title,icon,children,className='',action}:{title:string;icon:IconName;children:ReactNode;className?:string;action?:ReactNode}){
 return <section className={'general-card '+className}><header className="general-card-heading"><StudioIcon name={icon}/><h2>{title}</h2>{action}</header><div className="general-card-content">{children}</div></section>;
}
function Switch({checked,onChange,label,disabled=false}:{checked:boolean;onChange:(v:boolean)=>void;label:string;disabled?:boolean}){
 return <button type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled} onClick={()=>onChange(!checked)} className={'general-switch'+(checked?' is-on':'')}><span/></button>;
}
export default function GeneralSettings({general,generalLinks,generalOptions}:{general:GeneralState;generalLinks:{save:string;test:string};generalOptions?:{timezones:string[]}}){
 const {t,locale}=useGeneralText();const toast=useToast();const loading=useLoading();const page=usePage<SharedData>();
 const [saved,setSaved]=useState(general),[draft,setDraft]=useState<GeneralConfiguration>(structuredClone(general.configuration));
 const [files,setFiles]=useState<Partial<Record<AssetKey,File>>>({}),[urls,setUrls]=useState<Partial<Record<AssetKey,string>>>({});
 const [errors,setErrors]=useState<Errors>({}),[busy,setBusy]=useState(false),[visible,setVisible]=useState(false),[advanced,setAdvanced]=useState(false);
 const [dialog,setDialog]=useState<'maintenance'|'discard'|'leave'|'email'|null>(null),[recipient,setRecipient]=useState(page.props.auth.user?.email??'');
 const form=useRef<HTMLFormElement>(null),leave=useRef<null|(()=>void)>(null),bypass=useRef(false),urlsRef=useRef(urls);
 const dirty=JSON.stringify(draft)!==JSON.stringify(saved.configuration)||Object.keys(files).length>0;
 useEffect(()=>{urlsRef.current=urls;},[urls]);
 useEffect(()=>()=>{Object.values(urlsRef.current).forEach(value=>URL.revokeObjectURL(value));},[]);
 useEffect(()=>{const unload=(event:BeforeUnloadEvent)=>{if(dirty){event.preventDefault();event.returnValue='';}};window.addEventListener('beforeunload',unload);
 const stop=router.on('before',event=>{if(dirty&&!bypass.current){event.preventDefault();leave.current=()=>router.visit(event.detail.visit.url,event.detail.visit);setDialog('leave');}});
 return ()=>{stop();window.removeEventListener('beforeunload',unload);};},[dirty]);
 function change<K extends keyof GeneralConfiguration>(key:K,value:GeneralConfiguration[K]){setDraft(current=>({...current,[key]:value}));setErrors(current=>Object.fromEntries(Object.entries(current).filter(([field])=>field!==key&&!field.startsWith(key+'.'))));}
 function smtp<K extends keyof GeneralConfiguration['smtp']>(key:K,value:GeneralConfiguration['smtp'][K]){change('smtp',{...draft.smtp,[key]:value});setErrors(current=>Object.fromEntries(Object.entries(current).filter(([field])=>field!=='smtp.'+key)));}
 function choose(key:AssetKey,file?:File){if(!file)return;const limit=key==='favicon'?256*1024:2*1024*1024;const allowed=key==='favicon'?/\.(png|ico)$/i:/\.(png|jpe?g|webp)$/i;
 if(file.size>limit||!allowed.test(file.name)){setErrors(current=>({...current,[key]:[t(key==='favicon'?'faviconHint':'imageHint')]}));toast.error(t('invalid'));return;}
 if(urls[key])URL.revokeObjectURL(urls[key]!);setFiles(current=>({...current,[key]:file}));setUrls(current=>({...current,[key]:URL.createObjectURL(file)}));setErrors(current=>({...current,[key]:[]}));}
 function reset(){Object.values(urls).forEach(value=>URL.revokeObjectURL(value));setUrls({});setFiles({});setDraft(structuredClone(saved.configuration));setErrors({});setDialog(null);}
 function failed(error:unknown){if(error instanceof MediaApiError){setErrors(error.fields);if(Object.keys(error.fields).some(key=>key.startsWith('smtp.')))setAdvanced(true);
 toast.error(error.status===409?t('conflict'):error.status===422?t('invalid'):t('error'));requestAnimationFrame(()=>{const field=Object.keys(error.fields)[0];form.current?.querySelector<HTMLElement>('[data-field="'+CSS.escape(field??'')+'"] input, [data-field="'+CSS.escape(field??'')+'"] textarea, [data-field="'+CSS.escape(field??'')+'"] button')?.focus();});}else toast.error(t('error'));}
 async function save(confirmed=false){if(busy)return;if(draft.maintenance.enabled&&!saved.configuration.maintenance.enabled&&!confirmed){setDialog('maintenance');return;}
 setDialog(null);setBusy(true);setErrors({});const done=loading.begin();
 try{const body=new FormData();const {main_logo:_main,light_logo:_light,favicon:_icon,...configuration}=draft;body.set('configuration',JSON.stringify({...configuration,revision:saved.revision,confirm_maintenance:confirmed}));
 for(const[key,file]of Object.entries(files))body.set(key,file);
 const result=await mediaApi<{general:GeneralState;app:SharedData['app']}>(generalLinks.save,'POST',body);
 document.documentElement.dataset.appName=result.app.name; document.title=t('title')+' - '+result.app.name;
 setSaved(result.general);setDraft(structuredClone(result.general.configuration));setFiles({});Object.values(urls).forEach(value=>URL.revokeObjectURL(value));setUrls({});
 // Publish only the safe, public projection to the shared frame.
 router.replace({preserveState:true,preserveScroll:true,props:current=>({...current,app:result.app,general:result.general})});
 toast.success(t('savedSuccess'));
 }catch(error){failed(error);}finally{done();setBusy(false);}}
 async function test(kind:'connection'|'email'){if(busy)return;if(dirty){toast.error(t('saveFirst'));return;}if(!saved.configuration.smtp.enabled){toast.error(t('disabled'));return;}
 if(kind==='email'&&!recipient.trim()){setErrors({recipient:[t('recipient')]});return;}
 setBusy(true);const done=loading.begin();try{const {report}=await mediaApi<{report:MailReport}>(generalLinks.test,'POST',{revision:saved.revision,kind,...(kind==='email'?{recipient}: {})});
 setSaved(current=>({...current,mail_report:{...current.mail_report,[kind]:report}}));
 if(report.status==='success'){toast.success(t(kind==='email'?'emailSuccess':'connectionSuccess'));setDialog(null);}else toast.error(t(report.code==='smtp_authentication_failed'?'smtp_authentication_failed':'smtp_connection_failed'));
 }catch(error){failed(error);}finally{done();setBusy(false);}}
 const label=(key:string,title:string,children:ReactNode,required=false)=><div className="general-field" data-field={key}><label htmlFor={'general-'+key}>{key.startsWith('support_')?<><StudioIcon name={key==='support_email'?'mail':key==='support_phone'?'phone':'link'}/><span className="sr-only">{title}</span></>:title}{required&&<span className="general-required"> *</span>}</label>{children}{errors[key]?.map(message=><small className="general-error" role="alert" key={message}>{message}</small>)}</div>;
 function input(key:keyof GeneralConfiguration,title:string,type='text',required=false){return label(key,title,<input id={'general-'+key} value={String(draft[key])} onChange={e=>change(key,e.target.value as never)} type={type} placeholder={key.startsWith('support_')?title:undefined} maxLength={key==='name'?80:key==='tagline'?120:key==='support_phone'?40:500} aria-invalid={!!errors[key]?.length}/>,required);}
 function mailInput(key:keyof GeneralConfiguration['smtp'],title:string,type='text',required=false){return label('smtp.'+key,title,<input id={'general-smtp.'+key} value={String(draft.smtp[key])} type={type} autoComplete={key==='password'?'new-password':'off'} maxLength={key==='password'?1000:254} onChange={e=>smtp(key,key==='port'?Number(e.target.value):e.target.value as never)} aria-invalid={!!errors['smtp.'+key]?.length}/>,required);}
 function select(key:'language'|'timezone'|'date_format'|'time_format',title:string,options:{value:string;label:string;image?:string}[]){return label(key,title,<SelectField value={draft[key]} onChange={value=>change(key,value)} options={options} label={title} invalid={!!errors[key]?.length}/>);}
 const main=urls.main_logo??draft.main_logo?.url??'/images/zatlan-3d-film-logo.png';
 const zones=Array.from(new Set(['Asia/Bangkok','Asia/Yangon','UTC',draft.timezone,...(generalOptions?.timezones??[])]));
 function asset(key:AssetKey){const current=draft[key] as BrandingAsset|null,preview=urls[key]??current?.url??(key==='main_logo'?main:key==='light_logo'?main:'/favicon.svg');
 return <div className={'general-asset '+(key==='favicon'?'is-favicon':'')} data-field={key}><div className="general-asset-title"><strong>{t(key)}{key==='main_logo'?<span className="general-required"> *</span>:<small> ({t('optional')})</small>}</strong>{key==='main_logo'&&<span className="general-active"><StudioIcon name="check"/>{t('active')}</span>}</div>
 <div className="general-asset-preview"><img src={preview} alt={t(key)}/></div><div className="general-asset-file"><div><span title={files[key]?.name??current?.name}>{files[key]?.name??current?.name??(key==='light_logo'?t('fallback'):key==='main_logo'?'zatlan-logo.png':'favicon.svg')}</span><small>{files[key]?Math.ceil(files[key]!.size/1024)+' KiB':current?Math.ceil(current.bytes/1024)+' KiB':t(key==='favicon'?'faviconHint':'imageHint')}</small></div><label className="icon-button" title={t(current?'replace':'upload')}><StudioIcon name="upload"/><input type="file" aria-label={t(key)+' · '+t('upload')} accept={key==='favicon'?'.png,.ico':'.png,.jpg,.jpeg,.webp'} onChange={e=>{choose(key,e.target.files?.[0]);e.target.value='';}}/></label></div>
 {errors[key]?.map(message=><small className="general-error" key={message}>{message}</small>)}</div>;}
 const report=Object.values(saved.mail_report??{}).sort((a,b)=>Date.parse(b.at)-Date.parse(a.at))[0];
 return <AppLayout frame="preferences" area="admin" adminPage="generalSettings" breadcrumbLabel={t('title')}><Head title={t('title')}/><form className="general-settings" ref={form} noValidate onSubmit={event=>{event.preventDefault();void save();}}>
 <header className="general-page-heading"><div className="general-page-title"><span className="general-title-icon"><StudioIcon name="gear"/></span><div><h1>{t('title')}</h1><p>{t('subtitle')}</p></div></div><div className={'general-saved-state '+(!dirty&&saved.revision?'is-saved':'')}><span><StudioIcon name={dirty?'edit':'check'}/>{t(dirty?'unsaved':saved.revision?'saved':'notSaved')}</span>{saved.saved_at&&<small>{t('lastSaved')}: {formatPlatformDate(saved.saved_at,locale,page.props.app,true)}</small>}</div></header>
 <div className="general-top-grid">
 <Card title={t('platform')} icon="layers"><div className="general-platform-grid"><div>{input('name',t('name'),'text',true)}{input('tagline',t('tagline'))}</div><div className="general-live"><strong><StudioIcon name="eye"/>{t('live')}</strong><div><img src={main} alt={draft.name}/><span>{draft.name!=='ZATLAN'?draft.name:''}</span><small>{draft.tagline}</small></div></div></div></Card>
 <Card title={t('display')} icon="globe"><div className="general-inline-fields">
 {select('language',t('language'),[{value:'en',label:'English',image:'/images/flags/gb.svg'},{value:'my',label:'မြန်မာ',image:'/images/flags/mm.svg'},{value:'th',label:'ไทย',image:'/images/flags/th.svg'}])}
 {select('timezone',t('timezone'),zones.map(value=>({value,label:value.replaceAll('_',' ')})))}
 {select('date_format',t('dateFormat'),[{value:'medium',label:'Nov 12, 2026'},{value:'dmy',label:'12/11/2026'},{value:'mdy',label:'11/12/2026'},{value:'iso',label:'2026-11-12'}])}
 {select('time_format',t('timeFormat'),[{value:'24',label:'24-hour (14:30)'},{value:'12',label:'12-hour (2:30 PM)'}])}</div><p className="general-hint">{t('defaultLanguageHint')} {t('timeHint')}</p></Card></div>
 <div className="general-brand-grid"><Card title={t('branding')} icon="image"><div className="general-assets">{asset('main_logo')}{asset('light_logo')}{asset('favicon')}</div></Card>
 <Card title={t('support')} icon="headphones"><div className="general-support">{input('support_email',t('support_email'),'email')}{input('support_phone',t('support_phone'),'tel')}{input('support_url',t('support_url'),'url')}</div></Card></div>
 <Card title={t('smtp')} icon="mail" className="general-mail" action={<label className="general-toggle-label">{t('enabled')}<Switch checked={draft.smtp.enabled} onChange={value=>smtp('enabled',value)} label={t('enabled')}/></label>}>
 <div className="general-smtp-grid">{mailInput('host',t('host'),'text',draft.smtp.enabled)}{mailInput('port',t('port'),'number',draft.smtp.enabled)}
 {label('smtp.encryption',t('encryption'),<SelectField value={draft.smtp.encryption} onChange={v=>smtp('encryption',v)} label={t('encryption')} options={[{value:'tls',label:'STARTTLS'},{value:'ssl',label:'TLS / SSL'},{value:'none',label:t('localOnly')}]}/>)}
 {mailInput('username',t('username'))}
 {label('smtp.password',t('password'),<div className="general-password"><input id="general-smtp.password" type={visible?'text':'password'} value={draft.smtp.password} onChange={e=>smtp('password',e.target.value)} autoComplete="new-password" placeholder={saved.has_password?'••••••••••':''} maxLength={1000} aria-invalid={!!errors['smtp.password']?.length}/><button type="button" aria-label={t(visible?'hide':'show')} onClick={()=>setVisible(!visible)}><StudioIcon name="eye"/></button></div>)}
 <button type="button" className="media-button general-test-email" disabled={busy} onClick={()=>{if(dirty){toast.error(t('saveFirst'));return;}if(!saved.configuration.smtp.enabled){toast.error(t('disabled'));return;}setDialog('email');}}><StudioIcon name="send"/>{t('send')}</button></div>
 <div className="general-mail-tools"><button className="general-text-button" type="button" aria-expanded={advanced} onClick={()=>setAdvanced(!advanced)}><StudioIcon name="sliders"/>{t('advanced')}<StudioIcon name="chevron"/></button><button type="button" className="media-button" disabled={busy} onClick={()=>void test('connection')}><StudioIcon name="refresh"/>{t('connection')}</button>{report&&<span className={'general-test-report is-'+report.status}><StudioIcon name={report.status==='success'?'check':'alert'}/>{t('testStatus')}: {t(report.status==='success'?'success':'failed')} · {formatPlatformDate(report.at,locale,page.props.app,true)}</span>}</div>
 {advanced&&<div className="general-sender-grid">{mailInput('from_name',t('from_name'),'text',draft.smtp.enabled)}{mailInput('from_email',t('from_email'),'email',draft.smtp.enabled)}{mailInput('reply_to',t('reply_to'),'email')}<p className="general-hint">{saved.has_password?t('keepPassword'):t('security')}</p></div>}
 <p className="general-info"><StudioIcon name="info"/>{t('smtpDeferred')}</p></Card>
 <Card title={t('maintenance')} icon="wrench"><div className="general-maintenance"><div className="general-maintenance-toggle"><Switch checked={draft.maintenance.enabled} onChange={v=>change('maintenance',{...draft.maintenance,enabled:v})} label={t('enableMaintenance')}/><div><strong>{t('enableMaintenance')}</strong><p>{t('maintenanceHint')}</p></div></div>
 {label('maintenance.message',t('message'),<div className="general-message"><textarea id="general-maintenance.message" maxLength={200} rows={2} value={draft.maintenance.message} onChange={e=>change('maintenance',{...draft.maintenance,message:e.target.value})} aria-invalid={!!errors['maintenance.message']?.length}/><small>{draft.maintenance.message.length} / 200</small></div>,draft.maintenance.enabled)}</div></Card>
 <footer className="general-actions"><button type="button" className="media-button" disabled={busy} onClick={()=>{if(dirty)setDialog('discard');}}><StudioIcon name="refresh"/>{t('discard')}</button><button type="submit" className="media-button primary" disabled={busy}><StudioIcon name="save"/>{t(busy?'saving':'save')}</button></footer>
 </form>
 <MediaDialog open={dialog!==null} title={t(dialog==='email'?'send':dialog==='maintenance'?'maintenance':dialog==='leave'?'unsaved':'discard')} headerIcon={dialog==='email'?'mail':'info'} animated className="general-dialog" onClose={()=>{if(!busy)setDialog(null);}}>
 <div className="general-dialog-body">{dialog==='email'?<><p>{t('testHint')}</p>{label('recipient',t('recipient'),<input id="general-recipient" type="email" value={recipient} onChange={e=>setRecipient(e.target.value)} autoComplete="email"/>,true)}</>:<p>{t(dialog==='maintenance'?'maintenanceConfirm':dialog==='leave'?'leaveConfirm':'discardConfirm')}</p>}</div>
 <footer className="general-dialog-actions"><button type="button" className="media-button" disabled={busy} onClick={()=>setDialog(null)}>{t(dialog==='leave'?'stay':'cancel')}</button><button type="button" className="media-button primary" disabled={busy} onClick={()=>{if(dialog==='email')void test('email');else if(dialog==='maintenance')void save(true);else if(dialog==='leave'){bypass.current=true;setDialog(null);leave.current?.();}else reset();}}>{t(dialog==='email'?'sendConfirm':dialog==='leave'?'leave':'confirm')}</button></footer>
 </MediaDialog></AppLayout>;
}
