import {Head, Link, router, usePage} from '@inertiajs/react';
import {useEffect, useRef, useState} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon, type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {ClearFiltersButton} from '@/components/clear-filters-button';
import {MediaDialog, SelectField} from '@/components/media-workflow';
import {PageBoundaryButton} from '@/components/page-boundary-button';
import {formatBytes, mediaApi} from '@/lib/media-api';
import {useDisplayDate, formatPlatformDate} from '@/lib/platform-display';
import {usePlanText, type PlanWord} from '@/lib/plan-i18n';
import {useToast} from '@/lib/toast';
import type {SharedData} from '@/types';
import type {PlanProps, PlanMetric, PlanDetail, PlanExport} from '@/types/plan';
import '../../../css/my-plan.css';

const groups:{label:PlanWord;icon:IconName;items:PlanWord[]}[]=[
 {label:'sources',icon:'upload',items:['local','youtube']},
 {label:'creation',icon:'folder-play',items:['movie','translate','subs']},
 {label:'export',icon:'download',items:['mp4','srt','ass','audio']},
 {label:'editing',icon:'edit',items:['script_edit','voice_preview','video_preview','subtitle_style']},
 {label:'videoAudio',icon:'sliders',items:['layout','cover_subtitles','keep_background']},
];
export default function MyPlan({overview:o,exports,filters,availablePlans}:PlanProps){
 const t=usePlanText(),date=useDisplayDate(),toast=useToast();
 const {frameLinks,app,locale}=usePage<SharedData>().props;
 const [tab,setTab]=useState('features'),[query,setQuery]=useState(filters.q),[busy,setBusy]=useState(false),[failed,setFailed]=useState(false);
 const [plans,setPlans]=useState(false),[selected,setSelected]=useState<PlanExport|null>(null),[detail,setDetail]=useState<PlanDetail|null>(null),[detailError,setDetailError]=useState(false),[attempt,setAttempt]=useState(0);
 const pending=useRef(false);
 const hasFilters=!!query.trim()||!!filters.q||!!filters.type||filters.period!=='current';
 function clearFilters(){setQuery('');navigate({q:'',type:'',period:'current'});}
 const url=frameLinks.myPlan??'/account/plan',library=frameLinks.allProjects??'/projects';
 const number=(n:number)=>new Intl.NumberFormat(locale,{maximumFractionDigits:2}).format(n);
 const minutes=(seconds:number)=>number(seconds/60)+' '+t('minutes');
 const processingDuration=(seconds:number)=>{const whole=Math.max(0,Math.round(seconds));return number(Math.floor(whole/60))+' '+t('minutes')+' '+number(whole%60)+' '+t('seconds');};
 const completedDate=(value:string)=>formatPlatformDate(value,locale,app,false)+', '+new Intl.DateTimeFormat('en-US',{timeZone:app.timezone??'UTC',hour:'numeric',minute:'2-digit',hour12:true}).format(new Date(value));
 const fmt=(metric: 'processing'|'videos'|'storage',n:number)=>metric==='processing'?minutes(n):metric==='storage'?formatBytes(n):number(n);
 function navigate(change:Partial<typeof filters>&{page?:number}={}){
  if(pending.current)return;
  router.get(url,{...filters,...change,page:change.page??1},{preserveState:true,preserveScroll:true,replace:true,
   onStart:()=>{pending.current=true;setBusy(true);setFailed(false);},
   onError:()=>{setFailed(true);toast.error(t('failure'));},
   onHttpException:()=>{setFailed(true);toast.error(t('failure'));return false;},
   onFinish:()=>{pending.current=false;setBusy(false);}});
 }
 function refresh(){
  if(pending.current)return;
  router.reload({only:['overview','exports','availablePlans'],onStart:()=>{pending.current=true;setBusy(true);setFailed(false);},
   onError:()=>{setFailed(true);toast.error(t('failure'));},onFinish:()=>{pending.current=false;setBusy(false);}});
 }
 useEffect(()=>{setQuery(filters.q);},[filters.q]);
 useEffect(()=>{if(query.trim()===filters.q)return;const timer=setTimeout(()=>navigate({q:query.trim()}),350);return()=>clearTimeout(timer);},[query,filters.q,filters.type,filters.period]);
 useEffect(()=>{
  if(!o.jobs.running&&!o.jobs.queued)return;
  const timer=setInterval(()=>{if(document.hidden||pending.current)return;
   router.reload({only:['overview','exports'],showProgress:false,
    onStart:()=>{pending.current=true;},onFinish:()=>{pending.current=false;}});},10000);
  return()=>clearInterval(timer);
 },[o.jobs.running,o.jobs.queued]);
 useEffect(()=>{
  if(!selected)return;
  const controller=new AbortController();setDetail(null);setDetailError(false);
  mediaApi<PlanDetail>(selected.detail_url,'GET',undefined,controller.signal).then(setDetail).catch(()=>{if(!controller.signal.aborted)setDetailError(true);});
  return()=>controller.abort();
 },[selected,attempt]);
 const status=(['active','upcoming','expired','ended','cancelled','replaced','suspended'].includes(o.status)?o.status:'none') as PlanWord;
 const state=o.expiring?'expiring':status;
 const support=app.support_url?.startsWith('https://')?app.support_url:app.support_email?'mailto:'+app.support_email:null;
 const feature=(key:PlanWord)=>!!o.features[key==='ass'?'srt':key==='movie'||key==='translate'?'dub':key];
 const pages=Array.from(new Set([1,exports.current_page-1,exports.current_page,exports.current_page+1,exports.last_page])).filter(p=>p>0&&p<=exports.last_page).sort((a,b)=>a-b);
 return <AppLayout frame="myPlan"><Head title={t('title')}/><div className="my-plan">
  <header className="plan-heading"><div className="plan-heading-title"><div><h1>{t('title')}</h1><p>{t('description')}</p></div></div>
   <ActionTooltip label={t('refresh')}><button className="plan-refresh" type="button" onClick={refresh} disabled={busy}><StudioIcon name="reset" className={busy?'is-spinning':''}/><span>{t('updated')} {date(o.updated_at)}</span></button></ActionTooltip>
  </header>
  <section className="plan-current" aria-label={t('current')}>
   <div className="plan-package"><div><div className="plan-package-name"><strong>{o.package?.name??t('none')}</strong><span className={'plan-state is-'+state}>· {t(state)}</span>{o.package&&<span className="plan-type">{t(o.package.type==='paid'?'paid':'trial')}</span>}</div>{o.package&&<small>{o.package.code} · v{o.package.version}</small>}</div></div>
   <div className="plan-period"><StudioIcon name="calendar"/><div><span>{t('current')}</span><strong>{o.start&&o.end?date(o.start)+' → '+date(o.end):'—'}</strong></div></div>
   {o.status==='active'&&<span className={'plan-days'+(o.expiring?' is-warning':'')}>{o.days_left} {t('days')}</span>}
   <div className="plan-current-actions"><button className="plan-button primary" onClick={()=>setPlans(true)}><StudioIcon name="crown"/>{t('billingTitle')}</button></div>
  </section>
  {!o.package&&<p className="plan-notice"><StudioIcon name="info"/>{t('noPlanHint')}</p>}
  {o.pending_payment&&<p className="plan-notice"><StudioIcon name="clock"/>{t('pending')}</p>}
  {o.next&&<p className="plan-notice"><StudioIcon name="calendar"/><strong>{t('next')}: {o.next.package.name}</strong><span>{date(o.next.start)} → {date(o.next.end)}</span></p>}
  <section className="plan-allowances" aria-label={t('available')}>
   {(['processing','videos','storage'] as const).map(key=>{const m=o[key],known=!!o.package;return <article key={key} className={'plan-allowance is-'+key}>
    <div className="plan-allowance-top"><div><h2><StudioIcon name={key==='processing'?'clock':key==='videos'?'folder-play':'layers'}/>{t(key)}</h2>
     <p className="plan-available"><strong>{!known?'—':m.available===null?t('unlimited'):fmt(key,m.available)+(key==='videos'?' '+t('videoUnit'):'')}</strong> {known&&<span>{t('available')}</span>}</p></div>
     <dl><div><dt>{t('total')}</dt><dd>{!known?'—':m.total===null?t('unlimited'):fmt(key,m.total)}</dd></div><div><dt>{t(key==='storage'?'inUse':'used')}</dt><dd>{key==='processing'?processingDuration(m.used):fmt(key,m.used)}</dd></div>{(key!=='storage'||m.reserved>0)&&<div><dt><ActionTooltip label={t(key==='storage'?'storageHint':'reservedHint')}><span tabIndex={0}>{t(key==='storage'?'storageReserved':'reserved')} <StudioIcon name="info"/></span></ActionTooltip></dt><dd>{fmt(key,m.reserved)}</dd></div>}</dl>
    </div><div className="plan-meter-line"><Meter metric={m}/>{key==='storage'&&<Link className="plan-link" href={library}>{t('manage')}<StudioIcon name="arrow"/></Link>}</div>
    {m.exceeded>0&&<small className="plan-over">{t('over')}: {fmt(key,m.exceeded)}</small>}
   </article>;})}
  </section>
  {o.testing&&<p className="plan-testing"><StudioIcon name="info"/>{t('testing')}</p>}
  <div className="plan-jobs"><div><StudioIcon name="activity"/><span>{t('running')} <strong>{o.jobs.running} / {o.jobs.concurrency??'—'}</strong></span><i>·</i><span>{t('queued')} <strong>{o.jobs.queued} / {o.jobs.queue_limit??'—'}</strong></span></div><ActionTooltip label={t('jobsHint')}><Link className="plan-link" href={library+'?status=processing'}>{t('jobs')}<StudioIcon name="arrow"/></Link></ActionTooltip></div>
  <div className="plan-tabs" role="tablist" aria-label={t('features')}>{(['features','limits'] as const).map(key=><button key={key} type="button" role="tab" id={'plan-tab-'+key} aria-controls="plan-tab-content" aria-selected={tab===key} onClick={()=>setTab(key)}>{t(key)}</button>)}</div>
  <section id="plan-tab-content" role="tabpanel" aria-labelledby={'plan-tab-'+tab}>
   {tab==='features'?<><div className="plan-feature-groups">{groups.map(group=><article key={group.label}><h3><StudioIcon name={group.icon}/>{t(group.label)}</h3><ul>{group.items.map(key=><li className={feature(key)?'':'not-included'} key={key}><StudioIcon name={feature(key)?'check':'close'}/><span>{t(key)}</span><span className="sr-only"> · {feature(key)?t('features'):t('notIncluded')}</span></li>)}</ul></article>)}</div><p className="plan-footnote">{t('featureHint')}</p></>:<div className="plan-limits">{[
    [t('duration'),o.limits.duration===null?'—':minutes(o.limits.duration)],[t('upload'),o.limits.upload===null?'—':formatBytes(o.limits.upload)],[t('concurrency'),o.jobs.concurrency??'—'],[t('queueLimit'),o.jobs.queue_limit??'—'],[t('retention'),o.limits.retention_days===null?'—':o.limits.retention_days+' '+t('dayUnit')],
   ].map(([label,value])=><div key={label}><span>{label}</span><strong>{value}</strong></div>)}<p>{t(o.limits.cleanup_enabled?'cleanupOn':'cleanupOff')} {t('accessHint')} {app.timezone??'UTC'}</p></div>}
  </section>
  <section className="plan-history" aria-label={t('recent')} aria-busy={busy}>
   <h2><StudioIcon name="check"/>{t('recent')}</h2><p className="plan-footnote">{t('recentHint')}</p>
   <div className="plan-filters"><label className="plan-search"><StudioIcon name="search"/><input type="search" value={query} placeholder={t('search')} aria-label={t('search')} maxLength={150} autoComplete="off" onChange={e=>setQuery(e.target.value)}/></label>
    <SelectField label={t('type')} value={filters.type} onChange={type=>navigate({type})} options={[{value:'',label:t('type')+': '+t('all')},...(['movie','translate','subs'] as const).map(key=>({value:key,label:t(key)}))]}/>
    <SelectField label={t('period')} value={filters.period} onChange={period=>navigate({period})} options={[{value:'current',label:t('period')+': '+t('current')},{value:'all',label:t('period')+': '+t('allTime')}]}/>
    <ClearFiltersButton label={t('clear')} disabled={busy||!hasFilters} onClick={clearFilters}/>
   </div>
   {failed&&<p className="plan-notice" role="alert">{t('failure')}<button className="plan-link" onClick={refresh}>{t('retry')}</button></p>}
   <div className="plan-table" role="table" aria-label={t('recent')}><div className="plan-table-head" role="row">{(['project','type','completed','charged','output'] as const).map(key=><span key={key} role="columnheader">{t(key)}{key==='completed'&&<StudioIcon name="move-down"/>}</span>)}<span role="columnheader" className="sr-only">{t('view')}</span></div>
    {exports.data.map(item=><div className="plan-table-row" role="row" key={item.id}>
     <Link href={item.url} role="cell" className="plan-project">{item.poster?<img src={item.poster} alt="" loading="lazy" onError={e=>{e.currentTarget.style.visibility='hidden';}}/>:<span className="plan-thumbnail"><StudioIcon name="folder-play"/></span>}<strong title={item.title}>{item.title}</strong></Link>
     <span role="cell"><span className="plan-chip">{t(item.type)}</span></span><span className="plan-export-date" role="cell">{completedDate(item.completed_at)}</span>
     <span role="cell" className="plan-export-charge">{item.charged_seconds===null?<ActionTooltip label={t('notRecordedHint')}><span tabIndex={0}>{t('notRecorded')}</span></ActionTooltip>:processingDuration(item.charged_seconds)}</span>
     <span role="cell" className="plan-outputs">{item.outputs.map(output=><span key={output} className="plan-chip">{output}</span>)}</span>
     <span role="cell" className="plan-row-actions"><Link className="plan-link" href={item.url}>{t('view')}<StudioIcon name="arrow"/></Link><ActionTooltip label={t('details')}><button className="plan-icon-button" onClick={()=>setSelected(item)} aria-label={t('details')}><StudioIcon name="eye"/></button></ActionTooltip></span>
    </div>)}
   </div>
   {exports.data.length===0&&<div className="plan-empty"><StudioIcon name="folder-play"/><strong>{t('empty')}</strong></div>}
   <footer className="plan-pagination"><span>{t('showing')} {exports.from??0}–{exports.to??0} {t('of')} {exports.total}</span><nav aria-label={t('recent')}><PageBoundaryButton edge="first" page={exports.current_page} pages={exports.last_page} onNavigate={page=>navigate({page})} disabled={busy}/><button disabled={busy||exports.current_page<=1} onClick={()=>navigate({page:exports.current_page-1})} aria-label={t('previous')}><StudioIcon name="back"/></button>
    {pages.map((page,index)=><span key={page}>{index>0&&page-pages[index-1]>1&&<i>…</i>}<button aria-current={page===exports.current_page?'page':undefined} disabled={busy} onClick={()=>navigate({page})}>{page}</button></span>)}
    <button disabled={busy||exports.current_page>=exports.last_page} onClick={()=>navigate({page:exports.current_page+1})} aria-label={t('nextPage')}><StudioIcon name="arrow"/></button><PageBoundaryButton edge="last" page={exports.current_page} pages={exports.last_page} onNavigate={page=>navigate({page})} disabled={busy}/></nav></footer>
  </section>
  <MediaDialog open={plans} onClose={()=>setPlans(false)} title={t('billingTitle')} headerIcon="crown" className="plan-dialog plan-plans-dialog" animated>
   <div className="plan-dialog-body"><p className="plan-notice plan-billing-note"><StudioIcon name="info"/>{t('billingHint')}</p><div className="plan-offers">{availablePlans.map(plan=>{const current=o.package?.id===plan.id,free=plan.type==='free_trial'||Number(plan.price)===0;return <article className={current?'is-current':''} key={plan.id}>
    <div className="plan-offer-heading"><span className="plan-offer-crown"><StudioIcon name="crown"/></span><div className="plan-offer-name"><h3>{plan.name}</h3>{free&&<span className="plan-trial-label">{t('trial')}</span>}</div>{current&&<span className="plan-current-badge"><StudioIcon name="check"/>{t('currentPlan')}</span>}</div>
    <strong className="plan-offer-price">{free?t('trial'):plan.currency+' '+number(Number(plan.price))}</strong><p className="plan-offer-days"><StudioIcon name="calendar"/>{plan.days} {t('dayUnit')}</p>
    <dl>{([{label:'processing',icon:'bolt',value:minutes(plan.seconds)},{label:'storage',icon:'layers',value:formatBytes(plan.storage)},{label:'videos',icon:'folder-play',value:plan.videos===null?t('unlimited'):number(plan.videos)}] as const).map(item=><div key={item.label}><dt><StudioIcon name={item.icon}/>{t(item.label)}</dt><dd>{item.value}</dd></div>)}</dl>
   </article>;})}</div>{!availablePlans.length&&<p>{t('noPlans')}</p>}</div>
   <footer className="plan-dialog-footer"><button className="plan-button" onClick={()=>setPlans(false)}>{t('close')}</button><Link className="plan-button primary" href={frameLinks.billing??'/account/billing'}>{locale==='my'?'ငွေပေးချေရန်':locale==='th'?'ชำระเงิน':'Make a Payment'}<StudioIcon name="arrow"/></Link></footer>
  </MediaDialog>
  <MediaDialog open={!!selected} onClose={()=>setSelected(null)} title={t('details')} headerIcon="clock" headerDetail={selected?.title} className="plan-dialog plan-detail-dialog" animated>
   <div className="plan-dialog-body">{detailError?<div className="plan-empty" role="alert"><p>{t('failure')}</p><button className="plan-button" onClick={()=>setAttempt(v=>v+1)}>{t('retry')}</button></div>:!detail?<p role="status" className="plan-detail-loading"><StudioIcon name="loader" className="is-spinning"/>{t('detailsLoading')}</p>:<>
    <div className="plan-detail-status"><span className="plan-completed"><StudioIcon name="check"/>{t('completedStatus')}</span><time dateTime={detail.export.completed_at}>{completedDate(detail.export.completed_at)}</time></div>
    <h3 className="plan-detail-section">{t('usageSummary')}</h3>
    <dl className="plan-detail-totals">
     <div><dt><StudioIcon name="clock"/>{t('processingDeducted')}</dt><dd>{detail.export.charged_seconds===null?t('notRecorded'):processingDuration(detail.export.charged_seconds)}</dd></div>
     <div><dt><StudioIcon name="folder-play"/>{t('videosDeducted')}</dt><dd>{detail.export.counted_videos===null?t('notRecorded'):number(detail.export.counted_videos)+' '+t('videoUnit')}</dd></div>
    </dl>
    <p className="plan-detail-explanation"><StudioIcon name="info"/><span>{detail.export.charged_seconds===null?t('notRecordedHint'):t('allowanceHint')}{detail.export.counted_videos===0&&<> {t('noExtraVideo')}</>}</span></p>
    <dl className="plan-detail-facts">{([
     {label:'type',value:t(detail.export.type)},
     {label:'output',value:detail.export.outputs.join(' · ')||'—'},
     {label:'packageLabel',value:detail.package?detail.package.name+' · v'+detail.package.version:'—'},
     {label:'period',value:detail.period_start&&detail.period_end?completedDate(detail.period_start)+' → '+completedDate(detail.period_end):'—'},
    ] as const).map(item=><div key={item.label}><dt>{t(item.label)}</dt><dd>{item.value}</dd></div>)}</dl>
   </>}</div><footer className="plan-dialog-footer">{selected&&<Link className="plan-button primary" href={selected.url}>{t('view')}<StudioIcon name="arrow"/></Link>}</footer>
  </MediaDialog>
 </div></AppLayout>;
}
function Meter({metric:m}:{metric:PlanMetric}){
 const used=m.total?Math.min(100,m.used/m.total*100):0,reserved=m.total?Math.min(100-used,m.reserved/m.total*100):0;
 return <div className="plan-meter" aria-hidden="true"><span style={{width:used+'%'}}/><span className="reserved" style={{width:reserved+'%'}}/></div>;
}
