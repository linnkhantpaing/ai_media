import {PageBoundaryButton} from '@/components/page-boundary-button';
import {useDisplayDate} from '@/lib/platform-display';
import {Head} from '@inertiajs/react';
import {useEffect,useRef,useState,type FormEvent} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {MediaDialog,SelectField} from '@/components/media-workflow';
import {DatePicker} from '@/components/date-picker';
import {mediaApi,MediaApiError} from '@/lib/media-api';
import {useLoading} from '@/lib/loading';
import {useToast} from '@/lib/toast';
import {useUsageText} from '@/lib/usage-i18n';
import type {UsageRow,UsageList,UsageFilters,UsageDetails,UsageCustomer,UsageVideo,CustomerUsageDetails} from '@/types/usage';
import '../../../css/usage-ledger.css';

function initial(name:string){return name.trim().split(/\s+/u).slice(0,2).map(w=>Array.from(w)[0]??'').join('').toUpperCase();}
function Badge({row}:{row:UsageRow}){const {t}=useUsageText();return <span className={'usage-badge is-'+row.status}><StudioIcon name={row.status==='charged'||row.status==='stored'?'check':row.status==='released'?'back':row.status==='adjusted'?'edit':'clock'}/>{t(row.status)}</span>;}
function Package({row}:{row:Pick<UsageRow,"package">}){const {t}=useUsageText();return row.package?<span className="usage-package"><StudioIcon name={row.package.type==='trial'?'sparkles':row.package.name.toLowerCase().includes('premium')?'layers':'crown'}/>{row.package.name}<small>v{row.package.version}</small></span>:<small className="usage-muted">{t('unmetered')}</small>;}
function User({row}:{row:{id:number;customer:{name:string;email:string}}}){return <div className="usage-customer"><span className={'usage-avatar hue-'+(row.id%4)}>{initial(row.customer.name)}</span><span><strong>{row.customer.name}</strong><small>{row.customer.email}</small></span></div>;}

function Allowances({row,num,size}:{row:UsageCustomer;num:(n:number)=>string;size:(n:number)=>string}) {
 const {t}=useUsageText();
 const values:[IconName,string,number|null,number,string][]=[
  ['play',t('videos'),row.allowance.videos,row.used.videos,row.allowance.videos===null?t('noLimit'):num(row.allowance.videos)],
  ['database',t('storage'),row.allowance.storage_bytes,row.used.storage_bytes,row.allowance.storage_bytes===null?'\u2014':size(row.allowance.storage_bytes)],
  ['clock',t('minutes'),row.allowance.seconds,row.used.seconds,row.allowance.seconds===null?'\u2014':num(row.allowance.seconds/60)+' '+t('minutes')]
 ];
 return <div className="usage-allowances">{values.map(([icon,label,total,used,totalText],i)=><div key={icon} className={'usage-allowance-metric metric-'+i}>
  <span className="usage-metric-icon"><StudioIcon name={icon}/><span className="sr-only">{label}</span></span>
  <span><strong>{totalText}</strong><i>/</i><span>{i===1?size(used):num(i===2?used/60:used)+(i===2?' '+t('minutes'):'')}</span></span>
  <span className="usage-meter" aria-hidden="true"><i style={{width:Math.min(100,total&&total>0?used/total*100:0)+'%'}}/></span>
 </div>)}</div>;
}
function Remaining({row,num,size}:{row:UsageCustomer;num:(n:number)=>string;size:(n:number)=>string}) {
 const {t}=useUsageText();
 return <div className="usage-remaining">{(['videos','storage_bytes','seconds'] as const).map((key,i)=><span key={key} className={row.remaining[key]===0?'is-empty':''}>
  <StudioIcon name={i===0?'play':i===1?'database':'clock'}/>
  {row.remaining[key]===null?(i===0?t('noLimit'):'\u2014'):i===1?size(row.remaining[key]!):num(i===2?row.remaining[key]!/60:row.remaining[key]!)+(i===0?' '+t('videos'):' '+t('minutes'))}
 </span>)}</div>;
}

export default function UsageLedger({ledger:initialData}:{ledger:UsageList}){
 const displayDate=useDisplayDate();
 const {t,locale}=useUsageText();const toast=useToast();const {begin}=useLoading();
 const [data,setData]=useState(initialData),[filters,setFilters]=useState(initialData.filters),[page,setPage]=useState(initialData.pagination.page);
 const [refreshing,setRefreshing]=useState(false),[failed,setFailed]=useState(false),[exporting,setExporting]=useState(false);
 const [detail,setDetail]=useState<UsageDetails|null>(null),[drawer,setDrawer]=useState(false),[adjustment,setAdjustment]=useState<UsageDetails|null>(null);
 const [customerDetail,setCustomerDetail]=useState<CustomerUsageDetails|null>(null),[customerDrawer,setCustomerDrawer]=useState(false),[customerTab,setCustomerTab]=useState<'summary'|'recent'|'history'>('summary'),[customerBusy,setCustomerBusy]=useState(false),[video,setVideo]=useState<UsageVideo|null>(null),[videoError,setVideoError]=useState(false);
 const customerRequest=useRef<AbortController|null>(null);
 useEffect(()=>{setVideoError(false);},[video]);
 const request=useRef<AbortController|null>(null),detailRequest=useRef<AbortController|null>(null),version=useRef(0),mounted=useRef(true),last=useRef(JSON.stringify([filters,page]));
 const language=locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US';
 const num=(n:number)=>new Intl.NumberFormat(language,{maximumFractionDigits:2}).format(n);
 const date=(v:string|null,time=false)=>v?displayDate(v,time):'—';
 const size=(bytes:number)=>bytes>=1073741824?num(bytes/1073741824)+' GiB':bytes>=1048576?num(bytes/1048576)+' MiB':bytes>=1024?num(bytes/1024)+' KiB':num(bytes)+' B';
 const fail=(error:unknown)=>error instanceof MediaApiError?Object.values(error.fields).flat()[0]??t('error'):t('error');
 const query=(f=filters,p=page)=>new URLSearchParams({...f,per_page:String(f.per_page),page:String(p)}).toString();
 useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;request.current?.abort();detailRequest.current?.abort();customerRequest.current?.abort();};},[]);
 async function reload(f=filters,p=page,notify=false){
  request.current?.abort();const controller=new AbortController();request.current=controller;const id=++version.current;setRefreshing(true);setFailed(false);
  const done=notify?begin():()=>{};
  try{const result=await mediaApi<UsageList>(data.endpoints.index+'?'+query(f,p),'GET',undefined,controller.signal);
   if(mounted.current&&id===version.current){setData(result);if(p>result.pagination.pages)setPage(result.pagination.pages);if(notify)toast.success(t('refreshed'));}}
  catch(error){if(mounted.current&&!controller.signal.aborted){setFailed(true);toast.error(fail(error));}}
  finally{done();if(mounted.current&&id===version.current)setRefreshing(false);}
 }
 useEffect(()=>{const key=JSON.stringify([filters,page]);if(last.current===key)return;
  if(filters.dates==='custom'&&(!filters.from||!filters.to||filters.to<filters.from))return;
  const timer=setTimeout(()=>{last.current=key;void reload(filters,page);},300);return()=>clearTimeout(timer);
 },[filters,page]);
 function change(key:keyof UsageFilters,value:string|number){setFilters(f=>({...f,[key]:value}));setPage(1);}
 async function open(row:UsageRow,correct=false){
  detailRequest.current?.abort();const controller=new AbortController();detailRequest.current=controller;const done=begin();
  try{const result=await mediaApi<UsageDetails>(row.detail_url,'GET',undefined,controller.signal);done();
   if(mounted.current&&!controller.signal.aborted){if(correct)setAdjustment(result);else{setDetail(result);setDrawer(true);}}}
  catch(error){if(!controller.signal.aborted)toast.error(fail(error));}finally{done();}
 }

 async function showCustomer(row:UsageCustomer,tab:'summary'|'recent'|'history'='summary',recordsPage=1,recentPage=1){
  customerRequest.current?.abort();const controller=new AbortController();customerRequest.current=controller;
  setCustomerBusy(true);const done=customerDrawer?()=>{}:begin();
  try{const result=await mediaApi<CustomerUsageDetails>(row.detail_url+'?'+query(filters,1)+'&records_page='+recordsPage+'&recent_page='+recentPage,'GET',undefined,controller.signal);
   if(mounted.current&&!controller.signal.aborted){setCustomerDetail(result);setCustomerTab(tab);setCustomerDrawer(true);}}
  catch(error){if(!controller.signal.aborted)toast.error(fail(error));}
  finally{done();if(mounted.current&&!controller.signal.aborted)setCustomerBusy(false);}
 }

 async function exportCsv(){
  if(exporting)return;
  if(filters.dates==='custom'&&(!filters.from||!filters.to||filters.to<filters.from)){toast.error(t('custom'));return;}
  setExporting(true);
  try{const response=await fetch(data.endpoints.export+'?'+query(),{credentials:'same-origin',redirect:'error',headers:{Accept:'text/csv','X-Requested-With':'XMLHttpRequest'}});
   if(!response.ok){toast.error(response.status===422?t('exportLimit'):t('error'));return;}
   const blob=await response.blob();const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='usage-ledger.csv';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);toast.success(t('exported'));
  }catch{toast.error(t('error'));}finally{if(mounted.current)setExporting(false);}
 }
 const options=(values:string[])=>values.map(value=>({value,label:t(value)}));
 const cards:[string,IconName,string,string][]=[['processingUsed','play',num(data.summary.seconds/60)+' '+t('minutes'),'selected'],['videosCounted','play',num(data.summary.videos),'selected'],['processingReserved','clock',num(data.summary.reserved_seconds/60)+' '+t('minutes'),'current'],['storageInUse','database',size(data.summary.storage_bytes),'current']];
 return <AppLayout area="admin" adminPage="usageLedger" frame="dashboard" breadcrumbLabel={t('title')}><Head title={t('title')}/>
 <section className="usage-page">
  <header className="usage-page-header"><div className="usage-heading"><span className="usage-title-icon"><StudioIcon name="play"/></span><div><h1>{t('title')}</h1><p>{t('description')}</p></div></div><div className="usage-header-actions"><button className="media-button" onClick={exportCsv} disabled={exporting}><StudioIcon name={exporting?'loader':'download'}/>{t('export')}</button><button className="media-button primary" onClick={()=>reload(filters,page,true)} disabled={refreshing}><StudioIcon name={refreshing?'loader':'refresh'}/>{t('refresh')}</button></div></header>
  <div className="usage-summary">{cards.map(([key,icon,value,caption],index)=><article key={key} className={'usage-stat tone-'+index}><span className="usage-stat-icon"><StudioIcon name={icon}/></span><div><span>{t(key)}</span><strong>{value}</strong><small>{caption==='selected'?t(filters.dates==='30'?'last30':filters.dates==='7'?'last7':filters.dates==='1'?'today':filters.dates==='custom'?'custom':'allDates'):t(caption)}</small></div><svg viewBox="0 0 320 40" preserveAspectRatio="none" aria-hidden="true"><path d="M0 38 Q65 9 130 28 T260 24 L320 8 V40 H0Z"/></svg></article>)}</div>
  <div className="usage-table-card">
   <div className="usage-toolbar"><label className="search-input-row usage-search"><StudioIcon name="search"/><input type="search" aria-label={t('search')} placeholder={t('search')} value={filters.q} onChange={e=>change('q',e.target.value)} maxLength={100}/></label>
    <SelectField label={t('package')} value={filters.package_id} options={[{value:'',label:t('allPackages')},...data.packages.map(p=>({value:String(p.id),label:p.name}))]} onChange={v=>change('package_id',v)}/>
    <SelectField label={t('usage')} value={filters.kind} options={[{value:'',label:t('allTypes')},...options(['processing','storage'])]} onChange={v=>change('kind',v)}/>
    <SelectField label={t('period')} value={filters.period} options={[{value:'current',label:t('periodCurrent')},{value:'all',label:t('periodAll')}]} onChange={v=>change('period',v)}/>
    <SelectField label={t('period')} value={filters.dates} options={[{value:'all',label:t('allDates')},{value:'1',label:t('today')},{value:'7',label:t('last7')},{value:'30',label:t('last30')},{value:'custom',label:t('custom')}]} onChange={v=>change('dates',v)}/>
    <SelectField label={t('recorded')} value={filters.sort} options={options(['newest','oldest'])} onChange={v=>change('sort',v)}/>
    <ActionTooltip label={t('refresh')}><button className="icon-button" aria-label={t('refresh')} disabled={refreshing} onClick={()=>reload(filters,page,true)}><StudioIcon name={refreshing?'loader':'refresh'}/></button></ActionTooltip>
    <button className="media-button usage-clear" onClick={()=>{setFilters({...initialData.filters,q:'',package_id:'',kind:'',status:'',period:'current',dates:'30',from:'',to:'',sort:'newest',per_page:10});setPage(1);}}>{t('clear')}</button>
   </div>
   {filters.dates==='custom'&&<div className="usage-date-filters"><label>{t('from')}<DatePicker id="usage-from" today={data.today} locale={locale} value={filters.from} onChange={v=>change('from',v)} label={t('from')}/></label><label>{t('to')}<DatePicker id="usage-to" today={data.today} locale={locale} min={filters.from||undefined} value={filters.to} onChange={v=>change('to',v)} label={t('to')}/></label></div>}
   {failed?<div className="usage-empty"><StudioIcon name="alert"/><p>{t('error')}</p><button className="media-button" onClick={()=>reload()}>{t('retry')}</button></div>:<div className="usage-table-scroll" aria-busy={refreshing}><table className="usage-table usage-customer-table"><thead><tr>
   <th>#</th><th>{t('customer')}</th><th>{t('package')}</th><th className="usage-allowance-heading">{t('allowanceTotalUsed')}</th>
   <th>{t('reserved')}</th><th>{t('remainingAllowance')}</th><th>{t('recentUsage')}</th><th>{t('actions')}</th>
   </tr></thead><tbody>{data.items.map((row,i)=><tr key={row.id}>
    <td className="usage-index">{(data.pagination.from??1)+i}</td>
    <td className="usage-customer-cell"><User row={row}/></td>
    <td className="usage-package-cell" data-label={t('package')}><Package row={row}/><small className="usage-period">{date(row.period_start)}{row.period_end?' \u2013 '+date(row.period_end):''}</small>{(row.account_status!=='active'||(row.status!=='active'&&row.status!=='unmetered'))&&<small className={'usage-period-status is-'+(row.account_status!=='active'?row.account_status:row.status)}>{t(row.account_status!=='active'?row.account_status:row.status)}</small>}</td>
    <td className="usage-allowance-cell" data-label={t('allowanceTotalUsed')}><Allowances row={row} num={num} size={size}/></td>
    <td className="usage-reserved-cell" data-label={t('reserved')}><span className="usage-reserved-total"><StudioIcon name="clock"/>{num(row.reserved.seconds/60)} {t('minutes')}</span>{row.reserved.videos>0&&<small>{num(row.reserved.videos)} {t('videos')}</small>}{row.reserved.storage_bytes>0&&<small>{size(row.reserved.storage_bytes)}</small>}</td>
    <td className="usage-remaining-cell" data-label={t('remainingAllowance')}><Remaining row={row} num={num} size={size}/></td>
    <td className="usage-recent-cell" data-label={t('recentUsage')}><div className="usage-recent">{row.recent.map(video=><ActionTooltip key={video.id} label={video.title}><button type="button" className="usage-thumb" aria-label={t('playVideo')+' '+video.title} onClick={()=>setVideo(video)}>{video.thumbnail&&<img src={video.thumbnail} alt="" loading="lazy" onError={e=>{e.currentTarget.style.display='none';}}/>}<span><StudioIcon name="play"/></span></button></ActionTooltip>)}{row.recent_count>3&&<ActionTooltip label={t('allSuccessful')}><button className="usage-more-videos" onClick={()=>showCustomer(row,'recent')}>+{num(row.recent_count-3)}</button></ActionTooltip>}{row.recent_count===0&&<span className="usage-no-videos">{t('noSuccessYet')}</span>}</div></td>
    <td className="usage-customer-actions"><div className="usage-actions"><ActionTooltip label={t('customerDetails')}><button type="button" className="icon-button" aria-label={t('customerDetails')+' '+row.customer.name} onClick={()=>showCustomer(row,'summary')}><StudioIcon name="eye"/></button></ActionTooltip></div></td>
   </tr>)}</tbody></table>{data.items.length===0&&<div className="usage-empty"><StudioIcon name="users"/><p>{t('emptyCustomers')}</p></div>}</div>}
   <footer className="usage-pagination"><span>{t('showing')} {num(data.pagination.from??0)}–{num(data.pagination.to??0)} {t('of')} {num(data.pagination.total)} {t('customers')}<small>{data.timezone}</small></span><div><SelectField label={t('records')} value={String(filters.per_page)} options={[10,20,50].map(n=>({value:String(n),label:String(n)}))} onChange={v=>change('per_page',Number(v))}/><PageBoundaryButton edge="first" page={page} pages={data.pagination.pages} onNavigate={target=>setPage(target)} className="icon-button" disabled={refreshing}/><button className="icon-button" aria-label={t('previous')} disabled={page<=1||refreshing} onClick={()=>setPage(p=>p-1)}><StudioIcon name="back"/></button><span className="usage-page-number">{num(page)}</span><small>/ {num(data.pagination.pages)}</small><button className="icon-button" aria-label={t('next')} disabled={page>=data.pagination.pages||refreshing} onClick={()=>setPage(p=>p+1)}><StudioIcon name="arrow"/></button><PageBoundaryButton edge="last" page={page} pages={data.pagination.pages} onNavigate={target=>setPage(target)} className="icon-button" disabled={refreshing}/></div></footer>
  </div><p className="usage-footnote"><StudioIcon name="info"/>{t('summaryHint')}</p>
 </section>
 <MediaDialog open={customerDrawer} onClose={()=>setCustomerDrawer(false)} title={t('customerDetails')} headerIcon="users" headerDetail={customerDetail?.customer.customer.email} animated className="usage-drawer usage-customer-drawer">
 {customerDetail&&<><div className="usage-dialog-body">
  <div className="usage-customer-overview"><User row={customerDetail.customer}/><Package row={customerDetail.customer}/></div>
  <small className="usage-period">{date(customerDetail.customer.period_start)} {'\u2013'} {date(customerDetail.customer.period_end)}</small>
  <nav className="usage-detail-tabs" aria-label={t('customerDetails')}>{(['summary','recent','history'] as const).map(tab=><button type="button" key={tab} aria-pressed={customerTab===tab} onClick={()=>setCustomerTab(tab)}>{t(tab==='summary'?'overview':tab==='recent'?'recentUsage':'usageHistory')} {tab==='recent'&&<small>{num(customerDetail.recent_pagination.total)}</small>}{tab==='history'&&<small>{num(customerDetail.records_pagination.total)}</small>}</button>)}</nav>
  {customerBusy&&<p className="usage-panel-updating" role="status"><StudioIcon name="loader"/>{t('refresh')}</p>}
  {customerTab==='summary'&&<><section className="usage-detail-section"><h3><StudioIcon name="layers"/>{t('allowanceTotalUsed')}</h3><Allowances row={customerDetail.customer} num={num} size={size}/></section>
  <section className="usage-detail-section"><h3><StudioIcon name="chart"/>{t('remainingAllowance')}</h3><Remaining row={customerDetail.customer} num={num} size={size}/><p className="usage-reservation"><StudioIcon name="clock"/>{t('reserved')}: {num(customerDetail.customer.reserved.seconds/60)} {t('minutes')} {'\u00b7'} {num(customerDetail.customer.reserved.videos)} {t('videos')}</p><small>{t('remainingHint')}</small></section></>}
  {(customerTab==='summary'||customerTab==='recent')&&<section className="usage-detail-section"><h3><StudioIcon name="play"/>{t('recentUsage')}<small>{t('successfulOnly')}</small></h3>
   <div className="usage-video-grid">{(customerTab==='summary'?customerDetail.recent.slice(0,3):customerDetail.recent).map(item=><button key={item.id} type="button" className="usage-video-card" onClick={()=>setVideo(item)}><span className="usage-video-poster">{item.thumbnail&&<img src={item.thumbnail} alt="" loading="lazy" onError={e=>{e.currentTarget.style.display='none';}}/>}<StudioIcon name="play"/>{item.duration!==null&&<small>{Math.floor(item.duration/60)}:{String(Math.floor(item.duration%60)).padStart(2,'0')}</small>}</span><strong>{item.title}</strong><small>{date(item.completed_at,true)}</small></button>)}</div>
   {customerDetail.recent.length===0&&<p className="usage-muted">{t('noSuccessYet')}</p>}
   {customerTab==='summary'&&customerDetail.recent_pagination.total>3&&<button className="media-button" onClick={()=>setCustomerTab('recent')}>{t('allSuccessful')}<StudioIcon name="arrow"/></button>}
   {customerTab==='recent'&&<div className="usage-inner-pagination"><span>{num(customerDetail.recent_pagination.total)} {t('videos')}</span><PageBoundaryButton edge="first" page={customerDetail.recent_pagination.page} pages={customerDetail.recent_pagination.pages} onNavigate={target=>showCustomer(customerDetail.customer,'recent',1,target)} className="icon-button" disabled={customerBusy}/><button className="icon-button" disabled={customerBusy||customerDetail.recent_pagination.page<=1} aria-label={t('previous')} onClick={()=>showCustomer(customerDetail.customer,'recent',1,customerDetail.recent_pagination.page-1)}><StudioIcon name="back"/></button><small>{num(customerDetail.recent_pagination.page)} / {num(customerDetail.recent_pagination.pages)}</small><button className="icon-button" disabled={customerBusy||customerDetail.recent_pagination.page>=customerDetail.recent_pagination.pages} aria-label={t('next')} onClick={()=>showCustomer(customerDetail.customer,'recent',1,customerDetail.recent_pagination.page+1)}><StudioIcon name="arrow"/></button><PageBoundaryButton edge="last" page={customerDetail.recent_pagination.page} pages={customerDetail.recent_pagination.pages} onNavigate={target=>showCustomer(customerDetail.customer,'recent',1,target)} className="icon-button" disabled={customerBusy}/></div>}
  </section>}
  {customerTab==='history'&&<section className="usage-detail-section"><h3><StudioIcon name="list"/>{t('usageHistory')}</h3><small>{t('historyHint')}</small>
   <div className="usage-history-list">{customerDetail.records.map(record=><article key={record.id}><div className="usage-history-title"><strong>{record.project.title}</strong><Badge row={record}/></div><small>{record.code} {'\u00b7'} {date(record.updated_at,true)}</small><div className="usage-history-values"><span>{record.kind==='storage'?size(record.storage_bytes):num((record.status==='reserved'?record.reserved_seconds:record.status==='released'?record.released_seconds??0:record.charged_seconds-record.returned_seconds)/60)+' '+t('minutes')}</span><div className="usage-actions"><ActionTooltip label={t('view')}><button className="icon-button" aria-label={t('view')+' '+record.code} onClick={()=>{setCustomerDrawer(false);void open(record);}}><StudioIcon name="eye"/></button></ActionTooltip>{record.can_adjust&&<ActionTooltip label={t('adjust')}><button className="icon-button" aria-label={t('adjust')+' '+record.code} onClick={()=>{setCustomerDrawer(false);void open(record,true);}}><StudioIcon name="edit"/></button></ActionTooltip>}</div></div></article>)}</div>
   {customerDetail.records.length===0&&<p className="usage-muted">{t('empty')}</p>}
   <div className="usage-inner-pagination"><span>{num(customerDetail.records_pagination.total)} {t('records')}</span><PageBoundaryButton edge="first" page={customerDetail.records_pagination.page} pages={customerDetail.records_pagination.pages} onNavigate={target=>showCustomer(customerDetail.customer,'history',target)} className="icon-button" disabled={customerBusy}/><button className="icon-button" disabled={customerBusy||customerDetail.records_pagination.page<=1} aria-label={t('previous')} onClick={()=>showCustomer(customerDetail.customer,'history',customerDetail.records_pagination.page-1)}><StudioIcon name="back"/></button><small>{num(customerDetail.records_pagination.page)} / {num(customerDetail.records_pagination.pages)}</small><button className="icon-button" disabled={customerBusy||customerDetail.records_pagination.page>=customerDetail.records_pagination.pages} aria-label={t('next')} onClick={()=>showCustomer(customerDetail.customer,'history',customerDetail.records_pagination.page+1)}><StudioIcon name="arrow"/></button><PageBoundaryButton edge="last" page={customerDetail.records_pagination.page} pages={customerDetail.records_pagination.pages} onNavigate={target=>showCustomer(customerDetail.customer,'history',target)} className="icon-button" disabled={customerBusy}/></div>
  </section>}
 </div><footer className="usage-dialog-footer"><button className="media-button" onClick={()=>setCustomerDrawer(false)}>{t('close')}</button></footer></>}
 </MediaDialog>
 <MediaDialog open={!!video} onClose={()=>setVideo(null)} title={video?.title??t('recentUsage')} headerIcon="play" animated className="usage-video-dialog">{video&&<div className="usage-video-player"><video controls autoPlay playsInline preload="metadata" src={video.video_url} poster={video.thumbnail??undefined} onError={()=>setVideoError(true)}/>{videoError&&<p role="alert"><StudioIcon name="alert"/>{t('videoUnavailable')}</p>}</div>}</MediaDialog>

 <MediaDialog open={drawer} onClose={()=>setDrawer(false)} title={t('details')} headerIcon="layers" headerDetail={detail?.code} animated className="usage-drawer">
 {detail&&<><div className="usage-dialog-body"><div className="usage-detail-overview"><User row={detail}/><Badge row={detail}/></div>
 <section className="usage-detail-section"><h3><StudioIcon name="play"/>{detail.project.title}</h3><div className="usage-detail-line"><span>{detail.code}</span><Package row={detail}/></div><small>{date(detail.period_start)} – {date(detail.period_end)}</small>{detail.run_id&&<p className="usage-run-id">{detail.run_id}</p>}
 {detail.kind==='processing'?<div className="usage-detail-totals">{[['original',detail.charged_seconds,detail.charged_videos],['returned',detail.returned_seconds,detail.returned_videos],['remaining',detail.charged_seconds-detail.returned_seconds,detail.charged_videos-detail.returned_videos]].map(([key,seconds,videos])=><div key={key}><small>{t(String(key))}</small><strong>{num(Number(seconds)/60)} {t('minutes')}</strong><span>{num(Number(videos))} {t('videos')}</span></div>)}</div>:<div className="usage-detail-line"><span>{t('storageInUse')}</span><strong>{size(detail.storage_bytes)}</strong></div>}
 {detail.reserved_seconds>0&&<p className="usage-reservation"><StudioIcon name="clock"/>{t('reserved')}: {num(detail.reserved_seconds/60)} {t('minutes')} · {num(detail.reserved_videos)} {t('videos')}</p>}
 {detail.runtime_seconds!==null&&<><div className="usage-detail-line"><span>{t('runtime')}</span><strong>{num(detail.runtime_seconds)} {t('seconds')}</strong></div><small>{t('runtimeHint')}</small></>}
 </section>
 {detail.definition&&<section className="usage-detail-section"><h3><StudioIcon name="layers"/>{t('periodBalances')}</h3><div className="usage-balance-table"><span/>{['allowance','used','reserved','available'].map(k=><small key={k}>{t(k)}</small>)}<span>{t('minutes')}</span><strong>{num((detail.definition.included_seconds??(detail.definition.included_minutes??0)*60)/60)}</strong><strong>{num(detail.balances.seconds/60)}</strong><strong>{num(detail.balances.reserved_seconds/60)}</strong><strong>{num(Math.max(0,(detail.definition.included_seconds??(detail.definition.included_minutes??0)*60)-detail.balances.seconds-detail.balances.reserved_seconds)/60)}</strong><span>{t('videos')}</span><strong>{detail.definition.video_limit??t('noLimit')}</strong><strong>{num(detail.balances.videos)}</strong><strong>{num(detail.balances.reserved_videos)}</strong><strong>{detail.definition.video_limit===null?t('noLimit'):num(Math.max(0,(detail.definition.video_limit??0)-detail.balances.videos-detail.balances.reserved_videos))}</strong></div></section>}
 {detail.current_period&&<section className="usage-detail-section"><h3><StudioIcon name="calendar"/>{t('currentPeriod')}</h3><div className="usage-detail-line"><strong>{detail.current_period.package}</strong><span>{t(detail.current_period.status)}</span></div><small>{date(detail.current_period.starts_at)} – {date(detail.current_period.ends_at)}</small></section>}
 <section className="usage-detail-section"><h3><StudioIcon name="database"/>{t('files')}</h3>{detail.files.length?detail.files.map((file,i)=><div key={i} className="usage-file"><span><strong>{file.name}</strong><small>{file.category} · {t(file.state==='deleted'?'deleted':'stored')}</small></span><strong>{size(file.bytes)}</strong></div>):<small>{t('noFiles')}</small>}</section>
 <section className="usage-detail-section"><h3><StudioIcon name="clock"/>{t('history')}</h3><ol className="usage-timeline">{detail.events.map(event=><li key={event.id}><strong>{t(event.kind)}</strong><small>{event.actor_name??t('system')} · {date(event.created_at,true)}</small><p>{Object.entries(event.changes).map(([key,value])=>(key==='bytes'?size(Math.abs(value))+(value<0?' −':' +'):num(value)+' '+t(key==='videos'?'videos':'seconds'))).join(' · ')}</p>{event.reason&&<p>{event.reason}</p>}</li>)}</ol></section>
 </div><footer className="usage-dialog-footer">{customerDetail&&<button className="media-button" onClick={()=>{setDrawer(false);void showCustomer(customerDetail.customer,"history");}}><StudioIcon name="back"/>{t("usageHistory")}</button>}<button className="media-button" onClick={()=>setDrawer(false)}>{t('close')}</button>{detail.can_adjust&&<button className="media-button primary" onClick={()=>{setDrawer(false);setAdjustment(detail);}}><StudioIcon name="edit"/>{t('adjust')}</button>}</footer></>}
 </MediaDialog>
 <MediaDialog open={!!adjustment} onClose={()=>setAdjustment(null)} title={t('adjust')} headerIcon="edit" headerDetail={adjustment?.code} animated className="usage-adjust-dialog">
 {adjustment&&<Adjustment key={adjustment.id+':'+adjustment.revision} row={adjustment} num={num} onClose={()=>setAdjustment(null)} onSaved={()=>{setAdjustment(null);void reload();}}/>}
 </MediaDialog>
 </AppLayout>;
}
function Adjustment({row,num,onClose,onSaved}:{row:UsageDetails;num:(n:number)=>string;onClose:()=>void;onSaved:()=>void}){
 const {t}=useUsageText();const toast=useToast();const [seconds,setSeconds]=useState(''),[videos,setVideos]=useState(''),[reason,setReason]=useState(''),[busy,setBusy]=useState(false),[errors,setErrors]=useState<Record<string,string[]>>({});
 const requestId=useRef(crypto.randomUUID());const maxSeconds=row.charged_seconds-row.returned_seconds,maxVideos=row.charged_videos-row.returned_videos;
 function invalid(fields:Record<string,string[]>){setErrors(fields);toast.error(Object.values(fields).flat()[0]??t('error'));requestAnimationFrame(()=>document.querySelector<HTMLElement>('.usage-adjust-dialog [aria-invalid="true"]')?.focus());}
 async function submit(e:FormEvent){e.preventDefault();if(busy)return;const fields:Record<string,string[]>={};const s=Number(seconds||0),v=Number(videos||0);
  if(!Number.isInteger(s)||s<0||s>maxSeconds||s+v<=0)fields.seconds=[t('amountRequired')];
  if(!Number.isInteger(v)||v<0||v>maxVideos)fields.videos=[t('amountRequired')];
  if(!reason.trim())fields.reason=[t('reasonRequired')];if(Object.keys(fields).length){invalid(fields);return;}
  setBusy(true);setErrors({});
  try{await mediaApi(row.adjust_url,'POST',{revision:row.revision,seconds:s,videos:v,reason:reason.trim(),request_id:requestId.current});toast.success(t('saved'));onSaved();}
  catch(error){invalid(error instanceof MediaApiError&&Object.keys(error.fields).length?error.fields:{reason:[t('error')]});}finally{setBusy(false);}
 }
 return <form onSubmit={submit} noValidate className="usage-adjust-form"><div className="usage-dialog-body"><User row={row}/><p className="usage-correction-hint"><StudioIcon name="info"/>{t('correctionHint')}</p><div className="usage-adjust-grid">
 <label>{t('returnSeconds')}<input id="usage-seconds" type="number" min="0" step="1" value={seconds} onChange={e=>setSeconds(e.target.value)} aria-invalid={!!errors.seconds}/><small>{t('remaining')}: {num(maxSeconds)} {t('seconds')}</small>{errors.seconds&&<small className="usage-error">{errors.seconds[0]}</small>}</label>
 <label>{t('returnVideos')}<input type="number" min="0" step="1" value={videos} onChange={e=>setVideos(e.target.value)} aria-invalid={!!errors.videos}/><small>{t('remaining')}: {num(maxVideos)} {t('videos')}</small>{errors.videos&&<small className="usage-error">{errors.videos[0]}</small>}</label></div>
 <div className="usage-before-after"><div><small>{t('before')}</small><strong>{num(maxSeconds/60)} {t('minutes')} · {num(maxVideos)} {t('videos')}</strong></div><StudioIcon name="arrow"/><div><small>{t('after')}</small><strong>{num(Math.max(0,maxSeconds-Number(seconds||0))/60)} {t('minutes')} · {num(Math.max(0,maxVideos-Number(videos||0)))} {t('videos')}</strong></div></div>
 <label className="usage-reason"><span>{t('reason')} <b>*</b></span><textarea maxLength={500} rows={4} value={reason} onChange={e=>setReason(e.target.value)} aria-invalid={!!errors.reason}/>{errors.reason&&<small className="usage-error">{errors.reason[0]}</small>}<small>{reason.length} / 500</small></label>
 </div><footer className="usage-dialog-footer"><button type="button" className="media-button" disabled={busy} onClick={onClose}>{t('cancel')}</button><button className="media-button primary" type="submit" disabled={busy}><StudioIcon name={busy?'loader':'save'}/>{t('save')}</button></footer></form>;
}
