import {useEffect,useRef,useState,type ReactNode} from 'react';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {MediaDialog,SelectField} from '@/components/media-workflow';
import {ActionTooltip} from '@/components/action-tooltip';
import {useSupportText} from '@/lib/support-i18n';
import {useDisplayDate} from '@/lib/platform-display';
import {mediaApi,MediaApiError} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import type {SupportAttachment,SupportCustomer,SupportDetail,SupportOptions,SupportRelated} from '@/types/support';
import {AvatarPhoto} from '@/components/avatar-photo';

export const categoryIcons:Record<string,IconName>={video:'play',payment:'wallet',subscription:'crown',account:'key',general:'headphones'};
export function SupportAvatar({customer,small=false}:{customer:SupportCustomer;small?:boolean}){
 const initials=customer.name.trim().split(/\s+/).slice(0,2).map(p=>Array.from(p)[0]).join('').toUpperCase();
 return <span className={'sp-avatar'+(small?' is-small':'')} style={{'--avatar-hue':(customer.id*57+240)%360} as React.CSSProperties}><AvatarPhoto src={customer.avatar_url} fallback={initials||'?'}/></span>;
}
export function SupportBadge({value}:{value:string}){const {t}=useSupportText();return <span className={'sp-badge is-'+value}><StudioIcon name={['active','completed','resolved','approved'].includes(value)?'check':value==='failed'?'circle-x':['high','urgent'].includes(value)?'alert':'clock'}/>{t(value==='failed'?'failedStatus':value)}</span>;}
export function SupportPriorityMark({value}:{value:string}){
 const{t}=useSupportText();if(!['high','urgent'].includes(value))return null;
 return <ActionTooltip label={t(value)}><span className={'sp-priority-mark is-'+value} role="img" aria-label={t(value)}><StudioIcon name={value==='urgent'?'bolt':'alert'}/></span></ActionTooltip>;
}

export function CopySupport({value}:{value:string}){const {t}=useSupportText(),toast=useToast();const[copied,setCopied]=useState(false);const timer=useRef<ReturnType<typeof setTimeout>|undefined>(undefined);useEffect(()=>()=>clearTimeout(timer.current),[]);return <ActionTooltip label={copied?t('copied'):t('copy')} visible={copied}><button type="button" className="sp-copy" aria-label={t('copy')} onClick={async()=>{try{await navigator.clipboard.writeText(value);setCopied(true);clearTimeout(timer.current);timer.current=setTimeout(()=>setCopied(false),2000);}catch{toast.error(t('error'));}}}><StudioIcon name={copied?'check':'copy'}/></button></ActionTooltip>;}
export function ContextContent({detail}:{detail:SupportDetail}){
 const {t,bytes,number,language}=useSupportText(),date=useDisplayDate(),c=detail.context;const quota=c.quota??0;
 return <div className="sp-context-content">
  <section className="sp-context-customer"><div className="sp-person"><SupportAvatar customer={detail.customer}/><div><strong>{detail.customer.name}</strong><span>{detail.customer.email}<CopySupport value={detail.customer.email}/></span></div></div>
   <dl><div><dt>{t('since')}</dt><dd>{date(c.created_at,false)}</dd></div><div><dt>{t('status')}</dt><dd><SupportBadge value={c.status}/></dd></div><div><dt>{t('lastLogin')}</dt><dd>{c.last_login?date(c.last_login,true):t('never')}</dd></div></dl></section>
  <ContextSection icon="crown" title={t('subscriptionTitle')}>{c.subscription?<><div className="sp-context-line"><strong>{c.subscription.name} <small>v{c.subscription.version}</small></strong><SupportBadge value={c.subscription.status}/></div><p>{date(c.subscription.from,false)} – {date(c.subscription.to,false)}</p><a href={c.subscription.url}>{t('view')}<StudioIcon name="arrow"/></a></>:<p>{t('noSubscription')}</p>}</ContextSection>
  {detail.related&&<ContextSection icon="link" title={t('related')}><strong>{detail.related.title}</strong><div className="sp-context-line"><small>{detail.related.code}</small><SupportBadge value={detail.related.status}/></div>{detail.related.url&&<a href={detail.related.url}>{t('view')}<StudioIcon name="arrow"/></a>}</ContextSection>}
  <ContextSection icon="wallet" title={t('recentPayments')}>{c.payments.length?c.payments.map(p=><a className="sp-record" href={p.url} key={p.id}><span><strong>{p.currency} {new Intl.NumberFormat(language,{maximumFractionDigits:2}).format(p.amount_minor/100)}</strong><small>{p.id} · {date(p.at,false)}</small></span><SupportBadge value={p.status}/></a>):<p>{t('emptyRecords')}</p>}</ContextSection>
  <ContextSection icon="play" title={t('recentJobs')}>{c.jobs.length?c.jobs.map(j=><a className="sp-record" key={j.id} href={j.url} title={j.title??j.id}><span><strong>{j.id}</strong><small>{j.title}</small></span><SupportBadge value={j.status}/></a>):<p>{t('emptyRecords')}</p>}</ContextSection>
  <ContextSection icon="database" title={t('storage')}><strong>{bytes(c.storage)}{quota>0&&<> / {bytes(quota)} <small>({number(c.storage/quota*100)}%)</small></>}</strong>{quota>0&&<div className="sp-storage-meter"><i style={{width:Math.min(100,c.storage/quota*100)+'%'}}/></div>}</ContextSection>
  <ContextSection icon="link" title={t('links')}><a href={c.usage_url}><StudioIcon name="layers"/>{t('usage')}<StudioIcon name="arrow"/></a><a href={c.user_url}><StudioIcon name="users"/>{t('user')}<StudioIcon name="arrow"/></a></ContextSection>
 </div>;
}
function ContextSection({icon,title,children}:{icon:IconName;title:string;children:ReactNode}){return <section className="sp-context-section"><h3><StudioIcon name={icon}/>{title}</h3>{children}</section>;}
export function AttachmentView({file,onPreview}:{file:SupportAttachment;onPreview:(file:SupportAttachment)=>void}){
 const {t,bytes}=useSupportText();return <div className="sp-attachment"><button type="button" className="sp-attachment-preview" onClick={()=>onPreview(file)} aria-label={t('preview')}>{file.mime.startsWith('image/')?<img src={file.url} alt="" loading="lazy"/>:<StudioIcon name="file"/>}</button><span><strong>{file.name}</strong><small>{bytes(file.bytes)}</small></span><ActionTooltip label={t('download')}><a href={file.url+'?download=1'} className="icon-button" aria-label={t('download')}><StudioIcon name="download"/></a></ActionTooltip><ActionTooltip label={t('preview')}><button type="button" className="icon-button" onClick={()=>onPreview(file)} aria-label={t('preview')}><StudioIcon name="eye"/></button></ActionTooltip></div>;
}
export function AttachmentPreview({file,onClose}:{file:SupportAttachment|null;onClose:()=>void}){const{t,bytes}=useSupportText();return <MediaDialog open={!!file} title={file?.name??t('preview')} onClose={onClose} className="sp-preview-dialog" headerIcon="image">{file&&<><div className="sp-preview-body">{file.mime.startsWith('image/')?<img src={file.url} alt={file.name}/>:<div><StudioIcon name="file"/><p>{file.name}</p><small>PDF · {bytes(file.bytes)}</small></div>}</div><footer><a className="media-button" href={file.url+'?download=1'}><StudioIcon name="download"/>{t('download')}</a><button className="media-button" onClick={onClose}>{t('close')}</button></footer></>}</MediaDialog>;}
export function validFiles(files:File[]){return files.length<=5&&files.every(f=>f.size<=10*1024*1024&&f.size>0&&/^(image\/(png|jpeg|webp)|application\/pdf)$/.test(f.type)&&/\.(png|jpe?g|webp|pdf)$/i.test(f.name));}
function PendingThumbnail({file}:{file:File}){const[url,setUrl]=useState('');useEffect(()=>{if(!file.type.startsWith('image/'))return;const value=URL.createObjectURL(file);setUrl(value);return()=>URL.revokeObjectURL(value);},[file]);return url?<img className="sp-pending-thumbnail" src={url} alt=""/>:<StudioIcon name="file"/>;}
export function PendingFiles({files,onRemove}:{files:File[];onRemove:(index:number)=>void}){const{t,bytes}=useSupportText();return <div className="sp-pending-files">{files.map((f,i)=><div key={i}><PendingThumbnail file={f}/><span>{f.name}<small>{bytes(f.size)}</small></span><button type="button" onClick={()=>onRemove(i)} aria-label={t('remove')+' '+f.name}><StudioIcon name="close"/></button></div>)}</div>;}

export function NewSupportConversation({endpoint,onClose,onCreated}:{endpoint:string;onClose:()=>void;onCreated:(id:number)=>void}){
 const{t}=useSupportText(),toast=useToast();const [options,setOptions]=useState<SupportOptions>({customers:[],related:[]}),[query,setQuery]=useState(''),[customer,setCustomer]=useState(''),[subject,setSubject]=useState(''),[category,setCategory]=useState('general'),[priority,setPriority]=useState('normal'),[related,setRelated]=useState(''),[body,setBody]=useState(''),[files,setFiles]=useState<File[]>([]),[busy,setBusy]=useState(false),[fetching,setFetching]=useState(false),[errors,setErrors]=useState<Record<string,string[]>>({});
 const selected=useRef<SupportCustomer|null>(null),fileInput=useRef<HTMLInputElement>(null),alive=useRef(true),abort=useRef<AbortController|null>(null),mutation=useRef<AbortController|null>(null),nonce=useRef({signature:'',id:''});
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;abort.current?.abort();mutation.current?.abort();};},[]);
 useEffect(()=>{const controller=new AbortController();abort.current?.abort();abort.current=controller;const timer=setTimeout(async()=>{setFetching(true);try{const result=await mediaApi<SupportOptions>(endpoint+'?'+new URLSearchParams({q:query,...(customer?{customer}:{})}),'GET',undefined,controller.signal);if(alive.current&&!controller.signal.aborted){if(selected.current&&!result.customers.some(c=>c.id===selected.current?.id))result.customers.unshift(selected.current);setOptions(result);}}catch{if(!controller.signal.aborted)toast.error(t('error'));}finally{if(alive.current&&!controller.signal.aborted)setFetching(false);}},220);return()=>{clearTimeout(timer);controller.abort();};},[query,customer]);
 const fail=(key:string)=>errors[key]?.[0];
 async function submit(){
  const e:Record<string,string[]>={};if(!customer)e.customer_id=[t('required')];if(!subject.trim())e.subject=[t('required')];if(!body.trim()&&!files.length)e.body=[t('required')];if(!validFiles(files))e.files=[t('fileError')];setErrors(e);if(Object.keys(e).length){toast.error(t('validation'));return;}
  if(busy)return;setBusy(true);const data=new FormData();for(const[k,v]of Object.entries({customer_id:customer,subject,category,priority,body,...(related?{related_type:related.split(':')[0],related_id:related.split(':')[1]}:{})}))data.append(k,v);
  const signature=JSON.stringify([customer,subject,category,priority,related,body,files.map(f=>[f.name,f.size,f.lastModified])]);if(nonce.current.signature!==signature)nonce.current={signature,id:crypto.randomUUID()};data.append('request_id',nonce.current.id);files.forEach(f=>data.append('files[]',f));const controller=new AbortController();mutation.current=controller;
  try{const result=await mediaApi<{id:number}>(endpoint.replace(/\/options$/,''),'POST',data,controller.signal);if(alive.current){toast.success(t('created'));onCreated(result.id);}}
  catch(error){if(alive.current&&!controller.signal.aborted){if(error instanceof MediaApiError)setErrors(error.fields);toast.error(error instanceof MediaApiError&&error.status===422?t('validation'):t('error'));}}
  finally{if(alive.current)setBusy(false);}
 }
 return <MediaDialog open title={t('newConversation')} headerIcon="headphones" headerDetail={t('newHint')} onClose={()=>{if(!busy)onClose();}} className="sp-new-dialog">
  <form onSubmit={e=>{e.preventDefault();void submit();}} noValidate><div className="sp-new-body">
   <section><h3><StudioIcon name="users"/>{t('customer')}</h3><label className="sp-search"><StudioIcon name="search"/><input value={query} onChange={e=>setQuery(e.target.value)} placeholder={t('findCustomer')} aria-label={t('findCustomer')} autoComplete="off"/>{fetching&&<StudioIcon name="loader" className="is-spinning"/>}</label>
   <label className="sp-field"><span>{t('customer')} <em>*</em></span><SelectField label={t('customer')} value={customer} invalid={!!fail('customer_id')} options={[{value:'',label:t('chooseCustomer')},...options.customers.map(c=>({value:String(c.id),label:c.name,detail:c.email}))]} onChange={v=>{selected.current=options.customers.find(c=>String(c.id)===v)??null;setCustomer(v);setRelated('');setOptions(o=>({...o,related:[]}));}}/>{fail('customer_id')&&<small role="alert">{fail('customer_id')}</small>}</label></section>
   <section><h3><StudioIcon name="headphones"/>{t('subject')}</h3><label className="sp-field"><span>{t('subject')} <em>*</em></span><input value={subject} maxLength={160} onChange={e=>setSubject(e.target.value)} aria-invalid={!!fail('subject')}/>{fail('subject')&&<small role="alert">{fail('subject')}</small>}</label>
    <div className="sp-new-grid"><label className="sp-field"><span>{t('category')} <em>*</em></span><SelectField label={t('category')} value={category} onChange={setCategory} options={['video','payment','subscription','account','general'].map(value=>({value,label:t(value)}))}/></label><label className="sp-field"><span>{t('priority')}</span><SelectField label={t('priority')} value={priority} onChange={setPriority} options={['normal','high','urgent'].map(value=>({value,label:t(value)}))}/></label></div>
    <label className="sp-field"><span>{t('related')}</span><SelectField label={t('related')} value={related} onChange={setRelated} disabled={!customer} options={[{value:'',label:t('noRelated')},...options.related.map(r=>({value:r.type+':'+r.id,label:r.code+' · '+r.title,detail:t(r.status)}))]}/><small className="sp-muted">{fail('related_id')??t('relatedHint')}</small></label></section>
   <section><h3><StudioIcon name="mail"/>{t('message')}</h3><label className="sp-field"><span>{t('message')} <em>*</em></span><textarea rows={4} value={body} maxLength={4000} onChange={e=>setBody(e.target.value)} placeholder={t('placeholder')} aria-invalid={!!fail('body')}/>{fail('body')&&<small role="alert">{fail('body')}</small>}</label>
   <PendingFiles files={files} onRemove={i=>setFiles(files.filter((_,n)=>n!==i))}/><input hidden type="file" ref={fileInput} accept=".png,.jpg,.jpeg,.webp,.pdf" multiple onChange={e=>{const next=[...files,...Array.from(e.target.files??[])];if(validFiles(next))setFiles(next);else toast.error(t('fileError'));e.target.value='';}}/>
   <div className="sp-new-fileline"><button type="button" className="media-button" onClick={()=>fileInput.current?.click()}><StudioIcon name="upload"/>{t('attachment')}</button><small>{t('attachmentHint')}</small></div>{Object.keys(errors).filter(k=>k.startsWith('files')).map(k=><small role="alert" className="sp-error" key={k}>{errors[k][0]}</small>)}</section>
   <p className="sp-integration"><StudioIcon name="info"/>{t('integration')}</p>
  </div><footer><button type="button" className="media-button" onClick={onClose} disabled={busy}>{t('cancel')}</button><button className="media-button primary" disabled={busy}>{busy?<StudioIcon name="loader" className="is-spinning"/>:<StudioIcon name="send"/>}{t(busy?'sending':'start')}</button></footer></form>
 </MediaDialog>;
}

export function SafeMessage({text}:{text:string}){
 return <>{text.split('\n').map((line,i)=><div key={i} className={line.startsWith('• ')?'sp-bullet':''}>{line.split(/(\*\*[^*]+\*\*|_[^_]+_)/g).map((part,n)=>part.startsWith('**')&&part.endsWith('**')?<strong key={n}>{part.slice(2,-2)}</strong>:part.startsWith('_')&&part.endsWith('_')?<em key={n}>{part.slice(1,-1)}</em>:part)}{!line&&<br/>}</div>)}</>;
}
