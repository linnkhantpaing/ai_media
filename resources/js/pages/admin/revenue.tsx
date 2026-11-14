import {Head,Link} from '@inertiajs/react';
import {useEffect,useRef,useState} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {MediaDialog,SelectField} from '@/components/media-workflow';
import {DatePicker} from '@/components/date-picker';
import {ActionTooltip} from '@/components/action-tooltip';
import {PageBoundaryButton} from '@/components/page-boundary-button';
import {RevenueShare,RevenueTrend,revenueCurrencies} from '@/components/revenue-charts';
import {useRevenueText} from '@/lib/revenue-i18n';
import {useDisplayDate} from '@/lib/platform-display';
import {mediaApi} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import {useLoading} from '@/lib/loading';
import type {RevenueCurrency,RevenueData,RevenueDetail,RevenueEntry,RevenueFilters} from '@/types/revenue';
import '../../../css/revenue.css';

export default function Revenue({revenue}:{revenue:RevenueData}){
 const {t,locale,money,number}=useRevenueText(),date=useDisplayDate(),toast=useToast(),{begin}=useLoading();
 const [data,setData]=useState(revenue),[filters,setFilters]=useState(revenue.filters),[busy,setBusy]=useState(false),[stale,setStale]=useState(false);
 const [chartCurrency,setChartCurrency]=useState<RevenueCurrency>(revenue.filters.currency==='all'?'MMK':revenue.filters.currency);
 const [view,setView]=useState<'activity'|'packages'|'methods'>('activity'),[breakdownPage,setBreakdownPage]=useState(1);
 const [detail,setDetail]=useState<RevenueDetail|null>(null),[drawer,setDrawer]=useState(false),[exporting,setExporting]=useState(false),[copied,setCopied]=useState<string|null>(null);
 const req=useRef<AbortController|null>(null),openReq=useRef<AbortController|null>(null),exportReq=useRef<AbortController|null>(null),seq=useRef(0),alive=useRef(true),pending=useRef(false),last=useRef(JSON.stringify(filters)),copyTimer=useRef<ReturnType<typeof setTimeout>|null>(null);
 const query=(f:RevenueFilters)=>new URLSearchParams(Object.fromEntries(Object.entries(f).map(([k,v])=>[k,String(v)]))).toString();
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;req.current?.abort();openReq.current?.abort();exportReq.current?.abort();if(copyTimer.current)clearTimeout(copyTimer.current);};},[]);
 async function reload(f=filters,notify=false,silent=false){
  if(f.dates==='custom'&&(!f.from||!f.to||f.to<f.from))return;
  if(silent&&pending.current)return;
  req.current?.abort();const abort=new AbortController();req.current=abort;const serial=++seq.current;pending.current=true;if(!silent)setBusy(true);
  try{const result=await mediaApi<RevenueData>(data.endpoints.index+'?'+query(f),'GET',undefined,abort.signal);if(alive.current&&serial===seq.current){setData(result);setStale(false);if(result.pagination.page!==f.page){const corrected={...f,page:result.pagination.page};last.current=JSON.stringify(corrected);setFilters(corrected);}if(notify)toast.success(t('refreshed'));}}
  catch{if(!abort.signal.aborted&&alive.current){setStale(true);if(!silent)toast.error(t('error'));}}
  finally{if(alive.current&&serial===seq.current){setBusy(false);pending.current=false;}}
 }
 useEffect(()=>{const key=JSON.stringify(filters);if(key===last.current)return;const timer=setTimeout(()=>{last.current=key;void reload(filters);},280);return()=>clearTimeout(timer);},[filters]);
 useEffect(()=>{const timer=setInterval(()=>{if(!document.hidden)void reload(filters,false,true);},60000);return()=>clearInterval(timer);},[filters]);
 function change<K extends keyof RevenueFilters>(key:K,value:RevenueFilters[K]){if(key==='currency'&&value!=='all')setChartCurrency(value as RevenueCurrency);setFilters(f=>{const next={...f,[key]:value,...(key==='page'?{}:{page:1})};if(key==='currency'&&value==='all'&&next.sort==='amount')next.sort='newest';return next;});if(key!=='page')setBreakdownPage(1);}
 function clear(){setFilters({...revenue.filters,currency:'all',dates:'30',from:'',to:'',package:'',method:'',purpose:'',type:'',q:'',sort:'newest',group:'auto',page:1});setChartCurrency('MMK');setBreakdownPage(1);}
 async function open(entry:RevenueEntry){openReq.current?.abort();const abort=new AbortController();openReq.current=abort;const done=begin();try{const result=await mediaApi<RevenueDetail>(entry.detail_url,'GET',undefined,abort.signal);if(alive.current&&!abort.signal.aborted){setDetail(result);setDrawer(true);}}catch{if(alive.current&&!abort.signal.aborted)toast.error(t('error'));}finally{done();}}
 async function copy(value:string){try{await navigator.clipboard.writeText(value);setCopied(value);if(copyTimer.current)clearTimeout(copyTimer.current);copyTimer.current=setTimeout(()=>setCopied(null),2000);}catch{/* Clipboard access is optional. */}}
 async function exportCsv(){if(exporting)return;const abort=new AbortController();exportReq.current=abort;setExporting(true);try{const response=await fetch(data.endpoints.export+'?'+query(filters),{credentials:'same-origin',headers:{Accept:'text/csv'},signal:abort.signal});if(!response.ok||!response.headers.get('content-type')?.includes('text/csv'))throw Error('export');const blob=await response.blob();if(!alive.current||abort.signal.aborted)return;const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='zatlan-revenue-'+data.range.from+'-'+data.range.to+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),10000);toast.success(t('exportDone'));}catch{if(alive.current&&!abort.signal.aborted)toast.error(t('exportError'));}finally{if(alive.current)setExporting(false);}}
 const currencies=filters.currency==='all'?revenueCurrencies:[filters.currency];
 const breakdown=view==='packages'?data.packages:data.methods;
 const actualPage=view==='activity'?filters.page:breakdownPage;
 const total=view==='activity'?data.pagination.total:breakdown.length;
 const pages=view==='activity'?data.pagination.pages:Math.max(1,Math.ceil(total/filters.per_page));
 const shownPage=Math.min(actualPage,pages);
 const pageNumbers=[...new Set([1,...[shownPage-1,shownPage,shownPage+1].filter(n=>n>0&&n<=pages),pages])].sort((a,b)=>a-b);
 const navigate=(n:number)=>view==='activity'?change('page',n):setBreakdownPage(n);
 const pageFrom=view==='activity'?data.pagination.from:total?(shownPage-1)*filters.per_page+1:null;
 const pageTo=view==='activity'?data.pagination.to:Math.min(total,shownPage*filters.per_page);
 const rowBreakdown=breakdown.slice((shownPage-1)*filters.per_page,shownPage*filters.per_page);
 return <AppLayout area="admin" frame="preferences" adminPage="revenue" breadcrumbLabel={t('title')}><Head title={t('title')}/>
 <section className="rv-page">
  <header className="rv-heading"><div><span className="rv-title-icon"><StudioIcon name="chart"/></span><div><h1>{t('title')}</h1><p>{t('description')}</p></div></div><div className="rv-heading-actions"><button className="media-button" disabled={exporting} onClick={()=>void exportCsv()}><StudioIcon name={exporting?'loader':'download'}/>{t('export')}</button><button className="media-button primary" disabled={busy} onClick={()=>void reload(filters,true)}><StudioIcon name={busy?'loader':'refresh'}/>{t('refresh')}</button></div></header>
  <div className="rv-report-filters"><nav className="rv-currency-tabs" aria-label={t('currency')}>{(['all',...revenueCurrencies] as const).map(c=><button key={c} className={filters.currency===c?'is-active':''} aria-pressed={filters.currency===c} onClick={()=>change('currency',c)}>{c==='all'?t('all'):c}</button>)}</nav>
   <div className="rv-filter-controls"><SelectField label={t('dates')} value={filters.dates} options={['1','7','30','month','last_month','custom','all'].map((v,i)=>({value:v,label:t(['day','week','month','thisMonth','lastMonth','custom','allTime'][i])}))} onChange={v=>{setFilters(f=>({...f,dates:v,from:f.from||data.range.from,to:f.to||data.range.to,page:1}));setBreakdownPage(1);}}/>
   <SelectField label={t('packageFilter')} value={String(filters.package)} options={[{value:'',label:t('packageFilter')},...data.options.packages.map(p=>({value:String(p.id),label:p.name,detail:p.code}))]} onChange={v=>change('package',v)}/>
   <SelectField label={t('methodFilter')} value={String(filters.method)} options={[{value:'',label:t('methodFilter')},...data.options.methods.map(m=>({value:String(m.id),label:m.name,detail:m.currency}))]} onChange={v=>change('method',v)}/>
   <SelectField label={t('purposeFilter')} value={filters.purpose} options={[{value:'',label:t('purposeFilter')},...['new','renewal','change'].map(v=>({value:v,label:t(v)}))]} onChange={v=>change('purpose',v)}/>
   <button className="media-button rv-clear" onClick={clear}><StudioIcon name="filter"/>{t('clear')}</button></div>
  </div>
  {filters.dates==='custom'&&<div className="rv-custom-range"><DatePicker id="rv-from" label={t('from')} value={filters.from} max={filters.to||data.today} today={data.today} locale={locale} onChange={v=>change('from',v)}/><DatePicker id="rv-to" label={t('to')} value={filters.to} min={filters.from} today={data.today} locale={locale} onChange={v=>change('to',v)}/><span>{data.range.timezone}</span></div>}
  {stale&&<p className="rv-stale" role="alert"><StudioIcon name="alert"/>{t('stale')}</p>}
  <div className="rv-stats">{(['gross','refunds','net'] as const).map((key,i)=><article key={key} className={'rv-stat is-'+key}><span className="rv-stat-icon"><StudioIcon name={(['database','back','layers'] as IconName[])[i]}/></span><div><h2>{t(key)}{key!=='refunds'&&<small>{t(key==='gross'?'grossHint':'netHint')}</small>}</h2><dl>{currencies.map(c=><div key={c}><dt>{c}</dt><dd className={data.summary.amounts[c][key]<0?'is-negative':''}>{money(data.summary.amounts[c][key])}</dd></div>)}</dl></div></article>)}
   <article className="rv-counts">{([['payments','receipt'],['refunds','back'],['customers','users']] as const).map(([key,icon])=><div key={key}><StudioIcon name={icon}/><span><small>{t(key==='refunds'?'refundCount':key)}</small><strong>{number(data.summary.counts[key])}</strong></span></div>)}</article>
  </div>
  <div className="rv-basis"><StudioIcon name="info"/><span>{t('basis')}</span><span>{data.range.from} – {data.range.to} · {data.range.timezone}</span></div>
  <div className="rv-charts"><RevenueTrend data={data} currency={chartCurrency} onCurrency={c=>{setChartCurrency(c);if(filters.currency!=='all'&&filters.currency!==c)change('currency',c);}} group={filters.group} onGroup={v=>change('group',v)}/><RevenueShare data={data}/></div>
  <section className="rv-panel rv-transactions" aria-busy={busy}>
   <header><h2><StudioIcon name="receipt"/>{t('activity')}</h2><small>{t('updated')}: {date(data.updated_at,true)}</small></header>
   <div className="rv-table-tools"><nav className="rv-view-tabs" aria-label={t('activity')}>{(['activity','packages','methods'] as const).map((v,i)=><button key={v} aria-pressed={view===v} className={view===v?'is-active':''} onClick={()=>{setView(v);setBreakdownPage(1);}}>{t(['activity','byPackage','byMethod'][i])}</button>)}</nav>{view==='activity'&&<div className="rv-activity-filters"><label className="rv-search"><StudioIcon name="search"/><input type="search" value={filters.q} maxLength={100} aria-label={t('search')} placeholder={t('search')} onChange={e=>change('q',e.target.value)}/></label><SelectField label={t('type')} value={filters.type} options={[{value:'',label:t('allActivity')},{value:'payment',label:t('payments')},{value:'refund',label:t('refundCount')}]} onChange={v=>change('type',v)}/><SelectField label={t('sort')} value={filters.sort} options={['newest','oldest',...(filters.currency==='all'?[]:['amount'])].map(v=>({value:v,label:t(v==='amount'?'amountSort':v)}))} onChange={v=>change('sort',v)}/></div>}</div>
   {(view==='activity'?data.items.length:rowBreakdown.length)>0?<div className="rv-table-wrap">{view==='activity'?<table className="rv-table"><thead><tr><th>#</th>{['recorded','customer','packagePurpose','amount','method','type','status','actions'].map(k=><th key={k}>{t(k)}</th>)}</tr></thead><tbody>{data.items.map((entry,i)=><tr key={entry.id}>
    <td className="rv-row-number">{number((data.pagination.from??1)+i)}</td><td data-label={t('recorded')}><time>{date(entry.occurred_at,true)}</time><div className="rv-reference"><span>{entry.code}</span><ActionTooltip label={copied===entry.code?t('copied'):t('copy')}><button className="icon-button" aria-label={t('copy')} onClick={()=>void copy(entry.code)}><StudioIcon name={copied===entry.code?'check':'copy'}/></button></ActionTooltip></div>{entry.type==='refund'&&<small className="rv-original">{entry.payment_code}</small>}</td>
    <td className="rv-customer-cell"><div className="rv-customer"><span className={'rv-avatar tone-'+i%4}>{entry.customer.name.split(/\s+/).slice(0,2).map(x=>x[0]).join('').toUpperCase()}</span><div><strong>{entry.customer.name}</strong><small>{entry.customer.email}</small></div></div></td>
    <td data-label={t('packagePurpose')}><div className="rv-package"><StudioIcon name="layers"/><span>{entry.package.name}</span><small>v{entry.package.version}</small></div><span className="rv-purpose">{t(entry.purpose)}</span></td>
    <td data-label={t('amount')} className={'rv-amount '+(entry.type==='refund'?'is-outgoing':'')}><span>{entry.type==='refund'?'−':''}{entry.currency} {money(entry.amount_minor)}</span></td>
    <td data-label={t('method')}><span className="rv-method"><StudioIcon name={entry.method.type==='bank'?'bank':'wallet'}/>{entry.method.name}</span></td>
    <td data-label={t('type')}><span className={'rv-badge is-'+entry.type}>{t(entry.type)}</span></td><td data-label={t('status')}><span className="rv-badge is-success"><StudioIcon name="check"/>{t(entry.status)}</span></td>
    <td className="rv-action"><ActionTooltip label={t('view')}><button className="media-button rv-icon" aria-label={t('view')} onClick={()=>void open(entry)}><StudioIcon name="eye"/></button></ActionTooltip></td>
   </tr>)}</tbody></table>:<table className="rv-table rv-breakdown"><thead><tr>{[view==='packages'?'packageName':'method','currency','paymentNumber','gross','refunds','net'].map(k=><th key={k}>{t(k)}</th>)}</tr></thead><tbody>{rowBreakdown.map((r,i)=><tr key={(r.package_id??r.payment_method_id)+'-'+r.currency}><td><span className="rv-method"><StudioIcon name={view==='packages'?'layers':'bank'}/>{r.name}</span></td><td data-label={t('currency')}>{r.currency}</td><td data-label={t('paymentNumber')}>{number(r.payments)}</td>{(['gross','refunds','net'] as const).map(k=><td key={k} data-label={t(k)} className={r[k]<0?'is-negative':''}>{r.currency} {money(r[k])}</td>)}</tr>)}</tbody></table>}</div>:<div className="rv-empty"><StudioIcon name="receipt"/><h3>{t(filters.q||filters.type?'noMatch':'empty')}</h3><p>{t('emptyHint')}</p></div>}
   <footer className="rv-pagination-footer"><p>{t('showing')} {number(pageFrom??0)}–{number(pageTo??0)} {t('of')} {number(total)} {t('records')}</p><nav className="rv-pagination" aria-label={t('activity')}><SelectField label={t('pageSize')} value={String(filters.per_page)} options={[10,25,50].map(n=>({value:String(n),label:String(n)}))} onChange={v=>change('per_page',Number(v))}/><PageBoundaryButton edge="first" page={shownPage} pages={pages} onNavigate={navigate} className="media-button rv-icon"/><ActionTooltip label={t('previous')}><button className="media-button rv-icon" disabled={shownPage<=1} onClick={()=>navigate(shownPage-1)} aria-label={t('previous')}><StudioIcon name="back"/></button></ActionTooltip>{pageNumbers.map((n,i)=><span className="rv-page-number" key={n}>{i>0&&n>pageNumbers[i-1]+1&&<i>…</i>}<button className={'media-button rv-icon'+(shownPage===n?' primary':'')} aria-current={shownPage===n?'page':undefined} onClick={()=>navigate(n)}>{number(n)}</button></span>)}<ActionTooltip label={t('next')}><button className="media-button rv-icon" disabled={shownPage>=pages} aria-label={t('next')} onClick={()=>navigate(shownPage+1)}><StudioIcon name="arrow"/></button></ActionTooltip><PageBoundaryButton edge="last" page={shownPage} pages={pages} onNavigate={navigate} className="media-button rv-icon"/></nav></footer>
  </section>
 </section>
 <MediaDialog open={drawer} onClose={()=>setDrawer(false)} title={t('details')} headerIcon="chart" headerDetail={detail?.code} animated className="rv-detail-panel">
  {detail&&<><div className="rv-detail-body"><div className="rv-detail-customer"><span className="rv-avatar">{detail.customer.name.split(/\s+/).slice(0,2).map(x=>x[0]).join('').toUpperCase()}</span><div><strong>{detail.customer.name}</strong><small>{detail.customer.email}</small></div><span className="rv-badge is-success"><StudioIcon name="check"/>{t(detail.status)}</span></div>
   <div className={'rv-detail-amount is-'+detail.type}><span>{t(detail.type)}</span><strong>{detail.type==='refund'?'−':''}{detail.currency} {money(detail.amount_minor)}</strong><time>{date(detail.occurred_at,true)}</time></div>
   <section><h3><StudioIcon name="receipt"/>{t('reference')}</h3><dl><dt>{t('reference')}</dt><dd>{detail.code}<ActionTooltip label={copied===detail.code?t('copied'):t('copy')}><button className="icon-button" aria-label={t('copy')} onClick={()=>void copy(detail.code)}><StudioIcon name={copied===detail.code?'check':'copy'}/></button></ActionTooltip></dd>{detail.type==='refund'&&<><dt>{t('original')}</dt><dd>{detail.payment_code}</dd></>}<dt>{t('currency')}</dt><dd>{detail.currency}</dd></dl></section>
   <section><h3><StudioIcon name="layers"/>{t('package')}</h3><dl><dt>{t('packageName')}</dt><dd>{detail.package.name}<small>{t('version')} {detail.package.version}</small></dd><dt>{t('purpose')}</dt><dd>{t(detail.purpose)}</dd><dt>{t('receiving')}</dt><dd>{detail.method.name}</dd></dl></section>
   <section><h3><StudioIcon name="wallet"/>{t('lifetime')}</h3><p>{t('lifetimeHint')}</p><dl><dt>{t('gross')}</dt><dd>{detail.currency} {money(detail.lifetime.gross)}</dd><dt>{t('refunds')}</dt><dd>{detail.currency} {money(detail.lifetime.refunds)}</dd><dt>{t('remaining')}</dt><dd>{detail.currency} {money(detail.lifetime.net)}</dd></dl></section>
   {detail.period&&<section><h3><StudioIcon name="calendar"/>{t('period')}</h3><dl><dt>{t('starts_at')}</dt><dd>{date(detail.period.starts_at,true)}</dd><dt>{t('ends_at')}</dt><dd>{date(detail.period.ends_at,true)}</dd></dl></section>}
   <p className="rv-detail-note"><StudioIcon name="info"/>{t('readOnly')}</p>
  </div><footer className="rv-detail-footer"><button className="media-button" onClick={()=>setDrawer(false)}>{t('close')}</button><Link href={detail.related_url} className="media-button primary" onClick={()=>setDrawer(false)}>{t('openPayment')}<StudioIcon name="arrow"/></Link></footer></>}
 </MediaDialog>
 </AppLayout>;
}
