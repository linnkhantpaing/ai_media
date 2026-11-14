import {Head} from '@inertiajs/react';
import {useEffect,useId,useRef,useState} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {SelectField} from '@/components/media-workflow';
import {DatePicker} from '@/components/date-picker';
import {ActionTooltip} from '@/components/action-tooltip';
import {PageBoundaryButton} from '@/components/page-boundary-button';
import {ProcessingCustomerDetail} from '@/components/processing-customer-detail';
import {ProcessingRing,ProcessingTrend} from '@/components/processing-report-charts';
import {useProcessingText} from '@/lib/processing-report-i18n';
import {useDisplayDate} from '@/lib/platform-display';
import {mediaApi} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import {useLoading} from '@/lib/loading';
import type {ProcessingData,ProcessingDetail,ProcessingFilters,ProcessingRow} from '@/types/processing-report';
import '../../../css/revenue.css';
import '../../../css/processing-report.css';

const parameters=(f:ProcessingFilters)=>new URLSearchParams(Object.entries(f).filter(([,v])=>v!==''&&v!==undefined).map(([k,v])=>[k,String(v)])).toString();
export default function ProcessingReport({report}:{report:ProcessingData}){
 const {t,locale,number,minutes,duration,bytes}=useProcessingText(),date=useDisplayDate(),toast=useToast(),{begin}=useLoading();
 const [data,setData]=useState(report),[filters,setFilters]=useState(report.filters),[busy,setBusy]=useState(false),[exporting,setExporting]=useState(false),[stale,setStale]=useState(false);
 const [detail,setDetail]=useState<ProcessingDetail|null>(null),[drawer,setDrawer]=useState(false),[opening,setOpening]=useState(false);
 const request=useRef<AbortController|null>(null),detailRequest=useRef<AbortController|null>(null),exportRequest=useRef<AbortController|null>(null),serial=useRef(0),alive=useRef(true),pending=useRef(false),last=useRef(JSON.stringify(report.filters)),latest=useRef(filters);
 latest.current=filters;
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;request.current?.abort();detailRequest.current?.abort();exportRequest.current?.abort();};},[]);
 async function reload(f:ProcessingFilters,notify=false,silent=false){
  if(silent&&pending.current)return;request.current?.abort();const abort=new AbortController();request.current=abort;const id=++serial.current;pending.current=true;if(!silent)setBusy(true);
  try{const result=await mediaApi<ProcessingData>(data.endpoints.index+'?'+parameters(f),'GET',undefined,abort.signal);if(alive.current&&serial.current===id){setData(result);setStale(false);if(result.pagination.page!==f.page){const next={...f,page:result.pagination.page};last.current=JSON.stringify(next);setFilters(next);}if(notify)toast.success(t('refreshed'));}}
  catch{if(alive.current&&!abort.signal.aborted){setStale(true);if(!silent)toast.error(t('error'));}}
  finally{if(alive.current&&serial.current===id){pending.current=false;setBusy(false);}}
 }
 useEffect(()=>{const key=JSON.stringify(filters);if(last.current===key)return;const timer=setTimeout(()=>{last.current=key;void reload(filters);},280);return()=>clearTimeout(timer);},[filters]);
 useEffect(()=>{const timer=setInterval(()=>{if(document.visibilityState==='visible')void reload(latest.current,false,true);},60000);return()=>clearInterval(timer);},[]);
 function change<K extends keyof ProcessingFilters>(key:K,value:ProcessingFilters[K]){setFilters(old=>({...old,[key]:value,...(key==='page'?{}:{page:1}),...(key==='view'?{q:'',sort:value==='stages'?'exports':'processing'}:{}),...(key==='dates'&&value==='custom'?{from:old.from||data.range.from,to:old.to||data.range.to}:{})}));}
 function clear(){setFilters({...report.filters,dates:'30',from:'',to:'',package:'',customer:'',customer_q:'',mode:'',source:'',q:'',sort:'processing',view:filters.view,page:1,per_page:filters.per_page});}
 async function open(row:ProcessingRow){
  if(!row.detail_url||opening)return;setOpening(true);detailRequest.current?.abort();const abort=new AbortController();detailRequest.current=abort;const end=begin();
  try{const result=await mediaApi<ProcessingDetail>(row.detail_url+'?'+parameters(data.filters),'GET',undefined,abort.signal);if(alive.current&&!abort.signal.aborted){setDetail(result);setDrawer(true);}}
  catch{if(alive.current&&!abort.signal.aborted)toast.error(t('error'));}
  finally{end();if(alive.current)setOpening(false);}
 }
 async function download(){
  if(exporting)return;setExporting(true);const abort=new AbortController();exportRequest.current=abort;
  try{const response=await fetch(data.endpoints.export+'?'+parameters(filters),{credentials:'same-origin',headers:{Accept:'text/csv'},signal:abort.signal});if(!response.ok||!response.headers.get('content-type')?.includes('text/csv'))throw new Error();const blob=await response.blob();const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='zatlan-usage-processing-'+filters.view+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),10000);if(alive.current)toast.success(t('exported'));}
  catch{if(alive.current&&!abort.signal.aborted)toast.error(t('exportFailed'));}
  finally{if(alive.current)setExporting(false);}
 }
 const summary=data.summary,p=data.pagination,numbers=[...new Set([1,p.pages,p.page-1,p.page,p.page+1])].filter(n=>n>0&&n<=p.pages).sort((a,b)=>a-b);
 const packageBadge=(row:ProcessingRow)=>row.packages?.length?<div className="pr-packages">{row.packages.slice(0,2).map((pack,i)=><span className="rv-package" key={pack.name+pack.version+i}><StudioIcon name="layers"/>{pack.name}<small>v{pack.version}</small></span>)}{row.packages.length>2&&<small>+{number(row.packages.length-2)}</small>}</div>:<span className="pr-muted">{t('unknown')}</span>;
 const name=(row:ProcessingRow)=>row.name??(row.id==='unknown'?t('unknown'):t(String(row.id)));
 const rate=(value:number|null|undefined)=>value===null||value===undefined?'—':number(value)+'%';
 const cards:[string,IconName,string,string][]=[
  ['projects','play',number(summary.projects),'projectHint'],['processing','layers',minutes(summary.seconds),'processingHint'],
  ['successful','folder-play',number(summary.successful),'periodMetric'],['failed','close',number(summary.failed),'periodMetric'],
  ['average','clock',summary.average_duration===null?'\u2014':String(Math.floor(Math.round(summary.average_duration)/60)).padStart(2,'0')+':'+String(Math.round(summary.average_duration)%60).padStart(2,'0'),'averageHint'],['storage','database',bytes(summary.storage),'current']];
 const tableColumns=filters.view==='customers'?['customer','package','projects','processing','exportSF','average','storage','actions']:filters.view==='packages'?['package','customers','projects','processing','successful','failed','successRate']:['stages','completed','failed','cancelled','averageHint'];
 const topTotal=data.top_total;
 return <AppLayout area="admin" frame="preferences" adminPage="usageReports" breadcrumbLabel={t('title')}><Head title={t('title')}/><section className="rv-page pr-page">
 <header className="rv-page-heading"><div className="rv-title"><span className="rv-title-icon"><StudioIcon name="chart"/></span><div><h1>{t('title')}</h1><p>{t('subtitle')}</p></div></div><div className="rv-heading-actions"><button type="button" className="media-button" disabled={exporting} onClick={()=>void download()}><StudioIcon name="download"/>{t('export')}</button><button type="button" className="media-button primary" disabled={busy} onClick={()=>void reload(filters,true)}><StudioIcon name="refresh" className={busy?'accounts-refresh-spin':''}/>{t('refresh')}</button></div></header>
 <div className="pr-filters">
 <SelectField label={t('dates')} value={filters.dates} options={[['1','today'],['7','last7'],['30','last30'],['month','month'],['last_month','lastMonth'],['all','allTime'],['custom','custom']].map(([value,k])=>({value,label:t(k)}))} onChange={v=>change('dates',v)}/>
 <SelectField label={t('packages')} value={String(filters.package)} options={[{value:'',label:t('allPackages')},...data.options.packages.map(pack=>({value:String(pack.id),label:pack.name,detail:pack.code}))]} onChange={v=>change('package',v)}/>
 <CustomerPicker data={data} value={String(filters.customer)} search={filters.customer_q??''} onSearch={v=>change('customer_q',v)} onChange={v=>change('customer',v)}/>
 <SelectField label={t('mode')} value={filters.mode} options={['','dub','subs'].map(value=>({value,label:t(value||'allModes')}))} onChange={v=>change('mode',v)}/>
 <SelectField label={t('source')} value={filters.source} options={['','youtube','upload'].map(value=>({value,label:t(value||'allSources')}))} onChange={v=>change('source',v)}/>
 <button type="button" className="media-button pr-clear" onClick={clear}><StudioIcon name="sliders"/>{t('clear')}</button></div>
 {filters.dates==='custom'&&<div className="rv-custom-dates"><DatePicker id="pr-from" label={t('from')} value={filters.from} today={data.today} max={filters.to||data.today} locale={locale} onChange={v=>change('from',v)}/><DatePicker id="pr-to" label={t('to')} value={filters.to} today={data.today} min={filters.from} max={data.today} locale={locale} onChange={v=>change('to',v)}/></div>}
 <div className="pr-range"><StudioIcon name="calendar"/><span>{t('selected')}: {data.range.from} – {data.range.to}</span><i/><span>{t('timezone')}: {data.range.timezone}</span></div>
 {stale&&<div role="alert" className="rv-stale"><StudioIcon name="alert"/>{t('stale')}<button className="media-button" onClick={()=>void reload(filters,true)}>{t('refresh')}</button></div>}
 <div className="pr-stats">{cards.map(([key,icon,value,hint])=><article key={key} className={'pr-stat is-'+key}><span className="pr-stat-icon"><StudioIcon name={icon}/></span><div><h2>{t(key)}</h2><strong>{value}</strong><small>{t(hint)}</small></div></article>)}</div>
 <div className="pr-meta-strip"><span><StudioIcon name="check"/>{t('successRate')} <strong>{rate(summary.success_rate)}</strong></span><span><StudioIcon name="close"/>{t('cancelled')} <strong>{number(summary.cancelled)}</strong></span><span><StudioIcon name="clock"/>{t('reserved')} <strong>{minutes(summary.reserved)}</strong><small>{t('current')}</small></span></div>
 <div className="pr-main-charts"><ProcessingTrend data={data}/><ProcessingRing title={t('outcomes')} icon="activity" center={number(summary.successful+summary.failed+summary.cancelled)} unit={t('exports')} values={[{key:'successful',label:t('successful'),value:summary.successful,color:'#36D9B6'},{key:'failed',label:t('failed'),value:summary.failed,color:'#F18AAB'},...(summary.cancelled?[{key:'cancelled',label:t('cancelled'),value:summary.cancelled,color:'#94A2BA'}]:[])]}/></div>
 <div className="pr-secondary-charts">
 <ProcessingRing title={t('modeChart')} compact center={number(summary.seconds/60)} unit={t('minutes')} values={['dub','subs','unknown'].filter(k=>k!=='unknown'||data.modes[k]!==undefined).map(key=>({key,label:t(key),value:data.modes[key]??0,formatted:minutes(data.modes[key]??0)}))}/>
 <ProcessingRing title={t('sourceChart')} compact center={number(summary.projects)} unit={t('projects')} values={['youtube','upload','unknown'].filter(k=>k!=='unknown'||data.sources[k]!==undefined).map(key=>({key,label:t(key),value:data.sources[key]??0}))}/>
 <section className="rv-panel pr-top"><header><h2><StudioIcon name="users"/>{t('top')}</h2></header>{data.top.length?<ol>{data.top.map((row,i)=><li key={row.id}><span className="pr-rank">{number(i+1)}</span><span className={'rv-avatar tone-'+i}>{row.name?.slice(0,1)}</span><strong title={row.name??''}>{row.name}</strong><b>{minutes(row.seconds??0)}</b><div><i style={{width:Math.min(100,topTotal>0?(row.seconds??0)/topTotal*100:0)+'%'}}/></div><small>{rate(topTotal>0?Math.min(100,(row.seconds??0)/topTotal*100):null)}</small></li>)}{summary.seconds>data.top.reduce((sum,r)=>sum+(r.seconds??0),0)&&<li className="pr-top-others"><span>{t('others')}</span><b>{minutes(summary.seconds-data.top.reduce((sum,r)=>sum+(r.seconds??0),0))}</b></li>}</ol>:<div className="pr-small-empty">{t('noChart')}</div>}</section>
 </div>
 <section className="rv-panel pr-table-panel" aria-busy={busy}><header><h2><StudioIcon name="receipt"/>{t('table')}</h2><small>{t('updated')}: {date(data.updated_at,true)}</small></header>
 <div className="pr-table-tools"><nav className="rv-view-tabs" aria-label={t('table')}>{(['customers','packages','stages'] as const).map(value=><button type="button" key={value} aria-pressed={filters.view===value} className={filters.view===value?'is-active':''} onClick={()=>change('view',value)}>{t(value)}</button>)}</nav><label className="rv-search"><StudioIcon name="search"/><input type="search" aria-label={t('tableSearch')} placeholder={t('tableSearch')} maxLength={100} value={filters.q} onChange={e=>change('q',e.target.value)}/></label><SelectField label={t('sort')} value={filters.sort} options={[['processing','mostProcessing'],['exports','mostExports'],['failures','mostFailures'],['storage','mostStorage'],['name','name']].filter(([value])=>filters.view==='customers'||(filters.view==='packages'?value!=='storage':['exports','failures','name'].includes(value))).map(([value,k])=>({value,label:t(k)}))} onChange={v=>change('sort',v)}/></div>
 <p className="pr-table-hint">{t(filters.view==='stages'?'stageHint':'historicHint')}</p>
 {data.items.length?<div className="rv-table-wrap"><table className={'rv-table pr-table is-'+filters.view}><thead><tr><th>#</th>{tableColumns.map(key=><th key={key}>{t(key)}</th>)}</tr></thead><tbody>{data.items.map((row,i)=><tr key={row.id}><td className="rv-row-number">{number(p.from+i)}</td>
 {filters.view==='customers'?<><td className="rv-customer-cell"><div className="rv-customer"><span className={'rv-avatar tone-'+i%4}>{row.name?.split(/\s+/).slice(0,2).map(s=>s[0]).join('').toUpperCase()}</span><div><strong>{row.name}</strong><small>{row.email}</small></div></div></td><td data-label={t('package')}>{packageBadge(row)}</td><td data-label={t('projects')}>{number(row.projects??0)}</td><td data-label={t('processing')}><strong>{minutes(row.seconds??0)}</strong></td><td data-label={t('exportSF')}><span className="pr-export-pair"><span className="pr-success">{number(row.successful??0)}</span><span className="pr-muted"> / </span><span className="pr-failed">{number(row.failed??0)}</span></span></td><td data-label={t('average')}>{duration(row.average_duration)}</td><td data-label={t('storage')}>{bytes(row.storage??0)}</td><td className="rv-action"><ActionTooltip label={t('view')}><button type="button" className="media-button rv-icon" disabled={opening} aria-label={t('view')+' '+row.name} onClick={()=>void open(row)}><StudioIcon name="eye"/></button></ActionTooltip></td></>
 :filters.view==='packages'?<><td data-label={t('package')}><span className="rv-package"><StudioIcon name="layers"/>{name(row)}</span></td><td data-label={t('customers')}>{number(row.customers??0)}</td><td data-label={t('projects')}>{number(row.projects??0)}</td><td data-label={t('processing')}>{minutes(row.seconds??0)}</td><td data-label={t('successful')} className="pr-success">{number(row.successful??0)}</td><td data-label={t('failed')} className="pr-failed">{number(row.failed??0)}</td><td data-label={t('successRate')}>{rate(row.success_rate)}</td></>
 :<><td data-label={t('stages')}><span className="rv-method"><StudioIcon name="activity"/>{name(row)}</span></td><td data-label={t('completed')}>{number(row.completed??0)}</td><td data-label={t('failed')}>{number(row.failed??0)}</td><td data-label={t('cancelled')}>{number(row.cancelled??0)}</td><td data-label={t('averageHint')}>{duration(row.average_duration)}</td></>}
 </tr>)}</tbody></table></div>:<div className="rv-empty"><StudioIcon name="chart"/><h3>{t('empty')}</h3><p>{t('emptyHint')}</p></div>}
 <footer className="rv-pagination-footer"><p>{t('showing')} {number(p.from)}–{number(p.to)} {t('of')} {number(p.total)} {t('records')}</p><nav className="rv-pagination" aria-label={t('table')}><SelectField label={t('pageSize')} value={String(filters.per_page)} options={[10,25,50].map(n=>({value:String(n),label:String(n)}))} onChange={v=>change('per_page',Number(v))}/><PageBoundaryButton edge="first" page={p.page} pages={p.pages} onNavigate={n=>change('page',n)} className="media-button rv-icon"/><ActionTooltip label={t('previous')}><button className="media-button rv-icon" type="button" disabled={p.page<=1} aria-label={t('previous')} onClick={()=>change('page',p.page-1)}><StudioIcon name="back"/></button></ActionTooltip>{numbers.map((n,i)=><span className="rv-page-number" key={n}>{i>0&&n>numbers[i-1]+1&&<i>…</i>}<button type="button" className={'media-button rv-icon'+(n===p.page?' primary':'')} aria-current={n===p.page?'page':undefined} onClick={()=>change('page',n)}>{number(n)}</button></span>)}<ActionTooltip label={t('next')}><button className="media-button rv-icon" type="button" disabled={p.page>=p.pages} aria-label={t('next')} onClick={()=>change('page',p.page+1)}><StudioIcon name="arrow"/></button></ActionTooltip><PageBoundaryButton edge="last" page={p.page} pages={p.pages} onNavigate={n=>change('page',n)} className="media-button rv-icon"/></nav></footer></section>
 <p className="pr-footnote"><StudioIcon name="info"/>{t('stockHint')}</p>
 </section>
 <ProcessingCustomerDetail detail={detail} open={drawer} onClose={()=>setDrawer(false)}/></AppLayout>;
}

function CustomerPicker({data,value,search,onChange,onSearch}:{data:ProcessingData;value:string;search:string;onChange:(v:string)=>void;onSearch:(v:string)=>void}){
 const {t}=useProcessingText(),[open,setOpen]=useState(false),root=useRef<HTMLDivElement>(null),trigger=useRef<HTMLButtonElement>(null),id=useId();
 useEffect(()=>{if(!open)return;const close=(event:PointerEvent)=>{if(!root.current?.contains(event.target as Node))setOpen(false);};document.addEventListener('pointerdown',close);return()=>document.removeEventListener('pointerdown',close);},[open]);
 const selected=data.options.customers.find(c=>String(c.id)===value);
 return <div className="pr-customer-picker" ref={root} onKeyDown={e=>{if(e.key==='Escape'){e.preventDefault();setOpen(false);trigger.current?.focus();}}}><button ref={trigger} type="button" className="media-select-trigger" aria-haspopup="dialog" aria-controls={id} aria-expanded={open} aria-label={t('customers')} onClick={()=>setOpen(!open)}><span><strong>{selected?.name??t('allCustomers')}</strong></span><StudioIcon name="chevron"/></button>{open&&<div className="pr-customer-options" id={id} role="dialog" aria-label={t('customers')}><label className="rv-search"><StudioIcon name="search"/><input autoFocus type="search" value={search} maxLength={100} aria-label={t('customerSearch')} placeholder={t('customerSearch')} onChange={e=>onSearch(e.target.value)}/></label><div className="pr-customer-results"><button type="button" className={!value?'is-selected':''} onClick={()=>{onChange('');setOpen(false);trigger.current?.focus();}}>{t('allCustomers')}</button>{data.options.customers.map(c=><button type="button" key={c.id} className={String(c.id)===value?'is-selected':''} onClick={()=>{onChange(String(c.id));setOpen(false);trigger.current?.focus();}}><strong>{c.name}</strong><small>{c.email}</small></button>)}</div></div>}</div>;
}
