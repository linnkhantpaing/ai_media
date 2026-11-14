import {PageBoundaryButton} from '@/components/page-boundary-button';
import {useDisplayDate} from '@/lib/platform-display';
import {Head,Link} from '@inertiajs/react';
import {useEffect,useRef,useState,type FormEvent} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {MediaDialog,SelectField} from '@/components/media-workflow';
import {DatePicker} from '@/components/date-picker';
import {OpsStatus,JobTypeBadge,useOpsStage} from '@/components/operations';
import {useAdminJobsText} from '@/lib/admin-jobs-i18n';
import {useMediaText} from '@/lib/media-i18n';
import {mediaApi,MediaApiError,formatTime} from '@/lib/media-api';
import {useLoading} from '@/lib/loading';
import {useToast} from '@/lib/toast';
import type {AdminJob,AdminJobDetails,AdminJobFilters,AdminJobList} from '@/types/admin-jobs';
import '../../../css/admin-job-monitor.css';

const summaries:[string,string,IconName,string][]=[
 ['','all','layers','primary'],['queued','queued','hourglass','warning'],['running','running','triangle-play','info'],
 ['completed','completed','check','success'],['failed','failed','circle-x','danger'],['cancelled','cancelled','ban','neutral']
];
const initials=(name:string)=>name.trim().split(/\s+/u).slice(0,2).map(w=>Array.from(w)[0]??'').join('').toUpperCase();
function Customer({job}:{job:AdminJob}){return <div className="aj-customer"><span className={'aj-avatar hue-'+job.customer.id%4}>{initials(job.customer.name)}</span><span><strong>{job.customer.name}</strong><small>{job.customer.email}</small></span></div>;}
function Progress({job,date}:{job:AdminJob;date:(v:string|null)=>string}){
 const {t}=useAdminJobsText();const {error}=useMediaText();const stage=useOpsStage();
 const label=job.status==='queued'?t('waiting'):job.status==='retry_waiting'?(job.nextRetryAt?t('retryAt')+' '+date(job.nextRetryAt):t('retry_waiting')):job.status==='awaiting_review'?t('reviewWait'):job.status==='failed'?error(job.errorCode??'processing_failed'):job.status==='completed'?t('completed'):job.status==='cancelled'?t('cancelled'):job.status==='stopping'?t('stopping'):stage(job.stage);
 const progress=['queued','retry_waiting','cancelled'].includes(job.status)?0:job.progress;
 return <div className={'aj-progress is-'+job.status}><div>{job.status==='running'&&progress!==null&&<strong>{progress}%</strong>}<span title={label}>{label}</span></div><div className={'aj-progress-track'+(progress===null&&job.status==='running'?' is-indeterminate':'')} role="progressbar" aria-label={t('progress')} aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress??undefined}><i style={{width:progress===null?'35%':Math.max(0,Math.min(100,progress))+'%'}}/></div></div>;
}
export default function AdminJobMonitor({monitor:initial}:{monitor:AdminJobList}){
 const displayDate=useDisplayDate();
 const {t,locale}=useAdminJobsText();const {error}=useMediaText();const toast=useToast();const {begin}=useLoading();
 const [data,setData]=useState(initial),[filters,setFilters]=useState(initial.filters),[page,setPage]=useState(initial.pagination.page);
 const [busy,setBusy]=useState(false),[stale,setStale]=useState(false),[selected,setSelected]=useState<Set<number>>(new Set()),[copied,setCopied]=useState<number|string|null>(null);
 const [detail,setDetail]=useState<AdminJobDetails|null>(null),[drawer,setDrawer]=useState(false),[confirmation,setConfirmation]=useState<{job:AdminJob;action:'retry'|'cancel'}|null>(null),[video,setVideo]=useState<AdminJob|null>(null),[actionBusy,setActionBusy]=useState(false);
 const listRequest=useRef<AbortController|null>(null),detailRequest=useRef<AbortController|null>(null),mounted=useRef(true),sequence=useRef(0),last=useRef(JSON.stringify([filters,page])),copyTimer=useRef<ReturnType<typeof setTimeout>|null>(null),pending=useRef(false),selectAll=useRef<HTMLInputElement|null>(null);
 const language=locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US';
 const number=(value:number)=>new Intl.NumberFormat(language,{maximumFractionDigits:2}).format(value);
 const date=(value:string|null)=>value?displayDate(value,true):'\u2014';
 const query=(f=filters,p=page)=>new URLSearchParams({...f,per_page:String(f.per_page),page:String(p)}).toString();
 function failure(e:unknown){if(e instanceof MediaApiError){if(e.status===409)return t('stale');return Object.values(e.fields).flat()[0]??error(e.code);}return t('error');}
 useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;listRequest.current?.abort();detailRequest.current?.abort();if(copyTimer.current)clearTimeout(copyTimer.current);};},[]);
 useEffect(()=>{if(selectAll.current)selectAll.current.indeterminate=selected.size>0&&selected.size<data.items.length;},[selected,data.items]);
 async function reload(f=filters,p=page,notify=false,silent=false){
  if(f.date==='custom'&&(!f.from||!f.to||f.to<f.from)){if(!silent)toast.error(t('invalidRange'));return;}
  if(silent&&pending.current)return;listRequest.current?.abort();const request=new AbortController();listRequest.current=request;const id=++sequence.current;pending.current=true;
  if(!silent)setBusy(true);const done=notify?begin():()=>{};
  try{const result=await mediaApi<AdminJobList>(data.endpoints.index+'?'+query(f,p),'GET',undefined,request.signal);
   if(mounted.current&&id===sequence.current){setData(result);setStale(false);setSelected(old=>new Set([...old].filter(key=>result.items.some(job=>job.id===key))));if(p>result.pagination.pages)setPage(result.pagination.pages);if(notify)toast.success(t('refreshed'));}}
  catch(e){if(mounted.current&&!request.signal.aborted){setStale(true);if(!silent)toast.error(failure(e));}}
  finally{done();if(mounted.current&&id===sequence.current){setBusy(false);pending.current=false;}}
 }
 useEffect(()=>{const key=JSON.stringify([filters,page]);if(key===last.current)return;if(filters.date==='custom'&&(!filters.from||!filters.to||filters.to<filters.from))return;
  const timer=setTimeout(()=>{last.current=key;void reload(filters,page);},300);return()=>clearTimeout(timer);},[filters,page]);
 useEffect(()=>{const timer=setInterval(()=>{if(document.hidden||confirmation)return;void reload(filters,page,false,true);if(drawer&&detail)void loadDetail(detail,false);},data.poll_milliseconds);return()=>clearInterval(timer);},[filters,page,drawer,detail?.id,confirmation,data.poll_milliseconds]);
 function goToPage(requested:number){
  const next=Math.max(1,Math.min(requested,data.pagination.pages));
  if(next===page&&next===data.pagination.page&&!stale)return;
  last.current=JSON.stringify([filters,next]);setPage(next);setSelected(new Set());
  void reload(filters,next);
 }
 function change(key:keyof AdminJobFilters,value:string|number){setFilters(old=>({...old,[key]:value}));setPage(1);setSelected(new Set());}
 async function loadDetail(job:AdminJob,foreground=true){
  detailRequest.current?.abort();const request=new AbortController();detailRequest.current=request;const done=foreground?begin():()=>{};
  try{const result=await mediaApi<AdminJobDetails>(job.links.details,'GET',undefined,request.signal);if(mounted.current&&!request.signal.aborted){setDetail(result);if(foreground)setDrawer(true);}}
  catch(e){if(foreground&&!request.signal.aborted)toast.error(failure(e));}finally{done();}
 }
 async function copy(value:string,key:number|string){try{await navigator.clipboard.writeText(value);setCopied(key);if(copyTimer.current)clearTimeout(copyTimer.current);copyTimer.current=setTimeout(()=>setCopied(null),2000);}catch{toast.error(t('error'));}}
 function toggle(id:number){setSelected(old=>{const next=new Set(old);next.has(id)?next.delete(id):next.add(id);return next;});}
 const allSelected=data.items.length>0&&data.items.every(job=>selected.has(job.id));
 const count=(status:string)=>data.counts[status||'all']??0;
 function Actions({job}:{job:AdminJob}){return <div className="aj-actions">
  <ActionTooltip label={t('view')}><button className="icon-button" aria-label={t('view')+' '+job.reference} onClick={()=>loadDetail(job)}><StudioIcon name="eye"/></button></ActionTooltip>
  {job.canRetry&&<ActionTooltip label={t('retry')}><button className="icon-button" aria-label={t('retry')+' '+job.reference} onClick={()=>setConfirmation({job,action:'retry'})}><StudioIcon name="refresh"/></button></ActionTooltip>}
  {job.canCancel&&<ActionTooltip label={t('cancel')}><button className="icon-button is-danger" aria-label={t('cancel')+' '+job.reference} onClick={()=>setConfirmation({job,action:'cancel'})}><StudioIcon name="close"/></button></ActionTooltip>}
  {job.status==='completed'&&job.hasVideo&&<ActionTooltip label={t('download')}><a className="icon-button" aria-label={t('download')+' '+job.reference} href={job.links.video+'?download=1'}><StudioIcon name="download"/></a></ActionTooltip>}
 </div>;}
 return <AppLayout area="admin" adminPage="jobMonitor" frame="dashboard" breadcrumbLabel={t('title')}><Head title={t('title')}/>
 <section className="aj-page">
  <header className="aj-page-header"><div className="aj-heading"><span className="aj-title-icon"><StudioIcon name="folder-play"/></span><div><h1>{t('title')}</h1><p>{t('description')}</p></div></div><div className="aj-header-actions"><Link className="media-button" href={data.endpoints.workers}><StudioIcon name="activity"/>{t('workerHealth')}</Link><button className="media-button primary" disabled={busy} onClick={()=>reload(filters,page,true)}><StudioIcon name={busy?'loader':'refresh'}/>{t('refresh')}</button></div></header>
  <div className="aj-summary">{summaries.map(([status,label,icon,tone])=><button key={label} type="button" className={'aj-stat tone-'+tone+(filters.status===status?' is-active':'')} onClick={()=>change('status',status)} aria-pressed={filters.status===status}><span className="aj-stat-icon"><StudioIcon name={icon}/></span><span><small>{t(label)}</small><strong>{number(count(status))}</strong></span></button>)}</div>
  <nav className="aj-tabs" aria-label={t('status')}>{[['','all'],['failed','toReview'],['retry_waiting','retry_waiting'],['awaiting_review','awaiting_review']].map(([status,label])=><button key={label} type="button" aria-pressed={filters.status===status} onClick={()=>change('status',status)} title={label==='toReview'?t('toReviewHint'):undefined}>{status&&<StudioIcon name={status==='failed'?'clock':status==='retry_waiting'?'refresh':'eye'}/>}<span>{t(label)}</span><b>{number(count(status))}</b></button>)}</nav>
  {(data.counts.queued??0)+(data.counts.retry_waiting??0)>0&&data.worker.state!=='online'&&<p className={'aj-worker-notice '+(data.worker.enabled&&data.worker.supported&&data.worker.state!=='failed'?'':'is-warning')}><StudioIcon name="info"/>{t(data.worker.state==='failed'?'workerIssue':data.worker.enabled&&data.worker.supported?'auto':'manual')}</p>}
  <div className="aj-table-card">
   <div className="aj-toolbar"><label className="search-input-row aj-search"><StudioIcon name="search"/><input type="search" value={filters.q} onChange={e=>change('q',e.target.value)} aria-label={t('search')} placeholder={t('search')} maxLength={100}/></label>
    <SelectField label={t('status')} value={filters.status} onChange={value=>change('status',value)} options={[{value:'',label:t('allStatuses')},...['queued','running','retry_waiting','awaiting_review','completed','failed','cancelled'].map(value=>({value,label:t(value)}))]}/>
    <SelectField label={t('type')} value={filters.type} onChange={value=>change('type',value)} options={[{value:'',label:t('allTypes')},...['import','validation','analysis','generate','preview'].map(value=>({value,label:t(value)}))]}/>
    <SelectField label={t('custom')} value={filters.date} onChange={value=>change('date',value)} options={[['all','allDates'],['today','today'],['week','last7'],['month','last30'],['custom','custom']].map(([value,label])=>({value,label:t(label)}))}/>
    <SelectField label={t('newest')} value={filters.sort} onChange={value=>change('sort',value)} options={['newest','oldest','longest'].map(value=>({value,label:t(value)}))}/>
    <ActionTooltip label={t('refresh')}><button className="icon-button" aria-label={t('refresh')} disabled={busy} onClick={()=>reload(filters,page,true)}><StudioIcon name={busy?'loader':'refresh'}/></button></ActionTooltip>
    <button className="media-button aj-clear" onClick={()=>{setFilters({...initial.filters,q:'',status:'',type:'',date:'month',from:'',to:'',sort:'newest',per_page:10,page:1});setPage(1);setSelected(new Set());}}>{t('clear')}</button>
   </div>
   {filters.date==='custom'&&<div className="aj-date-filters"><label>{t('from')}<DatePicker id="admin-jobs-from" today={data.today} locale={locale} value={filters.from} label={t('from')} onChange={v=>change('from',v)}/></label><label>{t('to')}<DatePicker id="admin-jobs-to" today={data.today} locale={locale} min={filters.from||undefined} value={filters.to} label={t('to')} onChange={v=>change('to',v)}/></label></div>}
   {selected.size>0&&<div className="aj-selection"><span>{number(selected.size)} {t('selected')}</span><button onClick={()=>copy(data.items.filter(job=>selected.has(job.id)).map(job=>job.reference).join('\n'),'selected')}><StudioIcon name={copied==='selected'?'check':'copy'}/>{t(copied==='selected'?'copied':'copySelected')}</button><button onClick={()=>setSelected(new Set())}>{t('clearSelection')}</button></div>}
   <div className="aj-table-scroll" aria-busy={busy}><table className="aj-table"><thead><tr><th><input ref={selectAll} type="checkbox" aria-label={t('selectPage')} checked={allSelected} onChange={()=>setSelected(allSelected?new Set():new Set(data.items.map(job=>job.id)))}/></th><th>#</th>{['customer','project','type','progress','status','startedDuration','actions'].map(key=><th key={key}>{t(key)}</th>)}</tr></thead><tbody>
   {data.items.map((job,index)=><tr key={job.id} className={selected.has(job.id)?'is-selected':''}>
    <td className="aj-select-cell"><input type="checkbox" checked={selected.has(job.id)} aria-label={t('select')+' '+job.reference} onChange={()=>toggle(job.id)}/></td><td className="aj-index">{(data.pagination.from??1)+index}</td>
    <td className="aj-customer-cell"><Customer job={job}/></td>
    <td className="aj-project-cell"><div className="aj-project"><button className="aj-thumbnail" aria-label={t('view')+' '+job.title} onClick={()=>loadDetail(job)}>{job.hasPoster?<img src={job.links.poster} alt="" loading="lazy" onError={e=>{e.currentTarget.style.display='none';}}/>:<StudioIcon name="play"/>}{job.sourceDuration!==null&&<time>{formatTime(job.sourceDuration).replace(/^00:/,'')}</time>}</button><span><button className="aj-title-link" onClick={()=>loadDetail(job)} title={job.title}>{job.title}</button><span className="aj-reference"><small>{job.reference}</small><span className="aj-copy-anchor"><ActionTooltip label={t('copy')}><button className="aj-copy" aria-label={t('copy')+' '+job.reference} onClick={()=>copy(job.reference,job.id)}><StudioIcon name={copied===job.id?'check':'copy'}/></button></ActionTooltip>{copied===job.id&&<span className="aj-copied" role="status">{t('copied')}</span>}</span></span></span></div></td>
    <td className="aj-type-cell" data-label={t('type')}><JobTypeBadge job={job}/></td>
    <td className="aj-progress-cell" data-label={t('progress')}><Progress job={job} date={date}/></td>
    <td className="aj-status-cell" data-label={t('status')}><OpsStatus status={job.status}/></td>
    <td className="aj-time-cell" data-label={t('startedDuration')}><time>{date(job.startedAt)}</time><small>{job.elapsedSeconds===null?'\u2014':formatTime(job.elapsedSeconds)}</small></td>
    <td className="aj-actions-cell"><Actions job={job}/></td>
   </tr>)}</tbody></table>{data.items.length===0&&<div className="aj-empty"><StudioIcon name="jobs"/><p>{t('empty')}</p></div>}</div>
   <footer className="aj-pagination"><span>{t('showing')} {number(data.pagination.from??0)}–{number(data.pagination.to??0)} {t('of')} {number(data.pagination.total)} {t('jobs')}</span><div><SelectField label={t('jobs')} value={String(filters.per_page)} options={[10,20,50].map(value=>({value:String(value),label:String(value)}))} onChange={value=>change('per_page',Number(value))}/><PageBoundaryButton edge="first" page={page} pages={data.pagination.pages} onNavigate={target=>goToPage(target)} className="icon-button"/><button className="icon-button" disabled={page<=1} aria-label={t('previous')} onClick={()=>goToPage(page-1)}><StudioIcon name="back"/></button>{[...new Set([1,page-1,page,page+1,data.pagination.pages])].filter(p=>p>0&&p<=data.pagination.pages).sort((a,b)=>a-b).map((p,index,array)=><span key={p}>{index>0&&p-array[index-1]>1&&<i>…</i>}<button className={'aj-page-button'+(page===p?' is-active':'')} type="button" aria-label={t('jobs')+' ? '+number(p)} aria-current={page===p?'page':undefined} onClick={()=>goToPage(p)}>{number(p)}</button></span>)}<button className="icon-button" disabled={page>=data.pagination.pages} aria-label={t('next')} onClick={()=>goToPage(page+1)}><StudioIcon name="arrow"/></button><PageBoundaryButton edge="last" page={page} pages={data.pagination.pages} onNavigate={target=>goToPage(target)} className="icon-button"/></div></footer>
  </div>
  <p className={'aj-live'+(stale?' is-stale':'')} role={stale?'status':undefined}><i/>{t(stale?'disconnected':'live')}<span>{date(data.refreshed_at)} · {data.timezone}</span></p>
 </section>

 <MediaDialog open={drawer} onClose={()=>setDrawer(false)} title={t('details')} headerIcon="jobs" headerDetail={detail?.reference} animated className="aj-drawer">
 {detail&&<><div className="aj-drawer-body"><div className="aj-detail-customer"><Customer job={detail}/><OpsStatus status={detail.status}/></div>
  <section className="aj-detail-section"><div className="aj-detail-project"><strong>{detail.title}</strong><JobTypeBadge job={detail}/></div><Progress job={detail} date={date}/><dl className="aj-facts">{[
   [t('source'),t(detail.source)],[t('mode'),t(detail.mode??String(detail.settings.mode??'subs'))],
   [t('output'),detail.settings.output==='srt'?'SRT':detail.settings.output==='mp3'?'MP3':detail.settings.output==='wav'?'WAV':'MP4 + SRT'],
   [t('duration'),detail.sourceDuration===null?'\u2014':formatTime(detail.sourceDuration)],
   [t('attempt'),detail.attempt===null?'\u2014':detail.attempt+(detail.maxAttempts?'/'+detail.maxAttempts:'')],
   [t('started'),date(detail.startedAt)],[t('finished'),date(detail.finishedAt)]
  ].map(([label,value])=><div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section>
  {detail.errorCode&&<section className="aj-error-section"><h3><StudioIcon name="alert"/>{t('errorDetails')}</h3><p>{error(detail.errorCode)}</p>{detail.nextRetryAt&&<small>{t('retryAt')}: {date(detail.nextRetryAt)}</small>}</section>}
  {detail.retryBlocked&&<p className="aj-muted">{detail.retryBlocked==='historical'?t('historical'):detail.retryBlocked==='account_unavailable'?t('accountBlocked'):error(detail.retryBlocked)}</p>}
  <JobStages job={detail}/>
  <section className="aj-detail-section"><h3><StudioIcon name="layers"/>{t('usage')}</h3>{detail.usage.package&&<span className="aj-package"><StudioIcon name="crown"/>{detail.usage.package.name}<small>v{detail.usage.package.version}</small></span>}
   {detail.usage.status?<><div className="aj-usage-totals"><div><small>{t('reserved')}</small><strong>{number(detail.usage.reserved_seconds/60)} {t('minutes')}</strong><span>{number(detail.usage.reserved_videos)} {t('videos')}</span></div><div><small>{t('used')}</small><strong>{number(detail.usage.charged_seconds/60)} {t('minutes')}</strong><span>{number(detail.usage.videos)} {t('videos')}</span></div></div><small className="aj-muted">{date(detail.usage.period_start)} – {date(detail.usage.period_end)}</small></>:<small className="aj-muted">{t('noCharge')}</small>}
   <Link className="aj-text-link" href={detail.usage.ledger_url}>{t('ledger')}<StudioIcon name="arrow"/></Link>
  </section>
  <section className="aj-detail-section"><h3><StudioIcon name="play"/>{t('results')}</h3><div className="aj-result-actions">
   {detail.hasVideo&&<><button className="media-button" onClick={()=>setVideo(detail)}><StudioIcon name="play"/>{t('preview')}</button><a className="media-button primary" href={detail.links.video+'?download=1'}><StudioIcon name="download"/>{t('download')}</a></>}
   {detail.hasSubtitles&&<a className="media-button" href={detail.links.subtitles+'?download=1'}><StudioIcon name="file"/>SRT</a>}
   {detail.hasAudio&&<a className="media-button" href={detail.links.audio+'?download=1'}><StudioIcon name="headphones"/>{detail.audioFormat}</a>}
   {!detail.hasVideo&&!detail.hasAudio&&!detail.hasSubtitles&&<small className="aj-muted">{t(detail.resultsExpired?'fileExpired':'noResult')}</small>}
  </div></section>
  <JobHistory job={detail} date={date}/>
 </div><footer className="aj-drawer-footer"><Link className="media-button" href={detail.customer_url}><StudioIcon name="users"/>{t('customerLink')}</Link><div>{detail.canRetry&&<button className="media-button primary" onClick={()=>setConfirmation({job:detail,action:'retry'})}><StudioIcon name="refresh"/>{t('retry')}</button>}{detail.canCancel&&<button className="media-button danger" onClick={()=>setConfirmation({job:detail,action:'cancel'})}><StudioIcon name="close"/>{t('cancel')}</button>}<button className="media-button" onClick={()=>setDrawer(false)}>{t('close')}</button></div></footer></>}
 </MediaDialog>
 <MediaDialog open={!!confirmation} onClose={()=>{if(!actionBusy)setConfirmation(null);}} title={t(confirmation?.action==='retry'?'retryTitle':'cancelTitle')} headerIcon={confirmation?.action==='retry'?'refresh':'shield'} headerDetail={confirmation?.job.reference} animated className="aj-action-dialog">
  {confirmation&&<Confirmation key={confirmation.job.id+confirmation.action} job={confirmation.job} action={confirmation.action} onBusyChange={setActionBusy} onClose={()=>setConfirmation(null)} onSaved={()=>{setConfirmation(null);void reload();if(drawer&&detail)void loadDetail(detail,false);}}/>}
 </MediaDialog>
 <MediaDialog open={!!video} onClose={()=>setVideo(null)} title={video?.title??t('results')} headerIcon="play" animated className="aj-video-dialog">{video&&<ResultVideo job={video}/>}</MediaDialog>
 </AppLayout>;
}
function JobStages({job}:{job:AdminJobDetails}){
 const {t}=useAdminJobsText();const stage=useOpsStage();
 return <section className="aj-detail-section"><h3><StudioIcon name="jobs"/>{t('stages')}</h3><ol className="aj-stages">{Object.entries(job.stages).map(([key,state])=><li key={key} className={'is-'+state}><span><StudioIcon name={state==='completed'?'check':state==='active'?'loader':state==='failed'?'alert':'clock'}/></span><strong>{stage(key)}</strong><small>{state==='active'?t('running'):state==='completed'?t('completed'):state==='failed'?t('failed'):state==='skipped'?'\u2014':t('queued')}</small></li>)}</ol>{Object.keys(job.stages).length===0&&<small className="aj-muted">{t('noHistory')}</small>}</section>;
}
function JobHistory({job,date}:{job:AdminJobDetails;date:(value:string|null)=>string}){
 const {t}=useAdminJobsText();const stage=useOpsStage();const {error}=useMediaText();
 const events=[...(job.events??[]).map((item,index)=>({key:'event'+index,at:item.at,title:t(item.status)+(item.stage?' · '+stage(item.stage):''),actor:t('system'),reason:item.errorCode?error(item.errorCode):''})),
  ...job.admin_actions.map(item=>({key:'admin'+item.id,at:item.at,title:t(item.action==='retry'?'retryTitle':'cancelTitle'),actor:item.actor??t('system'),reason:item.reason}))]
  .sort((a,b)=>new Date(a.at).valueOf()-new Date(b.at).valueOf());
 return <section className="aj-detail-section"><h3><StudioIcon name="clock"/>{t('history')}</h3>{!job.historyAvailable&&<small className="aj-muted">{t('noHistory')}</small>}<ol className="aj-events">{events.map(item=><li key={item.key}><strong>{item.title}</strong><small>{item.actor} · {date(item.at)}</small>{item.reason&&<p>{item.reason}</p>}</li>)}</ol></section>;
}
function Confirmation({job,action,onClose,onSaved,onBusyChange}:{job:AdminJob;action:'retry'|'cancel';onClose:()=>void;onSaved:()=>void;onBusyChange:(busy:boolean)=>void}){
 const {t}=useAdminJobsText();const {error}=useMediaText();const toast=useToast();
 const [reason,setReason]=useState(''),[invalid,setInvalid]=useState(''),[busy,setBusy]=useState(false);const request=useRef(crypto.randomUUID());const input=useRef<HTMLTextAreaElement|null>(null);
 async function submit(event:FormEvent){event.preventDefault();if(busy)return;if(reason.trim().length<3){setInvalid(t('reasonRequired'));toast.error(t('reasonRequired'));input.current?.focus();return;}
  setBusy(true);onBusyChange(true);setInvalid('');
  try{await mediaApi(job.links.action,'POST',{action,confirm:true,reason:reason.trim(),request_id:request.current});toast.success(t(action==='retry'?'retrySaved':'cancelSaved'));onSaved();}
  catch(e){if(e instanceof MediaApiError){const message=e.status===409?t('stale'):Object.values(e.fields).flat()[0]??error(e.code);setInvalid(message);toast.error(message);}else toast.error(t('error'));}
  finally{setBusy(false);onBusyChange(false);}
 }
 return <form onSubmit={submit} noValidate className="aj-action-form"><div className="aj-dialog-body"><Customer job={job}/><strong className="aj-confirm-project">{job.title}</strong><p className="aj-confirm-hint"><StudioIcon name="info"/>{t(action==='retry'?'retryHint':'cancelHint')}</p>{job.errorCode&&action==='retry'&&<p className="aj-confirm-error">{error(job.errorCode)}</p>}<label className="aj-reason"><span>{t('reason')} <b>*</b></span><textarea ref={input} rows={4} value={reason} maxLength={500} onChange={e=>{setReason(e.target.value);setInvalid('');}} aria-invalid={!!invalid} autoFocus/>{invalid&&<small className="aj-error" role="alert">{invalid}</small>}<small>{reason.length} / 500</small></label></div><footer className="aj-dialog-footer"><button type="button" className="media-button" onClick={onClose} disabled={busy}>{t('close')}</button><button type="submit" className={'media-button '+(action==='retry'?'primary':'danger')} disabled={busy}><StudioIcon name={busy?'loader':action==='retry'?'refresh':'shield'}/>{t(action==='retry'?'confirmRetry':'confirmCancel')}</button></footer></form>;
}
function ResultVideo({job}:{job:AdminJob}){
 const [failed,setFailed]=useState(false);const {t}=useAdminJobsText();
 return <div className="aj-video-player"><video src={job.links.video} poster={job.hasPoster?job.links.poster:undefined} controls autoPlay playsInline preload="metadata" onError={()=>setFailed(true)}/>{failed&&<p className="aj-error">{t('noResult')}</p>}</div>;
}
