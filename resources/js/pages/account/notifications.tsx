import {Head,router} from '@inertiajs/react';
import {useEffect,useRef,useState} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {SelectField} from '@/components/media-workflow';
import {ActionPill,NotificationIcon,announceNotificationChange,notificationVisual,useDayGroups,useNotificationChanges,useRelativeTime} from '@/components/customer-notifications';
import {mediaApi} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import {useDisplayDate} from '@/lib/platform-display';
import {useNotificationText,type NotificationWord} from '@/lib/customer-notifications-i18n';
import type {CustomerNotification,NotificationFeed,NotificationFilters,NotificationListing} from '@/types/customer-notifications';
import '../../../css/customer-notifications.css';

const tabs=['all','unread','video','payment','plan','account'] as const;
const defaults:NotificationFilters={tab:'all',status:'',period:'30',archived:false,q:''};
const chipWord:Record<string,NotificationWord>={video:'chipVideo',payment:'chipPayment',plan:'chipPlan',account:'chipAccount'};

export default function Notifications({notifications:initial}:{notifications:NotificationListing}){
 const {t}=useNotificationText();const toast=useToast();const groups=useDayGroups();
 const [data,setData]=useState(initial),[filters,setFilters]=useState<NotificationFilters>(initial.filters),[page,setPage]=useState(initial.pagination.page),[query,setQuery]=useState(initial.filters.q);
 const [loading,setLoading]=useState(false),[failed,setFailed]=useState(false),[selected,setSelected]=useState<Set<number>>(new Set()),[busy,setBusy]=useState(false),[sheet,setSheet]=useState(false),[spin,setSpin]=useState(false);
 const request=useRef<AbortController|null>(null),lastKey=useRef(JSON.stringify([initial.filters,initial.pagination.page]));
 async function load(next=filters,targetPage=page,quiet=false){
  request.current?.abort();const controller=new AbortController();request.current=controller;if(!quiet)setLoading(true);
  const params=new URLSearchParams({tab:next.tab,period:next.period,page:String(targetPage),...(next.status?{status:next.status}:{}),...(next.archived?{archived:'1'}:{}),...(next.q?{q:next.q}:{})});
  try{const result=await mediaApi<NotificationListing>(data.endpoints.index+'?'+params,'GET',undefined,controller.signal);setData(result);setFailed(false);lastKey.current=JSON.stringify([next,result.pagination.page]);setPage(result.pagination.page);
   window.history.replaceState(window.history.state,'',data.endpoints.index+'?'+params);}
  catch(error){if(!controller.signal.aborted)setFailed(true);}finally{if(request.current===controller)setLoading(false);}
 }
 useEffect(()=>{const key=JSON.stringify([filters,page]);if(key===lastKey.current)return;lastKey.current=key;void load(filters,page);},[filters,page]);
 useEffect(()=>{if(query===filters.q)return;const timer=setTimeout(()=>{setPage(1);setFilters(old=>({...old,q:query.trim()}));},300);return()=>clearTimeout(timer);},[query]);
 useEffect(()=>{const timer=setInterval(()=>{if(!document.hidden&&!selected.size)void load(filters,page,true);},45000);return()=>clearInterval(timer);},[filters,page,selected.size]);
 useNotificationChanges(()=>void load(filters,page,true));
 useEffect(()=>{setSelected(new Set());},[data.items]);
 function change(patch:Partial<NotificationFilters>){setPage(1);setFilters(old=>({...old,...patch}));}
 async function act(action:string,ids:number[],message?:string){
  if(busy)return;setBusy(true);
  try{const feed=await mediaApi<NotificationFeed>(data.endpoints.action,'POST',{action,ids});announceNotificationChange(feed);setSelected(new Set());if(message)toast.success(message);await load(filters,page,true);}
  catch{toast.error(t('failed'));}finally{setBusy(false);}
 }
 function open(item:CustomerNotification){if(!item.read)void act('read',[item.id]);if(item.url)router.visit(item.url);}
 const changed=JSON.stringify({...filters,q:query.trim()})!==JSON.stringify(defaults);
 const allOnPage=data.items.length>0&&data.items.every(item=>selected.has(item.id));
 const statusOptions=[{value:'',label:t('statusAll')},{value:'unread',label:t('statusUnread')},{value:'read',label:t('statusRead')}];
 const periodOptions=[{value:'7',label:t('period7')},{value:'30',label:t('period30')},{value:'all',label:t('periodAll')}];
 const filterControls=<>
  <div className="cn-select"><SelectField label={t('statusAll')} value={filters.status} options={statusOptions} onChange={status=>change({status})}/></div>
  <div className="cn-select"><SelectField label={t('period30')} value={filters.period} options={periodOptions} onChange={period=>change({period})}/></div>
  <label className="cn-switch"><input type="checkbox" role="switch" checked={filters.archived} onChange={event=>change({archived:event.target.checked})}/><i/><span>{t('showArchived')}</span></label>
 </>;
 return <AppLayout frame="notifications"><Head title={t('title')}/>
  <section className="cn-page">
   <header className="cn-heading"><div><h1>{t('title')}</h1><p>{t('description')}</p></div>
    <button type="button" className="cn-button primary" disabled={busy||!data.unread} onClick={()=>void act('read_all',[],t('allRead'))}><StudioIcon name="check"/>{t('markAll')}</button></header>
   <nav className="cn-tabs" role="tablist" aria-label={t('title')}>{tabs.map(key=><button key={key} type="button" role="tab" aria-selected={filters.tab===key} onClick={()=>change({tab:key})}>
    <span>{t(key)}</span><b>{data.counts[key]??0}</b></button>)}</nav>
   <div className="cn-card">
    {selected.size>0?<div className="cn-selection" role="toolbar" aria-label={t('selected')}>
     <label className="cn-check"><input type="checkbox" checked={allOnPage} onChange={()=>setSelected(allOnPage?new Set():new Set(data.items.map(item=>item.id)))} aria-label={t('selectAll')}/><span>{selected.size} {t('selected')}</span></label>
     <button type="button" className="cn-bar-action" disabled={busy} onClick={()=>void act('read',[...selected],t('updated'))}><StudioIcon name="check"/>{t('markRead')}</button>
     <button type="button" className="cn-bar-action" disabled={busy} onClick={()=>void act(filters.archived&&data.items.filter(item=>selected.has(item.id)).every(item=>item.archived)?'unarchive':'archive',[...selected],t(filters.archived?'restored':'archived'))}><StudioIcon name="archive"/>{filters.archived&&data.items.filter(item=>selected.has(item.id)).every(item=>item.archived)?t('unarchive'):t('archive')}</button>
     <ActionTooltip label={t('clearSelection')}><button type="button" className="cn-icon-button" aria-label={t('clearSelection')} onClick={()=>setSelected(new Set())}><StudioIcon name="close"/></button></ActionTooltip>
    </div>:<div className="cn-toolbar">
     <label className="cn-search"><StudioIcon name="search"/><input value={query} placeholder={t('search')} aria-label={t('search')} maxLength={100} onChange={event=>setQuery(event.target.value)}/>{query&&<button type="button" aria-label={t('clearFilters')} onClick={()=>setQuery('')}><StudioIcon name="close"/></button>}</label>
     <div className="cn-desktop-filters">{filterControls}</div>
     <button type="button" className="cn-button cn-filters-button" onClick={()=>setSheet(true)}><StudioIcon name="filter"/>{t('filters')}{changed&&<i className="cn-filter-dot"/>}</button>
     <span className="cn-toolbar-end">
      {changed&&<ActionTooltip label={t('clearFilters')}><button type="button" className="cn-icon-button" aria-label={t('clearFilters')} onClick={()=>{setQuery('');setPage(1);setFilters(defaults);}}><StudioIcon name="filter-clear"/></button></ActionTooltip>}
      <ActionTooltip label={t('refresh')}><button type="button" className="cn-icon-button" aria-label={t('refresh')} onClick={()=>{setSpin(true);void load().finally(()=>setTimeout(()=>setSpin(false),400));}}><StudioIcon name="refresh" className={spin?'cn-spin':''}/></button></ActionTooltip>
     </span>
    </div>}
    {failed&&<div className="cn-error" role="alert"><StudioIcon name="alert"/>{t('loadFailed')}<button type="button" className="cn-link" onClick={()=>void load()}>{t('retryLoad')}</button></div>}
    <div className={'cn-list'+(loading?' is-loading':'')} aria-busy={loading}>
     {!data.items.length&&!loading?<div className="cn-empty"><span className="cn-bell-art"><StudioIcon name="bell"/></span>
      <h2>{changed?t('noMatch'):t('caughtUp')}</h2><p>{changed?'':t('caughtUpHint')}</p>{changed&&<button type="button" className="cn-button" onClick={()=>{setQuery('');setFilters(defaults);}}><StudioIcon name="filter-clear"/>{t('clearFilters')}</button>}</div>
      :groups(data.items).map(group=><section key={group.label} className="cn-group"><h3>{group.label}</h3><ul>{group.items.map(item=><NotificationRow key={item.id} item={item} tab={filters.tab} selected={selected.has(item.id)} selecting={selected.size>0} busy={busy}
       onSelect={()=>setSelected(old=>{const next=new Set(old);if(next.has(item.id))next.delete(item.id);else next.add(item.id);return next;})}
       onOpen={()=>open(item)} onAct={(action,message)=>void act(action,[item.id],message)}/>)}</ul></section>)}
    </div>
    {data.pagination.total>0&&<Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} size={data.pagination.per_page} onPage={setPage}/>}
   </div>
  </section>
  {sheet&&<div className="cn-sheet-backdrop" onClick={event=>{if(event.target===event.currentTarget)setSheet(false);}}><div className="cn-sheet cn-filter-sheet" role="dialog" aria-modal="true" aria-label={t('filters')}>
   <span className="cn-sheet-handle" aria-hidden="true"/><header className="cn-panel-head"><h2>{t('filters')}</h2><button type="button" className="cn-close" aria-label={t('close')} onClick={()=>setSheet(false)}><StudioIcon name="close"/></button></header>
   <div className="cn-sheet-filters">{filterControls}</div>
   <footer className="cn-sheet-actions"><button type="button" className="cn-button" disabled={!changed} onClick={()=>{setQuery('');setFilters(defaults);}}>{t('clearFilters')}</button><button type="button" className="cn-button primary" onClick={()=>setSheet(false)}>{t('apply')}</button></footer>
  </div></div>}
 </AppLayout>;
}

function NotificationRow({item,tab,selected,selecting,busy,onSelect,onOpen,onAct}:{item:CustomerNotification;tab:string;selected:boolean;selecting:boolean;busy:boolean;onSelect:()=>void;onOpen:()=>void;onAct:(action:string,message:string)=>void}){
 const {t,describe}=useNotificationText();const date=useDisplayDate();const relative=useRelativeTime();
 const text=describe(item,value=>date(value,false));const visual=notificationVisual(item.type);
 const [menu,setMenu]=useState(false),[offset,setOffset]=useState(0);
 const wrapRef=useRef<HTMLLIElement>(null),menuRef=useRef<HTMLDivElement>(null),swipe=useRef<{x:number;y:number;base:number;active:boolean}|null>(null);
 useEffect(()=>{if(!menu)return;const close=(event:PointerEvent)=>{if(!menuRef.current?.contains(event.target as Node))setMenu(false);};const escape=(event:KeyboardEvent)=>{if(event.key==='Escape')setMenu(false);};document.addEventListener('pointerdown',close);document.addEventListener('keydown',escape);return()=>{document.removeEventListener('pointerdown',close);document.removeEventListener('keydown',escape);};},[menu]);
 const chip=tab==='all'||tab==='unread'?<span className={'cn-chip is-'+item.category}>{t(chipWord[item.category])}</span>:<span className={'cn-status is-'+visual.tone}>{t(visual.status)}</span>;
 const readAction=item.read?{action:'unread',label:t('markUnread')}:{action:'read',label:t('markRead')};
 const archiveAction=item.archived?{action:'unarchive',label:t('unarchive'),message:t('restored')}:{action:'archive',label:t('archive'),message:t('archived')};
 useEffect(()=>{if(!offset)return;const close=(event:PointerEvent)=>{if(!wrapRef.current?.contains(event.target as Node))setOffset(0);};document.addEventListener('pointerdown',close);return()=>document.removeEventListener('pointerdown',close);},[offset]);
 return <li ref={wrapRef} className={'cn-row-wrap'+(offset<0?' is-swiped':'')}>
  <div className="cn-swipe-actions" aria-hidden={offset===0}>
   <button type="button" className="is-read" tabIndex={offset<0?0:-1} onClick={()=>{setOffset(0);onAct(readAction.action,t('updated'));}}><StudioIcon name={item.read?'bell':'check'}/>{item.read?t('unread'):t('read')}</button>
   <button type="button" className="is-archive" tabIndex={offset<0?0:-1} onClick={()=>{setOffset(0);onAct(archiveAction.action,archiveAction.message);}}><StudioIcon name="archive"/>{archiveAction.label}</button>
  </div>
  <div className={'cn-row'+(item.read?'':' is-unread')+(selected?' is-selected':'')+(selecting?' is-selecting':'')} style={offset?{transform:'translateX('+offset+'px)'}:undefined}
   onPointerDown={event=>{if(event.pointerType!=='touch')return;swipe.current={x:event.clientX,y:event.clientY,base:offset,active:false};}}
   onPointerMove={event=>{const state=swipe.current;if(!state)return;const dx=event.clientX-state.x,dy=event.clientY-state.y;if(!state.active&&Math.abs(dx)>12&&Math.abs(dx)>Math.abs(dy)*1.4)state.active=true;if(state.active)setOffset(Math.max(-136,Math.min(0,state.base+dx)));}}
   onPointerUp={()=>{const state=swipe.current;swipe.current=null;if(state?.active)setOffset(current=>current<-60?-136:0);}}
   onPointerCancel={()=>{swipe.current=null;setOffset(current=>current<-60?-136:0);}}>
   <label className="cn-row-check" onClick={event=>event.stopPropagation()}><input type="checkbox" checked={selected} onChange={onSelect} aria-label={t('select')+' '+text.title}/><i className="cn-dot" aria-hidden="true"/></label>
   <button type="button" className="cn-row-main" onClick={()=>{if(offset){setOffset(0);return;}onOpen();}} disabled={busy&&!item.url}>
    <NotificationIcon type={item.type}/>
    <span className="cn-row-text"><strong>{text.title}</strong><span className="cn-body">{item.removed?t('removedProject'):text.body}</span></span>
   </button>
   <span className="cn-row-chip">{chip}</span>
   <span className="cn-row-action">{item.action&&<ActionPill item={item} onDone={()=>{if(!item.read)onAct('read','');}}/>}</span>
   <time className="cn-row-time" dateTime={item.at} title={relative(item.at,true)}>{relative(item.at)}</time>
   <div className="cn-menu-wrap" ref={menuRef}>
    <ActionTooltip label={t('moreActions')}><button type="button" className="cn-icon-button" aria-label={t('moreActions')} aria-haspopup="menu" aria-expanded={menu} disabled={busy} onClick={()=>setMenu(!menu)}><StudioIcon name="more"/></button></ActionTooltip>
    {menu&&<div className="cn-menu" role="menu">
     <button type="button" role="menuitem" onClick={()=>{setMenu(false);onAct(readAction.action,t('updated'));}}><StudioIcon name={item.read?'bell':'check'}/>{readAction.label}</button>
     <button type="button" role="menuitem" onClick={()=>{setMenu(false);onAct(archiveAction.action,archiveAction.message);}}><StudioIcon name="archive"/>{archiveAction.label}</button>
    </div>}
   </div>
  </div>
 </li>;
}

function Pagination({page,pages,total,size,onPage}:{page:number;pages:number;total:number;size:number;onPage:(page:number)=>void}){
 const {t}=useNotificationText();
 const from=(page-1)*size+1,to=Math.min(total,page*size);
 const numbers=Array.from(new Set([1,page-1,page,page+1,pages])).filter(value=>value>=1&&value<=pages).sort((a,b)=>a-b);
 return <footer className="cn-pagination"><span>{t('showing')} {from}–{to} {t('of')} {total}</span><nav aria-label={t('showing')}>
  <button type="button" disabled={page<=1} onClick={()=>onPage(1)}>{t('first')}</button>
  <button type="button" disabled={page<=1} onClick={()=>onPage(page-1)}><StudioIcon name="back"/>{t('previous')}</button>
  {numbers.map((value,index)=><span key={value}>{index>0&&value>numbers[index-1]+1&&<i>…</i>}<button type="button" aria-current={value===page?'page':undefined} onClick={()=>onPage(value)}>{value}</button></span>)}
  <button type="button" disabled={page>=pages} onClick={()=>onPage(page+1)}>{t('next')}<StudioIcon name="arrow"/></button>
  <button type="button" disabled={page>=pages} onClick={()=>onPage(pages)}>{t('last')}</button>
 </nav></footer>;
}
