import {Head, Link, router, usePage} from '@inertiajs/react';
import {useCallback, useEffect, useMemo, useRef, useState, type ReactNode} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon, type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {ConfirmDialog} from '@/components/media-workflow';
import {LibraryMenu, type LibraryAction} from '@/components/project-library';
import {announceNotificationChange, notificationVisual, useNotificationChanges, useRelativeTime} from '@/components/customer-notifications';
import {formatBytes, mediaApi} from '@/lib/media-api';
import {useDisplayDate} from '@/lib/platform-display';
import {useMediaText} from '@/lib/media-i18n';
import {useNotificationText} from '@/lib/customer-notifications-i18n';
import {useDashboardText, type DashboardWord} from '@/lib/customer-dashboard-i18n';
import {useToast} from '@/lib/toast';
import type {SharedData} from '@/types';
import type {NotificationFeed, CustomerNotification} from '@/types/customer-notifications';
import type {BillingRow, ChecklistStep, DashboardBilling, DashboardAction, DashboardData, DashboardMetric, DashboardPlan, DashboardProps, DashboardSection, DashboardUsage, DashboardWork, DashboardWorkflow, RecentProject, UsageRange, WorkItem} from '@/types/customer-dashboard';
import '../../css/project-library.css';
import '../../css/customer-dashboard.css';

type Retry=(section:DashboardSection)=>void;
const LIVE:DashboardSection[]=['plan','actions','work','recent','checklist','billing'];
const dismissedKey='zatlan.dashboard.dismissed';
const typeWord:Record<DashboardWorkflow,DashboardWord>={movie:'t_movie',translate:'t_translate',subtitles:'t_subtitles'};

export default function Workspace({dashboard,dashboardLinks}:DashboardProps){
 const {t}=useDashboardText();
 const [data,setData]=useState<DashboardData>(dashboard);
 const [retrying,setRetrying]=useState<DashboardSection[]>([]);
 const pending=useRef(false),busy=useRef(false);
 const active=!!data.work?.progress.total;
 const merge=useCallback((next:DashboardData,sections:DashboardSection[])=>{
  setData(previous=>{
   const result:DashboardData={...previous,errors:previous.errors.filter(section=>!sections.includes(section))};
   for(const section of sections){
    if(next.errors.includes(section)){if(previous[section]===undefined||previous[section]===null)result.errors.push(section);}
    else (result as Record<string,unknown>)[section]=(next as Record<string,unknown>)[section];
   }
   return result;
  });
 },[]);
 const refresh=useCallback(async(sections:DashboardSection[]=LIVE)=>{
  if(pending.current&&sections===LIVE)return;
  if(sections===LIVE)pending.current=true;
  try{const next=await mediaApi<DashboardData>(dashboardLinks.live+'?'+sections.map(section=>'sections[]='+section).join('&'));merge(next,sections);}
  catch{if(sections!==LIVE)setData(previous=>({...previous,errors:[...new Set([...previous.errors,...sections])]}));}
  finally{if(sections===LIVE)pending.current=false;}
 },[dashboardLinks.live,merge]);
 const retry:Retry=section=>{setRetrying(list=>[...list,section]);void refresh([section]).finally(()=>setRetrying(list=>list.filter(item=>item!==section)));};
 useEffect(()=>{
  let timer:ReturnType<typeof setTimeout>;let stopped=false;
  const tick=()=>{timer=setTimeout(async()=>{if(stopped)return;if(!document.hidden&&!busy.current)await refresh();if(!stopped)tick();},active?10000:30000);};
  tick();
  const wake=()=>{if(!document.hidden)void refresh();};
  const changed=()=>{clearTimeout(timer);timer=setTimeout(()=>{void refresh().then(()=>{if(!stopped)tick();});},400);};
  window.addEventListener('focus',wake);document.addEventListener('visibilitychange',wake);window.addEventListener('media-queue-changed',changed);
  return()=>{stopped=true;clearTimeout(timer);window.removeEventListener('focus',wake);document.removeEventListener('visibilitychange',wake);window.removeEventListener('media-queue-changed',changed);};
 },[active,refresh]);
 const state=(section:DashboardSection)=>({error:data.errors.includes(section)&&!data[section],loading:retrying.includes(section),onRetry:()=>retry(section)});
 const checklist=data.checklist;
 return <AppLayout frame="dashboard"><Head title={t('dashboard')}/>
  <div className="db">
   <div className="db-grid">
    <div className="db-col db-main">
     <Greeting plan={data.plan??null}/>
     <ActionStrip items={data.actions??[]}/>
     <KpiRow plan={data.plan??null} work={data.work??null} {...state('plan')}/>
     {checklist&&<Checklist checklist={checklist} link={dashboardLinks.checklist} onHidden={()=>setData(previous=>({...previous,checklist:null}))}/>}
     {(!checklist||!!data.work?.items.length)&&<ContinueWorking work={data.work??null} {...state('work')} busy={busy} onChanged={()=>void refresh()}/>}
     {(!checklist||!!data.recent?.length)&&<RecentProjects items={data.recent??null} {...state('recent')}/>}
     <UsageChart initial={data.usage??null} plan={data.plan??null} link={dashboardLinks.usage} {...state('usage')}/>
    </div>
    <div className="db-col db-side">
     <PlanCard plan={data.plan??null} {...state('plan')}/>
     <QuickStart plan={data.plan??null}/>
     <RecentNotifications initial={data.notifications??null} {...state('notifications')}/>
     <BillingSnapshot billing={data.billing??null} {...state('billing')}/>
     <SupportCard/>
    </div>
   </div>
  </div>
 </AppLayout>;
}

/* ───────────── Shared pieces ───────────── */

function Card({className,icon,title,extra,children,error,loading,onRetry,skeleton=3}:{className:string;icon:IconName;title:ReactNode;extra?:ReactNode;children?:ReactNode;error?:boolean;loading?:boolean;onRetry?:()=>void;skeleton?:number}){
 const {t}=useDashboardText();
 return <section className={'db-card '+className}>
  <header className="db-card-head"><h2><span className="db-head-icon"><StudioIcon name={icon}/></span>{title}</h2>{extra}</header>
  {loading?<Skeleton rows={skeleton}/>:error?<div className="db-error" role="status"><StudioIcon name="alert"/><span>{t('couldntLoad')}</span><button type="button" onClick={onRetry}>{t('retry')}</button></div>:children}
 </section>;
}
function Skeleton({rows}:{rows:number}){return <div className="db-skeleton" aria-busy="true">{Array.from({length:rows},(_,index)=><span key={index}/>)}</div>;}
function ViewAll({href,label}:{href:string;label?:string}){const {t}=useDashboardText();return <Link href={href} className="db-view-all">{label??t('viewAll')}<StudioIcon name="chevron"/></Link>;}
function Poster({src,label}:{src:string|null;label:string}){
 const [failed,setFailed]=useState(false);
 return <span className="db-poster" aria-hidden={!label}>{src&&!failed?<img src={src} alt="" loading="lazy" onError={()=>setFailed(true)}/>:<StudioIcon name="play"/>}</span>;
}
function Bar({metric,danger}:{metric:DashboardMetric;danger?:boolean}){
 if(!metric.total)return null;
 const used=Math.min(100,metric.used/metric.total*100),reserved=Math.min(100-used,metric.reserved/metric.total*100);
 const over=metric.exceeded>0;
 return <span className={'db-bar'+(over?' is-over':danger?' is-warning':'')} aria-hidden="true"><i style={{width:(over?100:used)+'%'}}/>{!over&&reserved>0&&<b style={{width:reserved+'%'}}/>}</span>;
}
function useTimezone(){const {app}=usePage<SharedData>().props;return app.timezone??'UTC';}

/* ───────────── Greeting ───────────── */

function Greeting({plan}:{plan:DashboardPlan|null}){
 const {t,language}=useDashboardText();const {auth,frameLinks}=usePage<SharedData>().props;const zone=useTimezone();const date=useDisplayDate();
 const [now,setNow]=useState(()=>new Date());
 useEffect(()=>{const timer=setInterval(()=>setNow(new Date()),60000);return()=>clearInterval(timer);},[]);
 const hour=Number(new Intl.DateTimeFormat('en-US',{hour:'numeric',hourCycle:'h23',timeZone:zone}).format(now));
 const greeting=t(hour>=5&&hour<12?'morning':hour>=12&&hour<17?'afternoon':'evening');
 const weekday=new Intl.DateTimeFormat(language,{weekday:'long',timeZone:zone}).format(now);
 const name=(auth.user?.name??'').trim().split(/\s+/)[0];
 const ended=plan&&['expired','ended','cancelled'].includes(plan.status);
 return <header className="db-greeting">
  <div><h1>{greeting}{name?', '+name:''} <span aria-hidden="true">👋</span></h1><p>{t('subtitle')}</p><time dateTime={now.toISOString()}><StudioIcon name="calendar"/>{weekday}, {date(now.toISOString(),false)}</time></div>
  <div className="db-greeting-actions">
   {ended&&<span className="db-chip is-amber">{t('p_expired')}</span>}
   <Link href={frameLinks.newProject!} className="db-button primary"><StudioIcon name="plus"/>{t('newProject')}</Link>
   <Link href={frameLinks.allProjects!} className="db-button outline">{t('allProjects')}</Link>
  </div>
 </header>;
}

/* ───────────── Action needed ───────────── */

const actionIcon:Record<DashboardAction['kind'],IconName>={plan_expired:'crown',no_plan:'crown',payment_info:'credit-card',export_failed:'alert',scripts_ready:'file',plan_expiring:'crown',processing_over:'clock',processing_low:'clock',storage_full:'layers'};
const actionButton:Record<DashboardAction['kind'],DashboardWord>={plan_expired:'renew',no_plan:'choosePlan',payment_info:'provideInfo',export_failed:'retry',scripts_ready:'review',plan_expiring:'renew',processing_over:'upgrade',processing_low:'upgrade',storage_full:'manageFiles'};

function ActionStrip({items}:{items:DashboardAction[]}){
 const {t,minutes}=useDashboardText();const date=useDisplayDate();
 const [dismissed,setDismissed]=useState<string[]>(()=>{try{return JSON.parse(sessionStorage.getItem(dismissedKey)??'[]') as string[];}catch{return [];}});
 const [expanded,setExpanded]=useState(false);
 const visible=items.filter(item=>!dismissed.includes(item.key));
 if(!visible.length)return null;
 const hide=(key:string)=>{const next=[...dismissed,key];setDismissed(next);try{sessionStorage.setItem(dismissedKey,JSON.stringify(next));}catch{/* optional */}};
 const text=(item:DashboardAction)=>{
  const d=item.data,count=Number(d.count??0);
  const values={package:String(d.package??''),project:String(d.project??''),code:String(d.code??''),count,days:Number(d.days??0),percent:Number(d.percent??0),minutes:minutes(Number(d.seconds??0)),date:d.date?date(String(d.date),false):''};
  if(item.kind==='export_failed'&&count>1)return t('a_export_failed_many',values);
  if(item.kind==='scripts_ready'&&count>1)return t('a_scripts_ready_many',values);
  if(item.kind==='plan_expiring'&&values.days<=0)return t('a_plan_expiring_today',values);
  return t(('a_'+item.kind) as DashboardWord,values);
 };
 const shown=expanded?visible:visible.slice(0,3);
 return <section className="db-card db-actions" aria-label={t('actionNeeded')}>
  <h2><StudioIcon name="bolt"/>{t('actionNeeded')}</h2>
  <div className="db-action-list">
   {shown.map(item=><div key={item.key} className={'db-action is-'+item.tone}>
    <span className="db-action-icon"><StudioIcon name={actionIcon[item.kind]}/></span>
    <p title={text(item)}>{text(item)}</p>
    <Link href={item.url} className="db-action-button">{t(actionButton[item.kind])}</Link>
    <button type="button" className="db-action-dismiss" aria-label={t('dismiss')} onClick={()=>hide(item.key)}><StudioIcon name="close"/></button>
   </div>)}
   {visible.length>3&&<button type="button" className="db-action-more" onClick={()=>setExpanded(!expanded)}>{expanded?t('showLess'):t('more',{n:visible.length-3})}</button>}
  </div>
 </section>;
}

/* ───────────── KPI row ───────────── */

function KpiRow({plan,work,error,loading,onRetry}:{plan:DashboardPlan|null;work:DashboardWork|null;error:boolean;loading:boolean;onRetry:()=>void}){
 const {t,number,minutes}=useDashboardText();const {frameLinks}=usePage<SharedData>().props;const date=useDisplayDate();
 if(loading||error||!plan)return <div className="db-kpis">{[0,1,2,3].map(index=><div key={index} className="db-card db-kpi is-placeholder">{error&&index===0?<div className="db-error"><StudioIcon name="alert"/><span>{t('couldntLoad')}</span><button type="button" onClick={onRetry}>{t('retry')}</button></div>:<Skeleton rows={2}/>}</div>)}</div>;
 const noPlan=!plan.package&&!plan.testing;
 const p=plan.processing,v=plan.videos,s=plan.storage;
 const storageWarn=!!s.total&&(s.used+s.reserved)/s.total>=.9;
 const progress=work?.progress;
 const breakdown=progress?[progress.rendering&&progress.rendering+' '+t('rendering'),progress.writing&&progress.writing+' '+t('writing'),progress.queued&&progress.queued+' '+t('queuedLower'),progress.stopping&&progress.stopping+' '+t('stoppingLower')].filter(Boolean).join(' • '):'';
 const processingNote=noPlan?t('noPlan'):p.exceeded>0?<em className="is-red">{t('overBy',{value:minutes(p.exceeded)})}</em>:p.total===null?t('unlimited'):[p.reserved>0&&t('reservedLine',{value:minutes(p.reserved)}),p.available!==null&&t('leftLine',{value:minutes(p.available)})].filter(Boolean).join(' · ');
 return <div className="db-kpis">
  <Link href={frameLinks.myPlan!} className="db-card db-kpi">
   <span className="db-kpi-icon"><StudioIcon name="clock"/></span>
   <div><small>{t('processing')}</small><strong>{noPlan?'—':<>{number(Math.round(p.used/60))} <i>{t('minutesShort')}</i>{p.total!==null&&<span> / {minutes(p.total)}</span>}</>}</strong>
    {!noPlan&&<Bar metric={p}/>}<p>{processingNote}</p></div>
  </Link>
  <Link href={frameLinks.myPlan!} className="db-card db-kpi">
   <span className="db-kpi-icon is-mint"><StudioIcon name="triangle-play"/></span>
   <div><small>{t('videosPeriod')}</small><strong>{noPlan?'—':<>{number(v.used)}{v.total!==null&&<span> / {number(v.total)}</span>}</>}</strong>
    {!noPlan&&<Bar metric={v}/>}<p>{noPlan?t('noPlan'):v.exceeded>0?<em className="is-red">{t('overBy',{value:number(v.exceeded)})}</em>:v.total===null?t('unlimited'):plan.status==='active'&&plan.end?t('resetsOn',{date:date(plan.end,false)}):''}</p></div>
  </Link>
  <Link href={frameLinks.allProjects!} className="db-card db-kpi">
   <span className="db-kpi-icon"><StudioIcon name="layers"/></span>
   <div><small>{t('storage')}</small><strong>{formatBytes(s.used).replace(/ (\w+)$/,' $1')}{s.total!==null&&<span> / {formatBytes(s.total)}</span>}</strong>
    <Bar metric={s} danger={storageWarn}/><p>{s.total===null?t('unlimited'):storageWarn?<em className="is-amber">{Math.min(100,Math.floor((s.used+s.reserved)/s.total*100))}%</em>:''}</p></div>
  </Link>
  <Link href={frameLinks.processing??frameLinks.allProjects!} className="db-card db-kpi">
   <span className="db-kpi-icon is-wave"><StudioIcon name="activity"/></span>
   <div><small>{t('inProgress')}</small><strong>{number(progress?.total??0)}</strong><p>{progress?.total?breakdown:t('nothingRunning')}</p></div>
  </Link>
 </div>;
}

/* ───────────── Continue working ───────────── */

function useEta(){
 const seen=useRef(new Map<string,{progress:number;at:number}>());
 return (item:WorkItem)=>{
  if(item.state!=='running'||item.progress===null)return null;
  const first=seen.current.get(item.id),now=Date.now();
  if(!first||item.progress<first.progress){seen.current.set(item.id,{progress:item.progress,at:now});return null;}
  const moved=item.progress-first.progress,elapsed=(now-first.at)/1000;
  if(moved<2||elapsed<15)return null;
  return Math.max(1,Math.round(elapsed/moved*(100-item.progress)/60));
 };
}

function ContinueWorking({work,error,loading,onRetry,busy,onChanged}:{work:DashboardWork|null;error:boolean;loading:boolean;onRetry:()=>void;busy:{current:boolean};onChanged:()=>void}){
 const {t,number}=useDashboardText();const {error:failure}=useMediaText();const {frameLinks}=usePage<SharedData>().props;const toast=useToast();const eta=useEta();
 const [confirm,setConfirm]=useState<WorkItem|null>(null),[acting,setActing]=useState<string|null>(null);
 async function act(item:WorkItem,url:string,kind:'cancel'|'retry'){
  setActing(item.id);busy.current=true;
  try{await mediaApi(url,'POST');toast.success(t(kind==='cancel'?'cancelled':'retryStarted'));setConfirm(null);}
  catch{toast.error(t('actionFailed'));}
  finally{busy.current=false;setActing(null);onChanged();}
 }
 const stateChip=(item:WorkItem)=>{
  if(item.state==='running')return <span className="db-chip is-purple">{t(item.stage==='rendering'?'s_rendering':item.stage==='stopping'?'s_stopping':'s_writing')}</span>;
  if(item.state==='review')return <span className="db-chip is-mint">{t('s_review')}</span>;
  if(item.state==='failed')return <span className="db-chip is-red">{t('s_failed')}</span>;
  if(item.state==='queued'||item.state==='retrying')return <span className="db-chip is-amber">{t(item.state==='queued'?'s_queued':'s_retrying')}</span>;
  if(item.state==='preparing')return <span className="db-chip">{t(item.stage==='importing'?'s_importing':item.stage==='uploading'?'s_uploading':'s_validating')}</span>;
  return <span className="db-chip">{t('s_draft')}</span>;
 };
 return <Card className="db-work" icon="triangle-play" title={t('continueWorking')} extra={work&&work.total>4?<ViewAll href={frameLinks.allProjects!}/>:undefined} error={error} loading={loading||!work&&!error} onRetry={onRetry} skeleton={2}>
  {work&&(!work.items.length?<div className="db-empty"><StudioIcon name="check"/><strong>{t('nothingInProgress')}</strong><Link href={frameLinks.newProject!}>{t('startNew')}</Link></div>
  :<ul className="db-work-list">{work.items.map(item=>{
   const minutesLeft=eta(item);
   const actions:LibraryAction[]=[{label:t('openStudio'),icon:'arrow',href:item.url}];
   if(item.cancel)actions.push({label:t('cancelJob'),icon:'stop',action:()=>setConfirm(item),danger:true});
   if(item.retry)actions.push({label:t('retry'),icon:'refresh',action:()=>void act(item,item.retry!,'retry')});
   return <li key={item.id} className={'db-work-item is-'+item.state}>
    <Link href={item.url} className="db-work-thumb" tabIndex={-1} aria-hidden="true"><Poster src={item.poster} label=""/></Link>
    <div className="db-work-identity"><div><Link href={item.url} className="db-title" title={item.title}>{item.title}</Link>{stateChip(item)}</div>
     <small>{t(typeWord[item.type])}{item.duration>0&&<> • {number(Math.max(1,Math.round(item.duration/60)))} {t('minutesShort')}</>}{item.state==='failed'&&item.error&&<em title={failure(item.error)}> • {failure(item.error)}</em>}</small></div>
    <div className="db-work-status">
     {item.progress!==null?<><span className="db-progress" role="progressbar" aria-valuenow={item.progress} aria-valuemin={0} aria-valuemax={100} aria-label={item.title}><i style={{width:item.progress+'%'}}/></span><span className="db-percent"><b>{item.progress}%</b>{minutesLeft!==null&&<small>{t('minLeft',{n:minutesLeft})}</small>}</span></>
     :item.state==='review'?<Link href={item.url} className="db-button primary small">{t('reviewScript')}</Link>
     :item.state==='failed'&&item.retry?<button type="button" className="db-button primary small" disabled={acting===item.id} onClick={()=>void act(item,item.retry!,'retry')}>{t('retry')}</button>
     :<Link href={item.url} className="db-button outline small">{t(item.state==='draft'?'continue':'open')}</Link>}
    </div>
    <div className="db-work-tools">
     {item.cancel&&<ActionTooltip label={t('cancelJob')}><button type="button" className="db-round" aria-label={t('cancelJob')+': '+item.title} disabled={acting===item.id} onClick={()=>setConfirm(item)}><StudioIcon name="stop"/></button></ActionTooltip>}
     <LibraryMenu title={item.title} actions={actions} label={t('more_actions')}/>
    </div>
   </li>;})}</ul>)}
  <ConfirmDialog open={!!confirm} message={t('cancelTitle')+' '+t('cancelBody')} busy={!!confirm&&acting===confirm.id} onClose={()=>setConfirm(null)} onConfirm={()=>{if(confirm?.cancel)void act(confirm,confirm.cancel,'cancel');}}/>
 </Card>;
}

/* ───────────── Getting started ───────────── */

function Checklist({checklist,link,onHidden}:{checklist:{steps:Record<ChecklistStep,boolean>};link:string;onHidden:()=>void}){
 const {t}=useDashboardText();const {frameLinks}=usePage<SharedData>().props;const toast=useToast();
 const [hiding,setHiding]=useState(false);
 const steps:{key:ChecklistStep;word:DashboardWord;href?:string;optional?:boolean}[]=[
  {key:'account',word:'c_account'},
  {key:'upload',word:'c_upload',href:frameLinks.newProject},
  {key:'review',word:'c_review',href:frameLinks.awaitingReview??frameLinks.allProjects},
  {key:'export',word:'c_export',href:frameLinks.allProjects},
  {key:'defaults',word:'c_defaults',href:frameLinks.preferences,optional:true},
 ];
 const done=steps.filter(step=>checklist.steps[step.key]).length;
 const next=steps.find(step=>!checklist.steps[step.key]&&!step.optional)?.key;
 async function hide(){setHiding(true);try{await mediaApi(link,'POST');onHidden();}catch{toast.error(t('actionFailed'));setHiding(false);}}
 return <Card className="db-checklist" icon="sparkles" title={t('gettingStarted')} extra={<button type="button" className="db-link" disabled={hiding} onClick={()=>void hide()}>{t('hide')}</button>}>
  <div className="db-checklist-progress"><span>{t('checklistProgress',{n:done})}</span><span className="db-bar"><i style={{width:done/5*100+'%'}}/></span></div>
  <ol>{steps.map(step=>{const complete=checklist.steps[step.key];return <li key={step.key} className={(complete?'is-done':'')+(next===step.key?' is-next':'')}>
   <span className="db-step-mark">{complete?<StudioIcon name="check"/>:null}</span>
   <span>{t(step.word)}{step.optional&&<small>{t('optional')}</small>}</span>
   {!complete&&step.href&&<Link href={step.href} className="db-view-all">{t('start')}<StudioIcon name="chevron"/></Link>}
  </li>;})}</ol>
 </Card>;
}

/* ───────────── Recent projects ───────────── */

function RecentProjects({items,error,loading,onRetry}:{items:RecentProject[]|null;error:boolean;loading:boolean;onRetry:()=>void}){
 const {t,number}=useDashboardText();const {frameLinks}=usePage<SharedData>().props;const date=useDisplayDate();
 return <Card className="db-recent" icon="clock" title={t('recentProjects')} extra={<ViewAll href={frameLinks.completed??frameLinks.allProjects!}/>} error={error} loading={loading||!items&&!error} onRetry={onRetry}>
  {items&&(!items.length?<div className="db-empty"><StudioIcon name="folder-play"/><strong>{t('noRecent')}</strong></div>:
  <div className="db-recent-grid">{items.map(item=>{
   const actions:LibraryAction[]=[{label:t('watch'),icon:'play',href:item.url}];
   if(item.video)actions.push({label:t('downloadMp4'),icon:'download',href:item.video});
   if(item.subtitles)actions.push({label:t('downloadSrt'),icon:'file',href:item.subtitles});
   if(item.audio)actions.push({label:t('downloadAudio',{format:item.audio_format??'Audio'}),icon:'download',href:item.audio});
   return <article key={item.id} className="db-recent-item">
    <Link href={item.url} className="db-recent-thumb" aria-label={t('watch')+': '+item.title}><Poster src={item.poster} label=""/><span className="db-play"><StudioIcon name="triangle-play"/></span></Link>
    <div className="db-recent-text"><Link href={item.url} className="db-title" title={item.title}>{item.title}</Link>
     <small>{t(typeWord[item.type])}{item.duration>0&&<> • {number(Math.max(1,Math.round(item.duration/60)))} {t('minutesShort')}</>}</small>
     <small className="db-done"><StudioIcon name="check"/>{t('completed')} • {date(item.completed_at,false)}</small></div>
    <LibraryMenu title={item.title} actions={actions} label={t('more_actions')}/>
   </article>;})}</div>)}
 </Card>;
}

/* ───────────── Usage chart ───────────── */

/** Chart top with four even, round steps (e.g. 0·15·30·45·60). */
function niceMax(value:number){if(value<=0)return 4;const raw=value/4,power=Math.pow(10,Math.floor(Math.log10(raw)));const step=([1,1.5,2,2.5,3,4,5,6,8,10].find(item=>item*power>=raw)??10)*power;return step*4;}

function UsageChart({initial,plan,link,error,loading,onRetry}:{initial:DashboardUsage|null;plan:DashboardPlan|null;link:string;error:boolean;loading:boolean;onRetry:()=>void}){
 const {t,number,minutes,language}=useDashboardText();
 const [range,setRange]=useState<UsageRange>('14'),[usage,setUsage]=useState(initial),[fetching,setFetching]=useState(false),[failed,setFailed]=useState(false),[hover,setHover]=useState<number|null>(null);
 const request=useRef(0);
 useEffect(()=>{if(initial&&range===initial.range)setUsage(initial);},[initial]);
 async function choose(next:UsageRange){
  setRange(next);setHover(null);const id=++request.current;setFetching(true);setFailed(false);
  try{const result=await mediaApi<DashboardUsage>(link+'?range='+next);if(id===request.current)setUsage(result);}
  catch{if(id===request.current)setFailed(true);}
  finally{if(id===request.current)setFetching(false);}
 }
 const bars=usage?.bars??[];
 const top=niceMax(Math.max(...bars.map(bar=>bar.seconds/60),0));
 const label=(value:string)=>{const day=new Date(value+(value.length===7?'-01':'')+'T00:00:00Z');return new Intl.DateTimeFormat(language,usage?.unit==='month'?{month:'short',year:'2-digit',timeZone:'UTC'}:{month:'short',day:'numeric',timeZone:'UTC'}).format(day);};
 const step=bars.length>16?3:1;
 const p=plan?.processing;
 const segments:{key:UsageRange;word:DashboardWord}[]=[{key:'14',word:'days14'},{key:'30',word:'days30'},{key:'all',word:'allTime'}];
 return <Card className="db-usage" icon="bar-chart" title={<>{t('processingUsed')}{usage&&usage.total_seconds>0&&<small className="db-usage-total">{t('usageTotal',{minutes:minutes(usage.total_seconds),videos:number(usage.videos)})}</small>}</>}
  extra={<div className="db-segments" role="tablist">{segments.map(segment=><button key={segment.key} type="button" role="tab" aria-selected={range===segment.key} onClick={()=>void choose(segment.key)}>{t(segment.word)}</button>)}</div>}
  error={error||failed} loading={loading||fetching} onRetry={failed?()=>void choose(range):onRetry} skeleton={4}>
  {usage&&<div className="db-usage-body">
   {usage.total_seconds<=0?<div className="db-empty db-usage-empty"><StudioIcon name="bar-chart"/><strong>{t('noUsage')}</strong></div>:
   <div className="db-chart" onMouseLeave={()=>setHover(null)}>
    <div className="db-axis" aria-hidden="true">{[4,3,2,1,0].map(index=><span key={index}>{number(top/4*index,1)}</span>)}</div>
    <div className="db-plot">
     <div className="db-gridlines" aria-hidden="true">{[0,1,2,3,4].map(index=><i key={index}/>)}</div>
     <div className="db-bars" style={{gridTemplateColumns:`repeat(${bars.length},minmax(0,1fr))`}}>
      {bars.map((bar,index)=>{const height=bar.seconds/60/top*100;return <div key={bar.date} className={'db-bar-col'+(hover===index?' is-hover':'')+(index%step||bars.length>7&&index%2?' is-minor':'')}>
       <button type="button" className="db-bar-hit" aria-label={label(bar.date)+': '+t('usageTip',{minutes:minutes(bar.seconds),videos:number(bar.videos)})} onMouseEnter={()=>setHover(index)} onFocus={()=>setHover(index)} onBlur={()=>setHover(null)}>
        <span className="db-bar-fill" style={{height:bar.seconds>0?Math.max(3,height)+'%':'2px'}} data-empty={bar.seconds<=0||undefined}/>
       </button>
       {hover===index&&<span className={'db-tip'+(index>bars.length*.7?' is-left':index<bars.length*.3?' is-right':'')} role="tooltip"><b>{label(bar.date)}</b>{t('usageTip',{minutes:minutes(bar.seconds),videos:number(bar.videos)})}</span>}
       <small className={index%step?'is-hidden':''}>{label(bar.date)}</small>
      </div>;})}
     </div>
    </div>
   </div>}
   {p&&<dl className="db-legend"><div><dt><i className="is-used"/>{t('used')}</dt><dd>{minutes(p.used)}</dd></div><div><dt><i className="is-reserved"/>{t('reserved')}</dt><dd>{minutes(p.reserved)}</dd></div><div><dt><i className="is-remaining"/>{t('remaining')}</dt><dd>{p.total===null?(plan?.package||plan?.testing?t('unlimited'):'—'):minutes(p.available??0)}</dd></div><p>{t('thisPeriod')}</p></dl>}
  </div>}
 </Card>;
}

/* ───────────── Plan ───────────── */

function PlanCard({plan,error,loading,onRetry}:{plan:DashboardPlan|null;error:boolean;loading:boolean;onRetry:()=>void}){
 const {t,number,minutes}=useDashboardText();const {frameLinks}=usePage<SharedData>().props;const date=useDisplayDate();
 const billing=frameLinks.billing!;
 if(!plan||loading||error)return <Card className="db-plan" icon="crown" title={t('myPlan')} error={error} loading={loading||!error} onRetry={onRetry}/>;
 const ended=['expired','ended','cancelled','replaced'].includes(plan.status);
 const status=plan.status==='active'?(plan.expiring?'p_expiring':'p_active'):plan.status==='upcoming'?'p_upcoming':plan.package?'p_expired':'p_none';
 const tone=plan.status==='active'?(plan.expiring?'is-amber':'is-mint'):plan.status==='upcoming'?'is-purple':'is-red';
 const trial=plan.package?.type==='trial';
 const chips:{icon:IconName;label:string}[]=plan.package?[
  {icon:'triangle-play',label:plan.videos.total===null?t('unlimitedVideos'):t('videosChip',{n:number(plan.videos.total)})},
  {icon:'layers',label:plan.processing.total===null?t('unlimitedMinutes'):minutes(plan.processing.total)},
  {icon:'database',label:plan.storage.total===null?t('unlimited'):formatBytes(plan.storage.total)},
 ]:[];
 return <section className="db-card db-plan">
  <header className="db-card-head"><h2><span className="db-head-icon is-crown"><StudioIcon name="crown"/></span>{t('myPlan')}</h2>{plan.testing&&<span className="db-chip">{t('testing')}</span>}</header>
  {plan.package?<>
   <div className="db-plan-name"><strong>{plan.package.name}</strong>{trial&&<span className="db-chip is-mint">{t('freeTrial')}</span>}<span className={'db-chip '+tone}>{t(status)}</span></div>
   {plan.start&&plan.end&&<p className="db-plan-period"><StudioIcon name="calendar"/>{date(plan.start,false)} – {date(plan.end,false)}<span>·</span>{plan.status==='active'?(plan.days_left>0?t('daysLeft',{n:plan.days_left}):t('dayLeft')):ended?t('endedOn',{date:date(plan.end,false)}):''}</p>}
   {plan.next&&<p className="db-plan-note is-mint"><StudioIcon name="refresh"/>{t('nextPlan',{name:plan.next.package.name,date:date(plan.next.start,false)})}</p>}
   {plan.pending_payment&&<p className="db-plan-note is-amber"><StudioIcon name="hourglass"/>{t('pendingPayment')}</p>}
   <div className="db-plan-chips">{chips.map(chip=><span key={chip.icon}><StudioIcon name={chip.icon}/>{chip.label}</span>)}</div>
   {trial&&<Link href={billing} className="db-plan-trial">{t('trialHint')}<StudioIcon name="chevron"/></Link>}
   <div className="db-plan-actions"><Link href={frameLinks.myPlan!} className="db-button outline">{t('viewMyPlan')}</Link><Link href={billing} className="db-button primary">{t(ended||plan.expiring?'renew':'upgrade')}</Link></div>
  </>:<>
   <div className="db-plan-name"><strong>{plan.testing?t('testing'):t('noPlan')}</strong></div>
   <p className="db-plan-period">{t('noPlanHint')}</p>
   <div className="db-plan-actions"><Link href={frameLinks.myPlan!} className="db-button outline">{t('viewMyPlan')}</Link><Link href={billing} className="db-button primary">{t('choosePlan')}</Link></div>
  </>}
 </section>;
}

/* ───────────── Quick start ───────────── */

function QuickStart({plan}:{plan:DashboardPlan|null}){
 const {t}=useDashboardText();const {frameLinks}=usePage<SharedData>().props;
 const tiles:{key:DashboardWorkflow;icon:ReactNode}[]=[
  {key:'movie',icon:<StudioIcon name="triangle-play"/>},
  {key:'translate',icon:<StudioIcon name="translate"/>},
  {key:'subtitles',icon:<b>Aa</b>},
 ];
 const base=frameLinks.newProject!;
 return <section className="db-card db-quick">
  <header className="db-card-head"><h2><span className="db-head-icon"><StudioIcon name="bolt"/></span>{t('quickStart')}</h2></header>
  <div className="db-quick-grid">{tiles.map(tile=>{
   const locked=plan?.workflows[tile.key]===false;
   const href=locked?frameLinks.billing!:base+'?workflow='+tile.key+(plan?.preset?'&preset='+plan.preset.id:'');
   return <ActionTooltip key={tile.key} label={locked?t('notIncluded'):t(('qd_'+tile.key) as DashboardWord)}><Link href={href} className={'db-quick-tile is-'+tile.key+(locked?' is-locked':'')} aria-label={t(('q_'+tile.key) as DashboardWord)+(locked?' · '+t('notIncluded'):'')}>
    <span className="db-quick-icon">{tile.icon}</span><span>{t(('q_'+tile.key) as DashboardWord)}</span>{locked&&<i className="db-quick-lock"><StudioIcon name="lock"/></i>}
   </Link></ActionTooltip>;})}</div>
  {plan?.preset&&<p className="db-quick-preset"><StudioIcon name="sliders"/>{t('usingPreset',{name:plan.preset.name})}</p>}
 </section>;
}

/* ───────────── Notifications ───────────── */

function RecentNotifications({initial,error,loading,onRetry}:{initial:NotificationFeed|null;error:boolean;loading:boolean;onRetry:()=>void}){
 const {t}=useDashboardText();const {describe,t:noteText}=useNotificationText();const {customerNotifications}=usePage<SharedData>().props;const date=useDisplayDate();const relative=useRelativeTime();
 const [feed,setFeed]=useState(initial);
 useEffect(()=>{if(initial)setFeed(initial);},[initial]);
 useNotificationChanges(next=>{if(next)setFeed(next);else if(customerNotifications)void mediaApi<NotificationFeed>(customerNotifications.feed).then(setFeed).catch(()=>{});});
 async function open(item:CustomerNotification){
  if(!item.read&&customerNotifications){try{const result=await mediaApi<NotificationFeed>(customerNotifications.action,'POST',{action:'read',ids:[item.id]});setFeed(result);announceNotificationChange(result);}catch{/* still navigate */}}
  if(item.url)router.visit(item.url);
 }
 const items=feed?.all.slice(0,5)??[];
 return <Card className="db-notes" icon="bell" title={<>{t('recentNotifications')}{!!feed?.unread&&<span className="db-count">{feed.unread}</span>}</>} extra={customerNotifications?<ViewAll href={customerNotifications.index}/>:undefined} error={error&&!feed} loading={loading||!feed&&!error} onRetry={onRetry} skeleton={4}>
  {feed&&(!items.length?<div className="db-empty db-caught-up"><span className="db-bell-art"><StudioIcon name="bell"/></span><strong>{t('caughtUp')}</strong></div>:
  <ul className="db-note-list">{items.map(item=>{const text=describe(item,value=>date(value,false));const visual=notificationVisual(item.type);return <li key={item.id} className={item.read?'':'is-unread'}>
   <button type="button" onClick={()=>void open(item)} disabled={!item.url&&item.read}>
    <span className={'db-note-icon is-'+visual.tone}><StudioIcon name={visual.icon}/></span>
    <span className="db-note-text"><b>{text.title}:</b> {item.removed?noteText('removedProject'):text.body}</span>
    <time dateTime={item.at} title={relative(item.at,true)}>{relative(item.at)}</time><i className="db-unread" aria-hidden="true"/>
   </button></li>;})}</ul>)}
 </Card>;
}

/* ───────────── Billing ───────────── */

function money(row:BillingRow,language:string){return row.currency+' '+new Intl.NumberFormat(language,{maximumFractionDigits:2}).format(row.amount_minor/100);}

function BillingSnapshot({billing,error,loading,onRetry}:{billing:DashboardBilling|null;error:boolean;loading:boolean;onRetry:()=>void}){
 const {t,language}=useDashboardText();const {frameLinks}=usePage<SharedData>().props;const date=useDisplayDate();
 const url=frameLinks.billing!;
 const statusClass=(status:string)=>status==='approved'?'is-mint':status==='needs_information'?'is-red':'is-amber';
 return <Card className="db-billing" icon="credit-card" title={t('billingSnapshot')} extra={<ViewAll href={url} label={t('viewPayments')}/>} error={error} loading={loading||!billing&&!error} onRetry={onRetry} skeleton={2}>
  {billing&&(!billing.last&&!billing.pending?<div className="db-empty"><StudioIcon name="wallet"/><strong>{t('noPayments')}</strong><Link href={url}>{t('makePayment')}</Link></div>:
  <dl className="db-billing-rows">
   {billing.last&&<Link href={url+'?payment='+billing.last.id} className="db-billing-row"><dt><span className="db-billing-icon is-mint"><StudioIcon name="check"/></span>{t('lastPayment')}</dt><dd>{money(billing.last,language)}</dd><dd className="is-mint">{t('b_approved')}</dd><dd className="db-billing-date">{date(billing.last.at,false)}</dd></Link>}
   {billing.pending&&<Link href={url+'?payment='+billing.pending.id} className="db-billing-row"><dt><span className={'db-billing-icon '+statusClass(billing.pending.status)}><StudioIcon name="alert"/></span>{t('pending')}</dt><dd>{billing.pending.code}</dd><dd className={statusClass(billing.pending.status)}>{t(('b_'+billing.pending.status) as DashboardWord)}</dd><dd className="db-billing-date">{date(billing.pending.at,false)}</dd></Link>}
  </dl>)}
 </Card>;
}

/* ───────────── Help & support ───────────── */

function SupportCard(){
 const {t}=useDashboardText();const {supportFeed}=usePage<SharedData>().props;
 const [unread,setUnread]=useState(0);
 useEffect(()=>{const update=(event:Event)=>setUnread(Number((event as CustomEvent<number>).detail)||0);window.addEventListener('support-unread',update);return()=>window.removeEventListener('support-unread',update);},[]);
 const contact=useMemo(()=>()=>window.dispatchEvent(new Event('open-support-chat')),[]);
 if(!supportFeed)return null;
 return <section className="db-card db-support">
  <header className="db-card-head"><h2><span className="db-head-icon"><StudioIcon name="headphones"/></span>{t('helpSupport')}</h2>{unread>0&&<span className="db-count">{t('newReplies',{n:unread})}</span>}</header>
  <div className="db-support-body"><p>{t('helpText')}</p><button type="button" className="db-button outline" onClick={contact}>{t('contactSupport')}</button></div>
 </section>;
}
