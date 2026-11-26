import {Head,Link,usePage} from '@inertiajs/react';
import {useEffect,useRef,useState} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {MediaDialog} from '@/components/media-workflow';
import {mediaApi,MediaApiError} from '@/lib/media-api';
import {useDisplayDate} from '@/lib/platform-display';
import {useToast} from '@/lib/toast';
import {useScheduledText} from '@/lib/scheduled-tasks-i18n';
import type {SharedData} from '@/types';
import type {ScheduledOverview,ScheduledTask,TaskHistory,TaskRun,TaskState} from '@/types/scheduled-tasks';
import '../../../css/revenue.css';
import '../../../css/processing-report.css';
import '../../../css/scheduled-tasks.css';

type Endpoints={index:string;history:string;run:string};
const stateIcon:Record<TaskState,IconName>={running:'loader',queued:'hourglass',failed:'circle-x',late:'clock',success:'check',never:'info'};
const taskIcon:Record<string,IconName>={notifications:'bell',usage:'receipt',cleanup:'database',ai_usage:'wallet'};
const scheduleWord=(cron:string)=>cron==='* * * * *'?'every_minute':cron==='*/5 * * * *'?'every_5':cron==='0 * * * *'?'hourly':'daily';

export default function ScheduledTasks({overview:initial,endpoints}:{overview:ScheduledOverview;endpoints:Endpoints}){
 const {t}=useScheduledText();const toast=useToast();const displayDate=useDisplayDate();const {adminLinks}=usePage<SharedData>().props;
 const [data,setData]=useState(initial),[busy,setBusy]=useState(false),[confirm,setConfirm]=useState<ScheduledTask|null>(null),[sending,setSending]=useState(false);
 const [history,setHistory]=useState<{task:ScheduledTask;data:TaskHistory|null;status:string;page:number}|null>(null),[copied,setCopied]=useState('');
 const mounted=useRef(true),[,setNow]=useState(0);
 useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;};},[]);
 async function load(foreground=false){if(foreground)setBusy(true);try{const next=await mediaApi<ScheduledOverview>(endpoints.index,'GET');if(mounted.current)setData(next);}catch{if(foreground)toast.error(t('error'));}finally{if(mounted.current)setBusy(false);}}
 // Live view: refresh every 15 s while the tab is visible, and tick relative times every 5 s.
 useEffect(()=>{const poll=setInterval(()=>{if(!document.hidden)void load();},15000);const clock=setInterval(()=>setNow(value=>value+1),5000);return()=>{clearInterval(poll);clearInterval(clock);};},[]);
 const ago=(iso:string|null)=>{if(!iso)return '—';const s=Math.round((Date.now()-Date.parse(iso))/1000);return span(s,true);};
 const until=(iso:string)=>span(Math.round((Date.parse(iso)-Date.now())/1000),false);
 function span(seconds:number,past:boolean){const a=Math.abs(seconds);if(past&&a<10)return t('justNow');const text=a<60?a+' '+t('sec'):a<3600?Math.floor(a/60)+' '+t('min'):a<86400?Math.floor(a/3600)+' '+t('hr'):Math.floor(a/86400)+' '+t('day');return past?text+' '+t('ago'):seconds<=0?t('justNow'):t('in')+' '+text;}
 const duration=(ms:number|null)=>ms===null?'—':ms<1000?ms+' ms':ms<60000?(ms/1000).toFixed(1)+' '+t('sec'):Math.floor(ms/60000)+' '+t('min')+' '+Math.round(ms%60000/1000)+' '+t('sec');
 async function copy(value:string,key:string){try{await navigator.clipboard.writeText(value);setCopied(key);setTimeout(()=>mounted.current&&setCopied(''),1800);}catch{toast.error(t('error'));}}
 async function run(){if(!confirm)return;setSending(true);try{const next=await mediaApi<ScheduledOverview>(endpoints.run.replace('TASK',confirm.key),'POST',{confirm:true});setData(next);setConfirm(null);toast.success(t('requested'));}catch(error){toast.error(error instanceof MediaApiError&&error.status===422?t('busy'):t('error'));}finally{setSending(false);}}
 async function openHistory(task:ScheduledTask,status='',page=1){setHistory({task,data:history?.task.key===task.key?history.data:null,status,page});try{const result=await mediaApi<TaskHistory>(endpoints.history.replace('TASK',task.key)+'?'+new URLSearchParams({status,page:String(page)}),'GET');if(mounted.current)setHistory({task,data:result,status,page});}catch{toast.error(t('error'));}}
 const cron=data.scheduler,cronOk=cron.state==='running',workersOk=data.workers.online>0;
 const pending=(task:ScheduledTask)=>task.state==='queued'||task.state==='running';
 return <AppLayout area="admin" frame="preferences" adminPage="scheduledTasks" breadcrumbLabel={t('title')}><Head title={t('title')}/><section className="rv-page st-page">
  <header className="rv-page-heading"><div className="rv-title"><span className="rv-title-icon"><StudioIcon name="clock"/></span><div><h1>{t('title')}</h1><p>{t('subtitle')}</p></div></div>
   <div className="rv-heading-actions"><small className="st-updated">{t('updated')} {ago(data.refreshed_at)}</small><button type="button" className="media-button primary" disabled={busy} onClick={()=>void load(true)}><StudioIcon name="refresh" className={busy?'accounts-refresh-spin':''}/>{t('refresh')}</button></div></header>

  <div className="st-health">
   <article className={'st-health-card is-'+(cronOk?'ok':'bad')}><span className="st-health-icon"><StudioIcon name="clock"/></span><div><small>{t('scheduler')}</small><strong><i className="st-dot"/>{t(cron.state)}</strong><span>{t('lastTick')}: {cron.last_tick?ago(cron.last_tick):'—'}</span></div></article>
   <article className={'st-health-card is-'+(workersOk?'ok':'warn')}><span className="st-health-icon"><StudioIcon name="server"/></span><div><small>{t('workers')}</small><strong><i className="st-dot"/>{workersOk?data.workers.online+' '+t('online'):t('noWorker')}</strong>{adminLinks?.workerHealth&&<Link href={adminLinks.workerHealth}>{t('openWorkers')}<StudioIcon name="arrow"/></Link>}</div></article>
  </div>
  {!cronOk&&<Fix text={t('cronFix')} code={cron.cron_line} copied={copied==='cron'} copyLabel={t(copied==='cron'?'copied':'copy')} onCopy={()=>void copy(cron.cron_line,'cron')} tone="bad"/>}
  {!workersOk&&<Fix text={t('workerFix')} code={data.workers.restart} copied={copied==='worker'} copyLabel={t(copied==='worker'?'copied':'copy')} onCopy={()=>void copy(data.workers.restart,'worker')} tone="warn"/>}

  <section className="st-table" aria-busy={busy}>
   <div className="st-row st-head" role="row"><span>{t('task')}</span><span>{t('status')}</span><span>{t('lastRun')}</span><span>{t('nextRun')}</span><span>{t('last24')}</span><span>{t('actions')}</span></div>
   {data.tasks.map(task=>{const last=task.last;return <article key={task.key} className={'st-row st-task is-'+task.state}>
    <div className="st-name"><span className="st-task-icon"><StudioIcon name={taskIcon[task.key]??'activity'}/></span><div><strong>{t('task_'+task.key)}</strong><small>{t('task_'+task.key+'_hint')}</small><em>{t(scheduleWord(task.cron))}</em></div></div>
    <div data-label={t('status')}><span className={'st-pill is-'+task.state}><StudioIcon name={stateIcon[task.state]} className={task.state==='running'?'accounts-refresh-spin':''}/>{t(task.state==='never'?'neverRun':task.state)}</span></div>
    <div data-label={t('lastRun')} className="st-when">{last?<><strong title={last.finished_at?displayDate(last.finished_at,true):undefined}>{ago(last.finished_at)}</strong><small>{duration(last.duration_ms)}{last.trigger==='manual'?' · '+t('byAdmin'):''}</small></>:<strong>—</strong>}</div>
    <div data-label={t('nextRun')} className="st-when"><strong title={displayDate(task.next_run,true)}>{until(task.next_run)}</strong><small>{displayDate(task.next_run,true)}</small></div>
    <div data-label={t('last24')} className="st-counts"><span className="is-ok"><StudioIcon name="check"/>{task.day.success}</span><span className={task.day.failed?'is-bad':''}><StudioIcon name="circle-x"/>{task.day.failed}</span></div>
    <div className="st-actions"><button type="button" className="media-button" onClick={()=>void openHistory(task)}><StudioIcon name="list"/>{t('history')}</button><button type="button" className="media-button primary" disabled={pending(task)} onClick={()=>setConfirm(task)}><StudioIcon name="play"/>{t('runNow')}</button></div>
    {task.state==='failed'&&last?.error&&<p className="st-note is-bad"><StudioIcon name="alert"/><span><code>{last.error}</code><small>{t('retryNote')} {displayDate(task.next_run,true)}</small></span></p>}
    {task.state==='late'&&<p className="st-note is-warn"><StudioIcon name="clock"/><span>{t('lateNote')}</span></p>}
    {task.state==='queued'&&<p className="st-note"><StudioIcon name="hourglass"/><span>{t(cronOk?'queuedNote':'queuedNoCron')}</span></p>}
   </article>;})}
  </section>
  <p className="st-footer"><StudioIcon name="info"/>{t('footer')} {data.keep_days} {t('days')}.</p>

  <MediaDialog open={!!confirm} title={t('confirmTitle')} headerIcon="play" headerDetail={confirm?t('task_'+confirm.key):undefined} className="accounts-dialog st-confirm" animated onClose={()=>{if(!sending)setConfirm(null);}}>
   <div className="accounts-dialog-body"><p className="st-confirm-text">{t('confirmText')}</p></div>
   <footer className="accounts-dialog-footer"><button type="button" className="media-button" disabled={sending} onClick={()=>setConfirm(null)}>{t('cancel')}</button><button type="button" className="media-button primary" disabled={sending} onClick={()=>void run()}><StudioIcon name={sending?'loader':'play'} className={sending?'accounts-refresh-spin':''}/>{t('runNow')}</button></footer>
  </MediaDialog>

  <MediaDialog open={!!history} title={t('history')} headerIcon="list" headerDetail={history?t('task_'+history.task.key):undefined} className="accounts-dialog st-history" animated onClose={()=>setHistory(null)}>
   {history&&<div className="accounts-dialog-body">
    <nav className="st-filter" aria-label={t('status')}>{(['','success','failed'] as const).map(status=><button type="button" key={status||'all'} aria-pressed={history.status===status} className={history.status===status?'is-active':''} onClick={()=>void openHistory(history.task,status,1)}>{t(status||'all')}</button>)}<small>{history.data?history.data.pagination.total:'…'}</small></nav>
    {!history.data?<p className="st-empty"><StudioIcon name="loader" className="accounts-refresh-spin"/></p>:history.data.items.length===0?<p className="st-empty">{t('noHistory')}</p>:
     <ul className="st-runs">{history.data.items.map(run=><RunRow key={run.id} run={run} displayDate={displayDate} duration={duration}/>)}</ul>}
    {history.data&&history.data.pagination.pages>1&&<footer className="st-pages"><button type="button" className="media-button" disabled={history.page<=1} onClick={()=>void openHistory(history.task,history.status,history.page-1)}><StudioIcon name="back"/>{t('previous')}</button><strong>{history.page} / {history.data.pagination.pages}</strong><button type="button" className="media-button" disabled={history.page>=history.data.pagination.pages} onClick={()=>void openHistory(history.task,history.status,history.page+1)}>{t('next')}<StudioIcon name="arrow"/></button></footer>}
   </div>}
  </MediaDialog>
 </section></AppLayout>;
}

function Fix({text,code,copyLabel,copied,onCopy,tone}:{text:string;code:string;copyLabel:string;copied:boolean;onCopy:()=>void;tone:'bad'|'warn'}){
 return <div className={'st-fix is-'+tone}><StudioIcon name="alert"/><div><p>{text}</p><div className="st-code"><code>{code}</code><button type="button" onClick={onCopy}><StudioIcon name={copied?'check':'copy'}/>{copyLabel}</button></div></div></div>;
}

function RunRow({run,displayDate,duration}:{run:TaskRun;displayDate:(value:string,time?:boolean)=>string;duration:(ms:number|null)=>string}){
 const {t}=useScheduledText();const detail=run.error||run.output;const state:TaskState=run.status==='queued'?'queued':run.status;
 return <li className={'st-run is-'+run.status}><div className="st-run-main"><span className={'st-pill is-'+state}><StudioIcon name={stateIcon[state]}/>{t(state)}</span><strong>{run.started_at?displayDate(run.started_at,true):run.created_at?displayDate(run.created_at,true):'—'}</strong><small>{duration(run.duration_ms)}</small><small>{run.trigger==='manual'?t('byAdmin')+(run.requested_by?' · '+run.requested_by:''):t('bySchedule')}</small></div>
  {detail&&<details><summary>{t('details')}</summary><pre>{detail}</pre></details>}</li>;
}
