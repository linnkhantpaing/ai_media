import {PageBoundaryButton} from '@/components/page-boundary-button';
import {useDisplayDate} from '@/lib/platform-display';
import {Head,Link} from '@inertiajs/react';
import {useEffect,useRef,useState} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {MediaDialog,SelectField} from '@/components/media-workflow';
import {OpsStatus,useOpsStage} from '@/components/operations';
import {useWorkerHealthText} from '@/lib/worker-health-i18n';
import {useLoading} from '@/lib/loading';
import {useToast} from '@/lib/toast';
import {mediaApi,MediaApiError,formatTime} from '@/lib/media-api';
import type {HealthFilters,HealthWorker,HealthWorkerDetail,WorkerHealthData,WorkerState} from '@/types/worker-health';
import '../../../css/worker-health.css';

const icons:Record<WorkerState,IconName>={busy:'triangle-play',idle:'check',stale:'clock',unknown:'info',stopped:'ban',issue:'alert'};
function WorkerStatus({state}:{state:WorkerState}){const {t}=useWorkerHealthText();return <span className={'wh-status is-'+state}><StudioIcon name={icons[state]}/>{t(state)}</span>;}
function WorkerIdentity({worker}:{worker:HealthWorker}){return <div className="wh-identity"><span className="wh-worker-icon"><StudioIcon name="cube"/></span><span><strong title={worker.name}>{worker.name}</strong><small>{worker.reference}</small></span></div>;}
function CurrentJob({worker}:{worker:HealthWorker}){const {t}=useWorkerHealthText();const stage=useOpsStage();if(!worker.job)return <div className="wh-no-job"><strong>—</strong><small>{worker.state==='idle'?t('noJob'):t('jobUnknown')}</small></div>;return <div className="wh-current-job">{!worker.jobCurrent&&<small className="wh-last-job">{t('lastJob')}</small>}<Link href={worker.job.url}><StudioIcon name="play"/>{worker.job.reference}</Link><span title={worker.job.title}>{worker.job.title}</span>{worker.jobCurrent&&<><div className="wh-progress-label"><small>{worker.job.stage?stage(worker.job.stage):t('processing')}</small>{worker.job.progress!==null&&<strong>{worker.job.progress}%</strong>}</div><div className={'wh-progress'+(worker.job.progress===null?' is-indeterminate':'')} role="progressbar" aria-label={t('processing')} aria-valuemin={0} aria-valuemax={100} aria-valuenow={worker.job.progress??undefined}><i style={{width:worker.job.progress===null?'35%':worker.job.progress+'%'}}/></div></>}</div>;}

export default function WorkerHealth({health:initial}:{health:WorkerHealthData}){
 const displayDate=useDisplayDate();
 const {t,locale}=useWorkerHealthText();const toast=useToast();const {begin}=useLoading();
 const [data,setData]=useState(initial),[filters,setFilters]=useState(initial.filters),[page,setPage]=useState(initial.pagination.page);
 const [busy,setBusy]=useState(false),[stale,setStale]=useState(false),[expired,setExpired]=useState(false),[selected,setSelected]=useState<Set<string>>(new Set()),[copied,setCopied]=useState<string|null>(null);
 const [detail,setDetail]=useState<HealthWorkerDetail|null>(null),[drawer,setDrawer]=useState(false);
 const mounted=useRef(true),request=useRef<AbortController|null>(null),detailRequest=useRef<AbortController|null>(null),sequence=useRef(0),pending=useRef(false),last=useRef(JSON.stringify([filters,page])),copyTimer=useRef<ReturnType<typeof setTimeout>|null>(null),allBox=useRef<HTMLInputElement|null>(null);
 const language=locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US';
 const number=(value:number)=>new Intl.NumberFormat(language,{maximumFractionDigits:0}).format(value);
 const date=(value:string|null)=>value?displayDate(value,true):'—';
 const relative=(seconds:number|null)=>seconds===null?'—':seconds<10?t('justNow'):seconds<60?number(seconds)+' '+t('secondsAgo'):seconds<3600?number(Math.floor(seconds/60))+' '+t('minutesAgo'):seconds<86400?number(Math.floor(seconds/3600))+' '+t('hoursAgo'):number(Math.floor(seconds/86400))+' '+t('daysAgo');
 const query=(f:HealthFilters,p:number)=>new URLSearchParams({q:f.q,status:f.status,type:f.type,scope:f.scope,per_page:String(f.per_page),page:String(p)}).toString();
 useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;request.current?.abort();detailRequest.current?.abort();if(copyTimer.current)clearTimeout(copyTimer.current);};},[]);
 useEffect(()=>{if(allBox.current)allBox.current.indeterminate=selected.size>0&&selected.size<data.items.length;},[selected,data.items]);
 async function reload(f=filters,p=page,foreground=false,silent=false){
  if(silent&&pending.current)return;request.current?.abort();const abort=new AbortController();request.current=abort;const id=++sequence.current;pending.current=true;
  if(!silent)setBusy(true);const done=foreground?begin():()=>{};
  try{const result=await mediaApi<WorkerHealthData>(data.endpoints.index+'?'+query(f,p),'GET',undefined,abort.signal);
   if(mounted.current&&id===sequence.current){setData(result);setStale(false);setExpired(false);setSelected(old=>new Set([...old].filter(value=>result.items.some(worker=>worker.id===value))));if(p!==result.pagination.page){last.current=JSON.stringify([f,result.pagination.page]);setPage(result.pagination.page);}if(foreground)toast.success(t('refreshed'));}}
  catch(error){if(mounted.current&&!abort.signal.aborted){setStale(true);if(error instanceof MediaApiError&&[401,419].includes(error.status))setExpired(true);if(!silent)toast.error(t('error'));}}
  finally{done();if(mounted.current&&id===sequence.current){setBusy(false);pending.current=false;}}
 }
 useEffect(()=>{const key=JSON.stringify([filters,page]);if(key===last.current)return;const timer=setTimeout(()=>{last.current=key;void reload(filters,page);},300);return()=>clearTimeout(timer);},[filters,page]);
 useEffect(()=>{const timer=setInterval(()=>{if(document.hidden)return;void reload(filters,page,false,true);if(drawer&&detail)void showDetail(detail,false);},data.pollMilliseconds);return()=>clearInterval(timer);},[filters,page,drawer,detail?.id,data.pollMilliseconds]);
 function change(key:keyof HealthFilters,value:string|number|boolean){request.current?.abort();sequence.current++;pending.current=false;setBusy(false);setFilters(old=>({...old,[key]:value,...(key==='scope'?{status:''}:{})}));setPage(1);setSelected(new Set());}
 function goToPage(value:number){const next=Math.max(1,Math.min(value,data.pagination.pages));if(next===page&&next===data.pagination.page&&!stale)return;last.current=JSON.stringify([filters,next]);setPage(next);setSelected(new Set());void reload(filters,next);}
 async function showDetail(worker:HealthWorker,foreground=true){
  detailRequest.current?.abort();const abort=new AbortController();detailRequest.current=abort;const done=foreground?begin():()=>{};
  try{const result=await mediaApi<HealthWorkerDetail>(worker.links.details,'GET',undefined,abort.signal);if(mounted.current&&!abort.signal.aborted){setDetail(result);if(foreground)setDrawer(true);}}
  catch(error){if(foreground&&!abort.signal.aborted)toast.error(t(error instanceof MediaApiError&&error.status===404?'missing':'error'));}
  finally{done();}
 }
 async function copy(value:string,key:string){try{await navigator.clipboard.writeText(value);setCopied(key);if(copyTimer.current)clearTimeout(copyTimer.current);copyTimer.current=setTimeout(()=>setCopied(null),2000);}catch{toast.error(t('error'));}}
 function toggle(id:string){setSelected(old=>{const next=new Set(old);next.has(id)?next.delete(id):next.add(id);return next;});}
 const stats:[keyof WorkerHealthData['counts'],string,IconName,string,string][]=[
  ['online','online','server','success',data.counts.online===0&&data.processingState==='standby'?'noActive':'onlineHint'],
  ['busy','busyWorkers','refresh','info','busyHint'],['idle','idleWorkers','check','primary','idleWorkersHint'],
  ['attention','needsAttention','alert','warning',data.counts.attention?'attentionCountHint':'noIssues']];
 const allSelected=data.items.length>0&&data.items.every(worker=>selected.has(worker.id));
 const filtered=!!(filters.q||filters.status||filters.type);
 const processingIcon:IconName=data.processingState==='attention'?'alert':data.processingState==='starting'?'loader':data.processingState==='processing'?'activity':data.processingState==='idle'?'check':'play';
 return <AppLayout area="admin" adminPage="workerHealth" frame="workerHealth" breadcrumbLabel={t('title')}><Head title={t('title')}/>
 <section className="wh-page">
  <header className="wh-page-header"><div className="wh-heading"><span className="wh-title-icon"><StudioIcon name="server"/></span><div><h1>{t('title')}</h1><p>{t('description')}</p></div></div><div className="wh-header-actions"><Link className="media-button" href={data.endpoints.jobs}><StudioIcon name="folder-play"/>{t('jobs')}</Link><button className="media-button primary" onClick={()=>reload(filters,page,true)} disabled={busy}><StudioIcon name={busy?'loader':'refresh'}/>{t('refresh')}</button></div></header>
  <section className={'wh-processing is-'+data.processingState} aria-label={t('statusTitle')}><h2><StudioIcon name="settings"/>{t('statusTitle')}</h2><span className="wh-mode">{t('mode')}: <b className={data.automatic.enabled?'is-auto':''}>{t(data.automatic.enabled?'automatic':'manual')}</b></span><span className="wh-processing-state"><StudioIcon name={processingIcon}/>{t(data.processingState)}</span><p>{t(data.processingState+'Hint')}</p><Link href={data.endpoints.settings}><StudioIcon name="settings"/>{t('settings')}</Link></section>
  <div className="wh-summary">{stats.map(([key,label,icon,tone,hint])=><article className={'wh-stat tone-'+tone} key={key}><span className="wh-stat-icon"><StudioIcon name={icon}/></span><div><small>{t(label)}</small><strong>{number(data.counts[key])}</strong><p>{t(hint)}</p></div></article>)}</div>
  {stale&&<div className="wh-connection" role="status"><StudioIcon name="alert"/><span>{t('disconnected')} <small>{date(data.refreshedAt)}</small></span>{expired&&<a href={data.endpoints.login}>{t('signIn')}</a>}</div>}
  <section className="wh-table-card">
   <header className="wh-toolbar"><div className="wh-workers-heading"><h2><StudioIcon name="server"/>{t('workers')}</h2><nav aria-label={t('workers')}>{[['all','allWorkers'],['current','current'],['history','history']].map(([scope,label])=><button key={scope} aria-pressed={filters.scope===scope} onClick={()=>change('scope',scope)}>{t(label)}{scope==='history'&&<small>{number(data.historyTotal)}</small>}</button>)}</nav></div>
    <SelectField label={t('status')} value={filters.status} options={[{value:'',label:t('allStatus')},...(filters.scope==='history'?['stopped','issue']:filters.scope==='current'?['busy','idle','stale','unknown']:['busy','idle','stale','unknown','stopped','issue']).map(value=>({value,label:t(value)}))]} onChange={value=>change('status',value)}/>
    <SelectField label={t('type')} value={filters.type} options={[{value:'',label:t('allTypes')},...['processing','preparation','mixed','unknown'].map(value=>({value,label:t(value)}))]} onChange={value=>change('type',value)}/>
    <label className="search-input-row wh-search"><StudioIcon name="search"/><input type="search" maxLength={100} value={filters.q} onChange={event=>change('q',event.target.value)} placeholder={t('search')} aria-label={t('search')}/></label>
   </header>
   {selected.size>0&&<div className="wh-selected"><span>{number(selected.size)} {t('selected')}</span><button onClick={()=>copy(data.items.filter(worker=>selected.has(worker.id)).map(worker=>worker.id).join('\n'),'selection')}><StudioIcon name={copied==='selection'?'check':'copy'}/>{t(copied==='selection'?'copied':'copySelected')}</button></div>}
   <div className="wh-table-scroll" aria-busy={busy}><table className="wh-table"><thead><tr><th><input ref={allBox} type="checkbox" aria-label={t('selectPage')} checked={allSelected} onChange={()=>setSelected(allSelected?new Set():new Set(data.items.map(worker=>worker.id)))}/></th><th>#</th>{['worker','type','status','currentJob','uptime','heartbeat','actions'].map(key=><th key={key}>{t(key)}</th>)}</tr></thead><tbody>{data.items.map((worker,index)=><tr key={worker.id} className={selected.has(worker.id)?'is-selected':''}>
    <td className="wh-check-cell"><input type="checkbox" aria-label={t('select')+' '+worker.name} checked={selected.has(worker.id)} onChange={()=>toggle(worker.id)}/></td><td className="wh-row-index">{(data.pagination.from??1)+index}</td>
    <td className="wh-worker-cell"><WorkerIdentity worker={worker}/></td>
    <td className="wh-type-cell" data-label={t('type')}><span className="wh-type">{t(worker.type)}</span></td>
    <td className="wh-status-cell" data-label={t('status')}><WorkerStatus state={worker.state}/></td>
    <td className="wh-job-cell" data-label={worker.jobCurrent?t('currentJob'):t('lastJob')}><CurrentJob worker={worker}/></td>
    <td className="wh-uptime-cell" data-label={t('uptime')} title={worker.uptimeCurrent?t('uptime'):t('lastUptime')}><StudioIcon name="clock"/><span>{worker.uptimeSeconds===null?'—':formatTime(worker.uptimeSeconds)}</span></td>
    <td className="wh-heartbeat-cell" data-label={t('heartbeat')}><span className={'wh-heartbeat is-'+worker.state} title={date(worker.heartbeatAt)}><i/>{relative(worker.heartbeatSeconds)}</span></td>
    <td className="wh-actions-cell"><div className="wh-row-actions"><ActionTooltip label={t('details')}><button className="icon-button" aria-label={t('details')+' '+worker.reference} onClick={()=>showDetail(worker)}><StudioIcon name="chart"/></button></ActionTooltip><ActionTooltip label={t('refresh')}><button className="icon-button" aria-label={t('refresh')+' '+worker.reference} onClick={()=>reload(filters,page,true)} disabled={busy}><StudioIcon name="refresh"/></button></ActionTooltip>{worker.links.job&&<ActionTooltip label={t('openJob')}><Link className="icon-button" href={worker.links.job} aria-label={t('openJob')+' '+worker.reference}><StudioIcon name="arrow"/></Link></ActionTooltip>}</div></td>
   </tr>)}</tbody></table>{data.items.length===0&&<div className="wh-empty"><span><StudioIcon name={data.processingState==='standby'?'play':'server'}/></span><h3>{t(filtered?'noMatches':filters.scope==='history'?'noHistory':data.processingState==='standby'?'emptyStandby':'empty')}</h3>{!filtered&&filters.scope!=='history'&&<p>{t('emptyHint')}</p>}{filtered?<button className="media-button" onClick={()=>{change('q','');setFilters(old=>({...old,status:'',type:''}));}}>{t('clear')}</button>:filters.scope!=='history'&&data.historyTotal>0&&<button className="media-button" onClick={()=>change('scope','history')}><StudioIcon name="clock"/>{t('history')}<span>{number(data.historyTotal)}</span></button>}</div>}</div>
   <footer className="wh-table-footer"><p><StudioIcon name="info"/>{t(data.automatic.enabled?'standbyNote':'manualNote')}</p><div className="wh-pagination"><small>{t('showing')} {number(data.pagination.from??0)}–{number(data.pagination.to??0)} {t('of')} {number(data.pagination.total)}</small><SelectField label={t('workers')} value={String(filters.per_page)} options={[10,20,50].map(value=>({value:String(value),label:String(value)}))} onChange={value=>change('per_page',Number(value))}/><PageBoundaryButton edge="first" page={page} pages={data.pagination.pages} onNavigate={target=>goToPage(target)} className="icon-button"/><button className="icon-button" aria-label={t('previous')} disabled={page<=1} onClick={()=>goToPage(page-1)}><StudioIcon name="back"/></button>{[...new Set([1,page-1,page,page+1,data.pagination.pages])].filter(value=>value>0&&value<=data.pagination.pages).sort((a,b)=>a-b).map((value,index,values)=><span key={value}>{index>0&&value-values[index-1]>1&&<i>…</i>}<button className={'wh-page-button'+(value===page?' is-active':'')} aria-current={value===page?'page':undefined} onClick={()=>goToPage(value)}>{number(value)}</button></span>)}<button className="icon-button" aria-label={t('next')} disabled={page>=data.pagination.pages} onClick={()=>goToPage(page+1)}><StudioIcon name="arrow"/></button><PageBoundaryButton edge="last" page={page} pages={data.pagination.pages} onNavigate={target=>goToPage(target)} className="icon-button"/></div></footer>
  </section>
  <section className="wh-queue-section"><h2><StudioIcon name="layers"/>{t('queueTitle')}</h2>{data.queueReported?<div className="wh-queues">{data.queues.map(queue=><article className="wh-queue" key={queue.name}><header><span><StudioIcon name={queue.name==='media-inspect'?'upload':'play'}/></span><div><h3>{t(queue.name==='media-inspect'?'preparationQueue':'processingQueue')}</h3><p>{t(queue.name==='media-inspect'?'queuePrepareHint':'queueProcessingHint')}</p></div><Link href={data.endpoints.jobs}>{t('viewJobs')}<StudioIcon name="arrow"/></Link></header><div className="wh-queue-totals"><div><small>{t('ready')}</small><strong>{number(queue.ready)}</strong></div><div><small>{t('delayed')}</small><strong>{number(queue.delayed)}</strong></div><div><ActionTooltip label={t('claimedHint')}><span className="wh-claimed-label">{t('claimed')}<StudioIcon name="info"/></span></ActionTooltip><strong>{number(queue.reserved)}</strong></div></div><footer><span>{t('oldest')}</span><time>{queue.oldestReadyAt?date(queue.oldestReadyAt):t('noWaiting')}</time></footer></article>)}</div>:<p className="wh-queue-unknown"><StudioIcon name="info"/>{t('queueUnknown')}</p>}</section>
  <p className={'wh-live'+(stale?' is-stale':'')}><i/>{t(stale?'disconnected':'live')}<time>{date(data.refreshedAt)} · {data.timezone}</time></p>
  <svg className="wh-wave" viewBox="0 0 1200 130" preserveAspectRatio="none" aria-hidden="true"><path d="M0 80C140 0 220 20 380 90S600 30 750 65S1000 110 1200 35V130H0Z"/><path d="M0 110C200 30 350 130 520 100S770 25 940 90S1100 10 1200 60V130H0Z"/></svg>
 </section>
 <MediaDialog open={drawer} onClose={()=>setDrawer(false)} title={t('details')} headerIcon="server" headerDetail={detail?.reference} animated className="wh-panel">
  {detail&&<><div className="wh-panel-body"><div className="wh-detail-identity"><WorkerIdentity worker={detail}/><WorkerStatus state={detail.state}/></div><dl className="wh-detail-facts">{[
   [t('type'),t(detail.type)],[t('runMode'),t(detail.automatic?'automatic':'manual')],
   [t('capacity'),detail.usedSlots===null?'—':number(detail.usedSlots)+' / '+number(detail.capacity)],
   [t('uptime'),detail.uptimeSeconds===null?'—':formatTime(detail.uptimeSeconds)],
   [t('registered'),date(detail.registeredAt)],[t('heartbeat'),date(detail.heartbeatAt)],
   ...(detail.stoppedAt?[[t('stoppedAt'),date(detail.stoppedAt)]]:[]),
   [t('queues'),detail.queues.map(queue=>t(queue==='media-inspect'?'preparation':'processing')).join(' · ')||t('unknown')]
  ].map(([label,value])=><div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>
  <p className="wh-heartbeat-policy"><StudioIcon name="info"/>{t('heartbeatHint')}: {number(detail.heartbeatInterval)}s · {t('timeout')}: {number(detail.heartbeatTtl)}s</p>
  <section className="wh-detail-section"><h3><StudioIcon name="play"/>{t(detail.jobCurrent?'currentJob':'lastJob')}</h3><CurrentJob worker={detail}/>{detail.job&&<OpsStatus status={detail.job.status}/>}</section>
  {detail.errorCode&&<section className="wh-detail-error"><h3><StudioIcon name="alert"/>{t('issueTitle')}</h3><p>{t('workerError')}</p><Link href={data.endpoints.settings}>{t('settings')}<StudioIcon name="arrow"/></Link></section>}
  <section className="wh-detail-section"><h3><StudioIcon name="clock"/>{t('milestones')}</h3><ol className="wh-timeline">{detail.events.map(event=><li key={event.type}><strong>{t(event.type==='stopped'?'stoppedAt':event.type)}</strong><time>{date(event.at)}</time></li>)}</ol></section>
  <section className="wh-detail-section"><h3><StudioIcon name="jobs"/>{t('recentJobs')}</h3>{detail.recentJobs.length?<div className="wh-recent-jobs">{detail.recentJobs.map(job=><Link href={job.url} key={job.id}><span><strong>{job.title}</strong><small>{job.reference}</small></span><OpsStatus status={job.status}/><StudioIcon name="arrow"/></Link>)}</div>:<p className="wh-muted">{t('jobUnknown')}</p>}</section>
  <div className="wh-worker-id"><span>{detail.id}</span><span className="wh-copy-anchor"><ActionTooltip label={t('copy')}><button className="icon-button" aria-label={t('copy')} onClick={()=>copy(detail.id,'worker')}><StudioIcon name={copied==='worker'?'check':'copy'}/></button></ActionTooltip>{copied==='worker'&&<span className="wh-copied" role="status">{t('copied')}</span>}</span></div>
  </div><footer className="wh-panel-footer"><button className="media-button" onClick={()=>setDrawer(false)}>{t('close')}</button>{detail.links.job&&<Link className="media-button primary" href={detail.links.job}>{t('openJob')}<StudioIcon name="arrow"/></Link>}</footer></>}
 </MediaDialog>
 </AppLayout>;
}

