import {Head} from '@inertiajs/react';
import {useEffect,useRef,useState} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {SelectField} from '@/components/media-workflow';
import {DatePicker} from '@/components/date-picker';
import {PageBoundaryButton} from '@/components/page-boundary-button';
import {LibraryMenu} from '@/components/project-library';
import {useAdminNotifications,NoticeBadge,noticeIcons} from '@/components/admin-notifications';
import {useNotificationText} from '@/lib/notification-i18n';
import {useDisplayDate} from '@/lib/platform-display';
import {useToast} from '@/lib/toast';
import {mediaApi} from '@/lib/media-api';
import type {Notice,NoticeFilters,NoticeList} from '@/types/notifications';

export default function Notifications({notifications}:{notifications:NoticeList}){
 const {t}=useNotificationText();
 return <AppLayout area="admin" frame="preferences" adminPage="notifications" breadcrumbLabel={t('title')}><Head title={t('title')}/><NotificationContent initial={notifications}/></AppLayout>;
}
function NotificationContent({initial}:{initial:NoticeList}){
 const {t,body,relative,locale}=useNotificationText(),date=useDisplayDate(),toast=useToast(),center=useAdminNotifications();
 const [data,setData]=useState(initial),[filters,setFilters]=useState(initial.filters),[page,setPage]=useState(initial.pagination.page),[busy,setBusy]=useState(false),[stale,setStale]=useState(false),[selected,setSelected]=useState<Set<number>>(new Set()),[copied,setCopied]=useState<number|null>(null);
 const request=useRef<AbortController|null>(null),sequence=useRef(0),alive=useRef(true),last=useRef(JSON.stringify([filters,page])),box=useRef<HTMLInputElement>(null),copyTimer=useRef<ReturnType<typeof setTimeout>|null>(null),pending=useRef(false);
 const number=(n:number)=>new Intl.NumberFormat(locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US').format(n);
 const query=(f:NoticeFilters,p:number)=>new URLSearchParams({...Object.fromEntries(Object.entries(f).map(([k,v])=>[k,String(v)])),archived:f.archived?'1':'0',page:String(p)}).toString();
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;request.current?.abort();if(copyTimer.current)clearTimeout(copyTimer.current);};},[]);
 useEffect(()=>{if(box.current)box.current.indeterminate=selected.size>0&&selected.size<data.items.length;},[selected,data.items]);
 async function reload(f=filters,p=page,notify=false,silent=false){
  if(f.dates==='custom'&&(!f.from||!f.to||f.to<f.from))return;
  if(silent&&pending.current)return;
  request.current?.abort();const abort=new AbortController();request.current=abort;const seq=++sequence.current;pending.current=true;if(!silent)setBusy(true);
  try{const result=await mediaApi<NoticeList>(data.endpoints.index+'?'+query(f,p),'GET',undefined,abort.signal);if(alive.current&&seq===sequence.current){setData(result);setStale(false);setSelected(old=>new Set([...old].filter(id=>result.items.some(n=>n.id===id))));if(result.pagination.page!==p){last.current=JSON.stringify([f,result.pagination.page]);setPage(result.pagination.page);}if(notify)toast.success(t('refreshed'));}}
  catch{if(!abort.signal.aborted&&alive.current){setStale(true);if(!silent)toast.error(t('error'));}}
  finally{if(alive.current&&seq===sequence.current){setBusy(false);pending.current=false;}}
 }
 useEffect(()=>{const key=JSON.stringify([filters,page]);if(key===last.current)return;const timer=setTimeout(()=>{last.current=key;void reload(filters,page);},280);return()=>clearTimeout(timer);},[filters,page]);
 useEffect(()=>{if(center.version>0)void reload(filters,page,false,true);},[center.version]);
 useEffect(()=>{const timer=setInterval(()=>{if(!document.hidden)void reload(filters,page,false,true);},30000);return()=>clearInterval(timer);},[filters,page]);
 function change<K extends keyof NoticeFilters>(key:K,value:NoticeFilters[K]){setFilters(old=>({...old,[key]:value}));setPage(1);setSelected(new Set());}
 function clear(){setFilters({...initial.filters,q:'',category:'',read:'',priority:'',issue:'',dates:'7',from:'',to:'',sort:'newest',archived:false});setPage(1);setSelected(new Set());}
 function toggle(id:number){setSelected(old=>{const next=new Set(old);next.has(id)?next.delete(id):next.add(id);return next;});}
 function quick(key:string){setFilters({...initial.filters,q:'',category:'',read:key==='unread'?'unread':'',priority:key==='critical'?'critical':'',issue:key==='critical'||key==='attention'?'attention':'',dates:key==='today'?'1':'all',archived:false,from:'',to:''});setPage(1);setSelected(new Set());}
 async function copy(n:Notice){try{await navigator.clipboard.writeText(n.reference);setCopied(n.id);if(copyTimer.current)clearTimeout(copyTimer.current);copyTimer.current=setTimeout(()=>setCopied(null),2000);}catch{/* Clipboard is optional. */}}
 const summary=center.feed?.summary??data.summary;
 const pages=Array.from(new Set([1,...[page-1,page,page+1].filter(n=>n>=1&&n<=data.pagination.pages),data.pagination.pages])).sort((a,b)=>a-b);
 const options=(keys:string[],label:string)=>[{value:'',label:t(label)+': '+t('any')},...keys.map(k=>({value:k,label:t(k)}))];
 const hasFilters=!!(filters.q||filters.category||filters.read||filters.priority||filters.issue||filters.dates!=='7');
 const activeIds=[...selected];
 return <section className="nf-page">
  <header className="nf-page-header"><div className="nf-heading"><span className="nf-title-icon"><StudioIcon name="bell"/></span><div><h1>{t('title')}</h1><p>{t('description')}</p></div></div><div className="nf-header-actions"><ActionTooltip label={t('allReadHint')}><button className="media-button" disabled={center.mutating||summary.unread===0} onClick={()=>void center.update('read_all')}><StudioIcon name="check"/>{t('markAll')}</button></ActionTooltip><button className="media-button primary" disabled={busy} onClick={()=>{void reload(filters,page,true);void center.refresh();}}><StudioIcon name={busy?'loader':'refresh'}/>{t('refresh')}</button></div></header>
  <div className="nf-summary">{([['unread','mail','primary'],['attention','alert','warning'],['critical','bolt','error'],['today','calendar','success']] as const).map(([key,icon,tone])=><button key={key} className={'nf-stat is-'+tone} onClick={()=>quick(key)}><span className="nf-stat-icon"><StudioIcon name={icon}/></span><span><small>{t(key)}</small><strong>{number(summary[key])}</strong><p>{t(key+'Hint')}</p></span></button>)}</div>
  <div className="nf-category-row"><nav className="nf-tabs" aria-label={t('all')}>{['','payments','jobs','subscriptions','system','support'].map(category=><button key={category} className={filters.category===category?'is-active':''} aria-current={filters.category===category?'page':undefined} onClick={()=>change('category',category)}>{t(category||'all')}<span>{number(category?data.tabs[category]??0:Object.values(data.tabs).reduce((a,b)=>a+b,0))}</span></button>)}</nav><button className={'media-button nf-archive-view'+(filters.archived?' is-active':'')} aria-pressed={filters.archived} onClick={()=>change('archived',!filters.archived)}><StudioIcon name={filters.archived?'bell':'archive'}/>{t(filters.archived?'inbox':'archived')}</button></div>
  <div className="nf-records" aria-busy={busy}>
   <div className="nf-toolbar"><label className="nf-search"><StudioIcon name="search"/><input type="search" value={filters.q} maxLength={100} placeholder={t('search')} aria-label={t('search')} onChange={e=>change('q',e.target.value)}/></label>
    <SelectField label={t('readFilter')} value={filters.read} options={options(['unread','read'],'readFilter')} onChange={v=>change('read',v)}/>
    <SelectField label={t('priority')} value={filters.priority} options={options(['critical','warning','info'],'priority')} onChange={v=>change('priority',v)}/>
    <SelectField label={t('issue')} value={filters.issue} options={options(['attention','resolved','info'],'issue')} onChange={v=>change('issue',v)}/>
    <SelectField label={t('period')} value={filters.dates} options={['1','7','30','all','custom'].map((v,i)=>({value:v,label:t(['day','week','month','allDates','custom'][i])}))} onChange={v=>{setFilters(f=>({...f,dates:v,from:f.from||data.today,to:f.to||data.today}));setPage(1);}}/>
    <SelectField label={t('sort')} value={filters.sort} options={['newest','oldest','priority'].map(v=>({value:v,label:t(v==='priority'?'priorityFirst':v)}))} onChange={v=>change('sort',v)}/>
    <button className="media-button nf-clear" onClick={clear}><StudioIcon name="filter"/>{t('clear')}</button>
   </div>
   {filters.dates==='custom'&&<div className="nf-date-range"><DatePicker id="nf-from" label={t('from')} value={filters.from} today={data.today} max={filters.to||undefined} locale={locale} onChange={v=>change('from',v)}/><DatePicker id="nf-to" label={t('to')} value={filters.to} today={data.today} min={filters.from||undefined} locale={locale} onChange={v=>change('to',v)}/><small>{t('utc')}</small></div>}
   {stale&&<p className="nf-stale" role="alert"><StudioIcon name="alert"/>{t('stale')}</p>}
   {selected.size>0&&<div className="nf-bulk"><strong>{number(selected.size)} {t('selected')}</strong><button className="media-button" disabled={center.mutating} onClick={()=>void center.update('read',activeIds)}><StudioIcon name="check"/>{t('markRead')}</button><button className="media-button" disabled={center.mutating} onClick={()=>void center.update('unread',activeIds)}><StudioIcon name="mail"/>{t('markUnread')}</button><button className="media-button" disabled={center.mutating} onClick={()=>void center.update(filters.archived?'restore':'archive',activeIds).then(ok=>{if(ok)setSelected(new Set());})}><StudioIcon name={filters.archived?'refresh':'archive'}/>{t(filters.archived?'restore':'archive')}</button></div>}
   {data.items.length>0?<div className="nf-table-scroll"><table className="nf-table"><thead><tr><th className="nf-check"><input ref={box} type="checkbox" aria-label={t('selectAll')} checked={selected.size===data.items.length&&data.items.length>0} onChange={e=>setSelected(e.target.checked?new Set(data.items.map(n=>n.id)):new Set())}/></th>{['notification','related','priority','issue','received','actions'].map(key=><th key={key}>{t(key)}</th>)}</tr></thead><tbody>{data.items.map(n=><tr key={n.id} className={n.unread?'is-unread':''}>
    <td className="nf-check"><input type="checkbox" aria-label={t('select')+' '+n.code} checked={selected.has(n.id)} onChange={()=>toggle(n.id)}/></td>
    <td className="nf-notification-cell"><button className="nf-notification" onClick={()=>void center.open(n)}><i className={'nf-unread-dot'+(n.unread?'':' is-read')} aria-label={n.unread?t('unread'):undefined}/><span className={'nf-event-icon is-'+n.category}><StudioIcon name={noticeIcons[n.category]}/></span><span><strong>{t(n.kind)}</strong><small>{body(n)}</small></span></button></td>
    <td data-label={t('related')}><div className="nf-related"><span className="nf-related-icon"><StudioIcon name={n.customer.name?'users':noticeIcons[n.category]}/></span><div>{n.customer.name&&<strong title={n.customer.name}>{n.customer.name}</strong>}<span className="nf-related-reference"><span title={n.reference}>{n.reference}</span><ActionTooltip label={copied===n.id?t('copied'):t('copy')}><button className="icon-button" aria-label={t('copy')} onClick={()=>void copy(n)}><StudioIcon name={copied===n.id?'check':'copy'}/></button></ActionTooltip></span></div></div></td>
    <td data-label={t('priority')}><NoticeBadge value={n.priority}/></td><td data-label={t('issue')}><NoticeBadge value={n.issue} issue/></td>
    <td data-label={t('received')} className="nf-received"><time title={date(n.received_at,true)}>{date(n.received_at,true)}</time><small>{relative(n.received_at)}</small></td>
    <td className="nf-action-cell"><div className="nf-row-actions"><ActionTooltip label={t('view')}><button className="media-button nf-icon" aria-label={t('view')} onClick={()=>void center.open(n)}><StudioIcon name="eye"/></button></ActionTooltip><LibraryMenu title={t(n.kind)} label={t('more')} actions={[{label:t(n.unread?'markRead':'markUnread'),icon:n.unread?'check':'mail',disabled:center.mutating,action:()=>void center.update(n.unread?'read':'unread',[n.id])},{label:t(n.archived?'restore':'archive'),icon:n.archived?'refresh':'archive',disabled:center.mutating,action:()=>void center.update(n.archived?'restore':'archive',[n.id])}]}/></div></td>
   </tr>)}</tbody></table></div>:<div className="nf-empty"><StudioIcon name={filters.archived?'archive':'check'}/><h2>{t(filters.archived?'noArchives':hasFilters?'noMatches':'empty')}</h2><p>{t('emptyHint')}</p>{hasFilters&&<button className="media-button" onClick={clear}>{t('clear')}</button>}</div>}
   <footer className="nf-table-footer"><p>{t('showing')} {number(data.pagination.from??0)}–{number(data.pagination.to??0)} {t('of')} {number(data.pagination.total)} {t('records')}</p><nav className="nf-pagination" aria-label={t('title')}><SelectField label={t('pageSize')} value={String(filters.per_page)} options={[10,25,50].map(n=>({value:String(n),label:String(n)}))} onChange={v=>change('per_page',Number(v))}/><PageBoundaryButton edge="first" page={page} pages={data.pagination.pages} onNavigate={setPage} className="media-button nf-icon"/><button className="media-button nf-icon" aria-label={t('previous')} disabled={page<=1} onClick={()=>setPage(p=>p-1)}><StudioIcon name="back"/></button>{pages.map((n,i)=><span className="nf-page-number" key={n}>{i>0&&n>pages[i-1]+1&&<i>…</i>}<button className={'media-button nf-icon'+(n===page?' primary':'')} aria-label={'Page '+n} aria-current={n===page?'page':undefined} onClick={()=>setPage(n)}>{number(n)}</button></span>)}<button className="media-button nf-icon" aria-label={t('next')} disabled={page>=data.pagination.pages} onClick={()=>setPage(p=>p+1)}><StudioIcon name="arrow"/></button><PageBoundaryButton edge="last" page={page} pages={data.pagination.pages} onNavigate={setPage} className="media-button nf-icon"/></nav></footer>
  </div>
 </section>;
}
