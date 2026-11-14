import {PageBoundaryButton} from '@/components/page-boundary-button';
import {Head,Link} from '@inertiajs/react';
import {useEffect,useRef,useState} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {MediaDialog,SelectField} from '@/components/media-workflow';
import {DatePicker} from '@/components/date-picker';
import {useAuditText} from '@/lib/audit-i18n';
import {useDisplayDate} from '@/lib/platform-display';
import {useToast} from '@/lib/toast';
import {useLoading} from '@/lib/loading';
import {mediaApi} from '@/lib/media-api';
import '../../../css/audit-security.css';

type Entry={id:number;code:string;category:string;action:string;result:string;sensitive:boolean;actor:{id:number|null;name:string;email:string|null;kind:string};target:string;target_type:string;target_id:string|null;ip:string|null;occurred_at:string;detail_url:string};
type Detail=Entry&{details:Record<string,unknown>;source:string;source_id:string;browser:string|null;request_id:string|null;related_url:string|null;recorded_at:string};
type Filters={q:string;category:string;actor:string;result:string;sensitive:boolean;dates:string;from:string;to:string;sort:string;per_page:number};
type Data={items:Entry[];summary:{total:number;successful:number;failed:number;sensitive:number};tabs:Record<string,number>;filters:Filters;today:string;pagination:{page:number;pages:number;total:number;from:number|null;to:number|null};endpoints:{index:string;export:string}};
const categoryIcons:Record<string,IconName>={accounts:'users',packages:'layers',payments:'credit-card',settings:'settings',security:'shield',operations:'jobs'};
const tabs=['all','accounts','packages','payments','settings','security'] as const;
const value=(v:unknown):string=>v===null||v===undefined?'—':typeof v==='boolean'?(v?'ON':'OFF'):typeof v==='object'?JSON.stringify(v):String(v);
const human=(v:string)=>v.replaceAll('_',' ').replaceAll('.',' · ').replace(/^./,c=>c.toUpperCase());
function Result({entry}:{entry:Entry}){const {t}=useAuditText();return <span className={'as-result is-'+entry.result}><StudioIcon name={entry.result==='success'?'check':entry.result==='blocked'?'shield':'circle-x'}/>{t(entry.result==='success'?'success':entry.result==='blocked'?'blocked':'failure')}</span>;}

export default function AuditSecurity({audit:initial}:{audit:Data}){
 const {t,action,locale}=useAuditText(),date=useDisplayDate(),toast=useToast(),{begin}=useLoading();
 const [data,setData]=useState(initial),[filters,setFilters]=useState(initial.filters),[page,setPage]=useState(initial.pagination.page);
 const [busy,setBusy]=useState(false),[exporting,setExporting]=useState(false),[stale,setStale]=useState(false),[detail,setDetail]=useState<Detail|null>(null),[drawer,setDrawer]=useState(false),[copied,setCopied]=useState(false);
 const request=useRef<AbortController|null>(null),detailRequest=useRef<AbortController|null>(null),sequence=useRef(0),alive=useRef(true),last=useRef(JSON.stringify([filters,page])),copyTimer=useRef<ReturnType<typeof setTimeout>|null>(null);
 const number=(n:number)=>new Intl.NumberFormat(locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US').format(n);
 const query=(f:Filters,p:number)=>new URLSearchParams({...Object.fromEntries(Object.entries(f).map(([k,v])=>[k,String(v)])),sensitive:f.sensitive?'1':'0',page:String(p)}).toString();
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;request.current?.abort();detailRequest.current?.abort();if(copyTimer.current)clearTimeout(copyTimer.current);};},[]);
 async function reload(f=filters,p=page,foreground=false){
  if(f.dates==='custom'&&(!f.from||!f.to||f.to<f.from))return;
  request.current?.abort();const abort=new AbortController();request.current=abort;const seq=++sequence.current;setBusy(true);const done=foreground?begin():()=>{};
  try{const result=await mediaApi<Data>(data.endpoints.index+'?'+query(f,p),'GET',undefined,abort.signal);if(alive.current&&seq===sequence.current){setData(result);setStale(false);if(result.pagination.page!==p){last.current=JSON.stringify([f,result.pagination.page]);setPage(result.pagination.page);}if(foreground)toast.success(t('refreshed'));}}
  catch(error){if(!abort.signal.aborted&&alive.current){setStale(true);toast.error(t('error'));}}
  finally{done();if(alive.current&&seq===sequence.current)setBusy(false);}
 }
 useEffect(()=>{const key=JSON.stringify([filters,page]);if(key===last.current)return;const timer=setTimeout(()=>{last.current=key;void reload(filters,page);},280);return()=>clearTimeout(timer);},[filters,page]);
 function change<K extends keyof Filters>(key:K,v:Filters[K]){setFilters(old=>({...old,[key]:v}));setPage(1);}
 function clear(){setFilters({...initial.filters,q:'',category:'',actor:'',result:'',sensitive:false,dates:'30',from:'',to:'',sort:'newest'});setPage(1);}
 async function open(entry:Entry){detailRequest.current?.abort();const abort=new AbortController();detailRequest.current=abort;const done=begin();try{const result=await mediaApi<Detail>(entry.detail_url,'GET',undefined,abort.signal);if(alive.current&&!abort.signal.aborted){setDetail(result);setDrawer(true);setCopied(false);}}catch{if(!abort.signal.aborted)toast.error(t('error'));}finally{done();}}
 async function exportCsv(){if(exporting)return;setExporting(true);try{const response=await fetch(data.endpoints.export+'?'+query(filters,1),{credentials:'same-origin',headers:{Accept:'text/csv'}});if(!response.ok||!response.headers.get('Content-Type')?.includes('text/csv'))throw Error();const blob=await response.blob(),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='audit-'+data.today+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);toast.success(t('exported'));}catch{toast.error(t('exportError'));}finally{if(alive.current)setExporting(false);}}
 async function copy(){if(!detail)return;try{await navigator.clipboard.writeText(detail.code);setCopied(true);if(copyTimer.current)clearTimeout(copyTimer.current);copyTimer.current=setTimeout(()=>setCopied(false),2000);}catch{toast.error(t('error'));}}
 const options=(keys:string[],labels:string[])=>keys.map((key,i)=>({value:key,label:labels[i]}));
 const periodLabel=filters.dates==='1'?t('day'):filters.dates==='7'?t('week'):filters.dates==='30'?t('month'):filters.dates==='all'?t('allDates'):t('custom');
 const pages=Array.from(new Set([1,...[page-1,page,page+1].filter(n=>n>=1&&n<=data.pagination.pages),data.pagination.pages])).sort((a,b)=>a-b);
 const before=(detail?.details.before??{}) as Record<string,unknown>,after=(detail?.details.after??{}) as Record<string,unknown>;
 const changed=Array.from(new Set([...Object.keys(before),...Object.keys(after)])).filter(k=>value(before[k])!==value(after[k]));
 const fields=Array.isArray(detail?.details.fields)?detail.details.fields as string[]:[];
 return <AppLayout area="admin" frame="preferences" adminPage="audit" breadcrumbLabel={t('title')}><Head title={t('title')}/>
 <section className="as-page">
  <header className="as-header"><div className="as-heading"><span className="as-title-icon"><StudioIcon name="shield"/></span><div><h1>{t('title')}</h1><p>{t('description')}</p></div></div><div className="as-header-actions"><button className="media-button" onClick={()=>void exportCsv()} disabled={exporting}><StudioIcon name={exporting?'loader':'download'}/>{t('export')}</button><button className="media-button primary" onClick={()=>void reload(filters,page,true)} disabled={busy}><StudioIcon name={busy?'loader':'refresh'}/>{t('refresh')}</button></div></header>
  <div className="as-summary">{([
   ['total','activity','total','primary'],['successful','check','successful','success'],['failed','circle-x','failed','error'],['sensitive','shield','sensitive','warning'],
  ] as const).map(([key,icon,label,tone])=><button type="button" className={'as-stat is-'+tone} key={key} aria-pressed={key==='sensitive'?filters.sensitive:key==='successful'?filters.result==='success':key==='failed'?filters.result==='unsuccessful':!filters.result&&!filters.sensitive} onClick={()=>{setFilters(f=>({...f,result:key==='successful'?'success':key==='failed'?'unsuccessful':'',sensitive:key==='sensitive'}));setPage(1);}}><span className="as-stat-icon"><StudioIcon name={icon}/></span><span><small>{t(label)}</small><strong>{number(data.summary[key])}</strong><p>{key==='total'?t('selected')+': '+periodLabel:key==='sensitive'?t('inPeriod'):(data.summary.total?Math.round(data.summary[key]/data.summary.total*1000)/10:0)+'% '+t('ofEvents')}</p></span></button>)}</div>
  <nav className="as-tabs" aria-label={t('all')}>{tabs.map(key=><button key={key} className={filters.category===(key==='all'?'':key)?'is-active':''} aria-current={filters.category===(key==='all'?'':key)?'page':undefined} onClick={()=>change('category',key==='all'?'':key)}>{t(key)}<span>{number(key==='all'?Object.values(data.tabs).reduce((a,b)=>a+b,0):data.tabs[key]??0)}</span></button>)}</nav>
  <div className="as-records" aria-busy={busy}>
   <div className="as-toolbar"><label className="as-search"><StudioIcon name="search"/><input type="search" value={filters.q} maxLength={100} onChange={e=>change('q',e.target.value)} placeholder={t('search')} aria-label={t('search')}/></label>
    <SelectField label={t('actor')} value={filters.actor} options={options(['','admin','customer','system','unknown'],[t('actor')+': '+t('any'),t('admin'),t('customer'),t('system'),t('unknown')])} onChange={v=>change('actor',v)}/>
    <SelectField label={t('result')} value={filters.result} options={options(['','success','failed','blocked','unsuccessful'],[t('result')+': '+t('any'),t('success'),t('failure'),t('blocked'),t('failed')])} onChange={v=>change('result',v)}/>
    <SelectField label={t('period')} value={filters.dates} options={options(['1','7','30','all','custom'],[t('day'),t('week'),t('month'),t('allDates'),t('custom')])} onChange={v=>{setFilters(f=>({...f,dates:v,from:f.from||data.today,to:f.to||data.today}));setPage(1);}}/>
    <SelectField label={t('sort')} value={filters.sort} options={options(['newest','oldest'],[t('sort')+': '+t('newest'),t('sort')+': '+t('oldest')])} onChange={v=>change('sort',v)}/>
    <ActionTooltip label={t('refresh')}><button className="media-button as-icon" aria-label={t('refresh')} onClick={()=>void reload(filters,page,true)} disabled={busy}><StudioIcon name={busy?'loader':'refresh'}/></button></ActionTooltip><button className="media-button as-clear" onClick={clear}>{t('clear')}</button>
   </div>
   {filters.dates==='custom'&&<div className="as-custom"><DatePicker id="audit-from" label={t('from')} value={filters.from} today={data.today} max={filters.to||undefined} locale={locale} onChange={v=>change('from',v)}/><DatePicker id="audit-to" label={t('to')} value={filters.to} today={data.today} min={filters.from||undefined} locale={locale} onChange={v=>change('to',v)}/><small>{t('utc')}</small></div>}
   {filters.sensitive&&<div className="as-active-filter"><StudioIcon name="shield"/>{t('sensitiveOnly')}<button aria-label={t('clear')} onClick={()=>change('sensitive',false)}><StudioIcon name="close"/></button></div>}
   {stale&&<p className="as-stale" role="alert"><StudioIcon name="alert"/>{t('stale')}</p>}
   {data.items.length?<div className="as-table-scroll"><table className="as-table"><thead><tr>{['#',t('date'),t('actor'),t('action'),t('target'),t('result'),t('ip'),t('actions')].map((label,i)=><th key={i}>{label}</th>)}</tr></thead><tbody>{data.items.map((entry,index)=><tr key={entry.id}>
    <td className="as-row-index">{number((data.pagination.from??1)+index)}</td><td data-label={t('date')} className="as-date"><time>{date(entry.occurred_at,true)}</time><small>{entry.code}</small></td>
    <td data-label={t('actor')}><div className={'as-actor is-'+entry.actor.kind}><span>{entry.actor.kind==='unknown'?<StudioIcon name="alert"/>:entry.actor.name.split(' ').map(w=>w[0]).slice(0,2).join('')}</span><div><strong title={entry.actor.name}>{entry.actor.kind==='system'?t('system'):entry.actor.kind==='unknown'?t('unknown'):entry.actor.name}</strong><small title={entry.actor.email??''}>{entry.actor.email??'—'}</small></div></div></td>
    <td data-label={t('action')}><span className="as-event"><StudioIcon name={categoryIcons[entry.category]??'activity'}/>{action(entry.action)}</span>{entry.sensitive&&<small className="as-sensitive"><StudioIcon name="shield"/>{t('sensitive')}</small>}</td>
    <td data-label={t('target')} className="as-target"><strong title={entry.target}>{entry.target}</strong><small>{t(entry.category as typeof tabs[number])}{entry.target_id?' · #'+entry.target_id:''}</small></td>
    <td data-label={t('result')}><Result entry={entry}/></td><td data-label={t('ip')} className="as-ip">{entry.ip??'—'}</td>
    <td className="as-action-cell"><ActionTooltip label={t('view')}><button className="media-button as-icon" aria-label={t('view')} onClick={()=>void open(entry)}><StudioIcon name="eye"/></button></ActionTooltip></td>
   </tr>)}</tbody></table></div>:<div className="as-empty"><StudioIcon name="shield"/><h2>{filters.q||filters.category||filters.actor||filters.result||filters.sensitive?t('noMatch'):t('empty')}</h2><p>{t('emptyHint')}</p><button className="media-button" onClick={clear}>{t('clear')}</button></div>}
   <footer className="as-table-footer"><p>{t('showing')} {number(data.pagination.from??0)}–{number(data.pagination.to??0)} {t('of')} {number(data.pagination.total)} {t('events')}</p><div className="as-pagination"><SelectField label="Rows per page" value={String(filters.per_page)} options={[10,25,50].map(n=>({value:String(n),label:String(n)}))} onChange={v=>change('per_page',Number(v))}/><PageBoundaryButton edge="first" page={page} pages={data.pagination.pages} onNavigate={target=>setPage(target)} className="media-button as-icon"/><button className="media-button as-icon" aria-label={t('previous')} disabled={page<=1} onClick={()=>setPage(p=>p-1)}><StudioIcon name="back"/></button>{pages.map((n,i)=><span className="as-page-number" key={n}>{i>0&&n>pages[i-1]+1&&<span>…</span>}<button className={'media-button as-icon'+(n===page?' primary':'')} aria-label={'Page '+n} aria-current={n===page?'page':undefined} onClick={()=>setPage(n)}>{number(n)}</button></span>)}<button className="media-button as-icon" aria-label={t('next')} disabled={page>=data.pagination.pages} onClick={()=>setPage(p=>p+1)}><StudioIcon name="arrow"/></button><PageBoundaryButton edge="last" page={page} pages={data.pagination.pages} onNavigate={target=>setPage(target)} className="media-button as-icon"/></div></footer>
  </div>
  <p className="as-footnote"><StudioIcon name="shield"/>{t('readOnly')}</p>
 </section>
 <MediaDialog open={drawer} onClose={()=>setDrawer(false)} title={t('details')} headerIcon="shield" headerDetail={detail?.code} animated className="as-panel">
 {detail&&<><div className="as-panel-body">
  <section className="as-detail-hero"><span className="as-detail-emblem"><StudioIcon name={categoryIcons[detail.category]??'activity'}/></span><div><h2>{action(detail.action)}</h2><span>{t(detail.category as typeof tabs[number])}</span></div><Result entry={detail}/></section>
  <div className="as-code"><code>{detail.code}</code><ActionTooltip label={copied?t('copied'):t('copy')}><button className="media-button as-icon" aria-label={t('copy')} onClick={()=>void copy()}><StudioIcon name={copied?'check':'copy'}/></button></ActionTooltip>{copied&&<span role="status">{t('copied')}</span>}</div>
  <section className="as-detail-section"><h3><StudioIcon name="info"/>{t('summary')}</h3><dl className="as-detail-list"><dt>{t('date')}</dt><dd>{date(detail.occurred_at,true)}</dd><dt>{t('actor')}</dt><dd>{detail.actor.name}<small>{detail.actor.email??t(detail.actor.kind as 'admin'|'system'|'customer'|'unknown')}</small></dd><dt>{t('target')}</dt><dd>{detail.target}</dd><dt>{t('sourceId')}</dt><dd>{detail.source} #{detail.source_id}</dd>{detail.sensitive&&<><dt>{t('sensitive')}</dt><dd className="as-sensitive"><StudioIcon name="shield"/>{t('sensitive')}</dd></>}</dl>
  {Boolean(detail.details.reason)&&<div className="as-reason"><h4>{t('reason')}</h4><p>{value(detail.details.reason)}</p></div>}
  </section>
  <section className="as-detail-section"><h3><StudioIcon name="layers"/>{t('changes')}</h3>
   {changed.length?<div className="as-diff-scroll"><table className="as-diff"><thead><tr><th>{t('field')}</th><th>{t('before')}</th><th>{t('after')}</th></tr></thead><tbody>{changed.map(key=><tr key={key}><th>{human(key)}</th><td>{value(before[key])}</td><td>{value(after[key])}</td></tr>)}</tbody></table></div>:<p className="as-muted">{t('noChanges')}</p>}
   {fields.length>0&&<div className="as-fields"><h4>{t('recordedFields')}</h4>{fields.map(field=><span key={field}>{human(field)}</span>)}</div>}
   <dl className="as-detail-list">{Object.entries(detail.details).filter(([key])=>!['before','after','reason','fields','notice'].includes(key)).map(([key,v])=><div className="as-detail-pair" key={key}><dt>{human(key)}</dt><dd>{value(v)}</dd></div>)}</dl>
  </section>
  <section className="as-detail-section"><h3><StudioIcon name="shield"/>{t('context')}</h3><dl className="as-detail-list"><dt>{t('ip')}</dt><dd>{detail.ip??'?'}</dd><dt>{t('browser')}</dt><dd>{detail.browser??'?'}</dd><dt>{t('request')}</dt><dd><code>{detail.request_id??'?'}</code></dd><dt>{t('recorded')}</dt><dd>{date(detail.recorded_at,true)}</dd></dl><p className="as-muted">{t('masked')}</p><p className="as-muted">{t('historyHint')}</p></section>
 </div><footer className="as-panel-footer"><button className="media-button" onClick={()=>setDrawer(false)}>{t('close')}</button>{detail.related_url?<Link className="media-button primary" href={detail.related_url}>{t('related')}<StudioIcon name="arrow"/></Link>:<small>{t('unavailable')}</small>}</footer></>}
 </MediaDialog>
 </AppLayout>;
}
