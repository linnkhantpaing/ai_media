import {router,usePage} from '@inertiajs/react';
import {useCallback,useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {mediaApi} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import {useDisplayDate} from '@/lib/platform-display';
import {useNotificationText,type NotificationWord} from '@/lib/customer-notifications-i18n';
import type {SharedData} from '@/types';
import type {CustomerNotification,NotificationFeed} from '@/types/customer-notifications';
import '../../css/customer-notifications.css';

type Tone='mint'|'purple'|'amber'|'red';
const changeEvent='zatlan:customer-notifications';

/** Tells every bell/page instance that notifications changed. */
export function announceNotificationChange(feed?:NotificationFeed){window.dispatchEvent(new CustomEvent(changeEvent,{detail:feed}));}
export function useNotificationChanges(listener:(feed?:NotificationFeed)=>void){
 const ref=useRef(listener);ref.current=listener;
 useEffect(()=>{const handler=(event:Event)=>ref.current((event as CustomEvent<NotificationFeed|undefined>).detail);window.addEventListener(changeEvent,handler);return()=>window.removeEventListener(changeEvent,handler);},[]);
}

export function notificationVisual(type:string):{icon:IconName;tone:Tone;status:NotificationWord}{
 switch(type){
  case 'video_ready':return {icon:'triangle-play',tone:'mint',status:'ready'};
  case 'script_ready':return {icon:'file',tone:'purple',status:'actionRequired'};
  case 'export_failed':case 'analysis_failed':return {icon:'alert',tone:'red',status:'failedChip'};
  case 'payment_approved':return {icon:'wallet',tone:'mint',status:'approved'};
  case 'payment_info_needed':return {icon:'info',tone:'amber',status:'actionRequired'};
  case 'payment_rejected':return {icon:'close',tone:'red',status:'rejected'};
  case 'refund_completed':return {icon:'refresh',tone:'mint',status:'refunded'};
  case 'plan_expiring':return {icon:'crown',tone:'amber',status:'expiring'};
  case 'plan_expired':return {icon:'crown',tone:'red',status:'expired'};
  case 'plan_started':return {icon:'crown',tone:'mint',status:'active'};
  default:return {icon:'shield',tone:'purple',status:'security'};
 }
}
const categoryChip:Record<string,NotificationWord>={video:'chipVideo',payment:'chipPayment',plan:'chipPlan',account:'chipAccount'};

export function useRelativeTime(){
 const {t,language}=useNotificationText();const date=useDisplayDate();
 return (value:string,full=false)=>{
  const seconds=(Date.parse(value)-Date.now())/1000;
  if(full)return date(value,true);
  if(seconds>-60)return t('justNow');
  const format=new Intl.RelativeTimeFormat(language,{numeric:'auto',style:'short'});
  if(seconds>-3600)return format.format(Math.round(seconds/60),'minute');
  if(seconds>-86400)return format.format(Math.round(seconds/3600),'hour');
  if(seconds>-7*86400)return date(value,true);
  return date(value,false);
 };
}

/** Groups by calendar day in the viewer's display time zone. */
export function useDayGroups(){
 const {app}=usePage<SharedData>().props;const {t}=useNotificationText();
 const day=(value:Date)=>new Intl.DateTimeFormat('en-CA',{timeZone:app.timezone??'UTC',year:'numeric',month:'2-digit',day:'2-digit'}).format(value);
 return (items:CustomerNotification[])=>{
  const now=new Date();const today=day(now),yesterday=day(new Date(now.getTime()-86400000));
  const groups:{label:string;items:CustomerNotification[]}[]=[];
  for(const item of items){
   const at=new Date(item.at),key=day(at);
   const label=key===today?t('today'):key===yesterday?t('yesterday'):now.getTime()-at.getTime()<7*86400000?t('thisWeek'):t('earlier');
   const last=groups.at(-1);if(last?.label===label)last.items.push(item);else groups.push({label,items:[item]});
  }
  return groups;
 };
}

export function NotificationIcon({type}:{type:string}){const visual=notificationVisual(type);return <span className={'cn-icon is-'+visual.tone} aria-hidden="true"><StudioIcon name={visual.icon}/></span>;}

export function ActionPill({item,onDone}:{item:CustomerNotification;onDone:()=>void}){
 const {t}=useNotificationText();
 if(!item.action)return null;
 const label=t(item.action.kind);
 if(item.action.kind==='download')return <a className="cn-pill" href={item.action.url} download onClick={event=>{event.stopPropagation();onDone();}}>{label}</a>;
 return <button type="button" className="cn-pill" onClick={event=>{event.stopPropagation();onDone();router.visit(item.action!.url);}}>{label}</button>;
}

/** Compact item used in the bell dropdown and bottom sheet. */
function FeedItem({item,onOpen,onRead}:{item:CustomerNotification;onOpen:()=>void;onRead:()=>void}){
 const {t,describe}=useNotificationText();const date=useDisplayDate();const relative=useRelativeTime();
 const text=describe(item,value=>date(value,false));
 return <li className={'cn-feed-item'+(item.read?'':' is-unread')}>
  <button type="button" className="cn-feed-main" onClick={onOpen} disabled={!item.url&&item.read}>
   <i className="cn-dot" aria-hidden="true"/><NotificationIcon type={item.type}/>
   <span className="cn-feed-text"><span className="cn-feed-top"><strong>{text.title}</strong><time dateTime={item.at} title={relative(item.at,true)}>{relative(item.at)}</time></span>
    <span className="cn-body">{item.removed?t('removedProject'):text.body}</span>
    <span className="cn-feed-meta"><span className={'cn-chip is-'+item.category}>{t(categoryChip[item.category])}</span></span></span>
  </button>
  {item.action&&<span className="cn-feed-action"><ActionPill item={item} onDone={onRead}/></span>}
 </li>;
}

function Panel({feed,tab,setTab,onMarkAll,onOpen,onRead,onClose,busy,error,onRetry,mobile}:{feed:NotificationFeed|null;tab:'all'|'unread';setTab:(tab:'all'|'unread')=>void;onMarkAll:()=>void;onOpen:(item:CustomerNotification)=>void;onRead:(item:CustomerNotification)=>void;onClose:()=>void;busy:boolean;error:boolean;onRetry:()=>void;mobile:boolean}){
 const {t}=useNotificationText();const {customerNotifications}=usePage<SharedData>().props;const groups=useDayGroups();
 const items=tab==='all'?feed?.all??[]:feed?.unread_items??[];
 return <>
  {mobile&&<span className="cn-sheet-handle" aria-hidden="true"/>}
  <header className="cn-panel-head"><h2>{t('title')}</h2>{feed&&<span className="cn-count">{feed.unread} {t('unreadCount')}</span>}
   <button type="button" className="cn-link" disabled={busy||!feed?.unread} onClick={onMarkAll}>{t('markAll')}</button>
   {mobile&&<button type="button" className="cn-close" aria-label={t('close')} onClick={onClose}><StudioIcon name="close"/></button>}</header>
  <nav className="cn-panel-tabs" role="tablist">{(['all','unread'] as const).map(key=><button key={key} type="button" role="tab" aria-selected={tab===key} onClick={()=>setTab(key)}>{t(key)}</button>)}</nav>
  <div className="cn-panel-body">
   {!feed&&!error?<div className="cn-skeletons" aria-busy="true">{[0,1,2].map(index=><span key={index}/>)}</div>
    :error&&!feed?<div className="cn-panel-error"><StudioIcon name="alert"/>{t('loadFailed')}<button type="button" className="cn-link" onClick={onRetry}>{t('retryLoad')}</button></div>
    :!items.length?<div className="cn-caught-up"><span className="cn-bell-art"><StudioIcon name="bell"/></span><strong>{t('caughtUp')}</strong><p>{tab==='unread'?t('caughtUpUnread'):t('caughtUpHint')}</p></div>
    :groups(items).map(group=><section key={group.label}><h3>{group.label}</h3><ul>{group.items.map(item=><FeedItem key={item.id} item={item} onOpen={()=>onOpen(item)} onRead={()=>onRead(item)}/>)}</ul></section>)}
  </div>
  <footer className="cn-panel-foot"><a href={customerNotifications?.index} onClick={event=>{event.preventDefault();onClose();router.visit(customerNotifications!.index);}}>{t('viewAll')}<StudioIcon name="arrow"/></a></footer>
 </>;
}

/** Header bell with unread badge, dropdown on desktop and bottom sheet on phones. */
export function CustomerNotificationBell(){
 const {customerNotifications}=usePage<SharedData>().props;
 if(!customerNotifications)return null;
 return <Bell links={customerNotifications}/>;
}

function Bell({links}:{links:{index:string;feed:string;action:string}}){
 const {t}=useNotificationText();const toast=useToast();
 const [feed,setFeed]=useState<NotificationFeed|null>(null),[open,setOpen]=useState(false),[tab,setTab]=useState<'all'|'unread'>('all'),[busy,setBusy]=useState(false),[error,setError]=useState(false),[ring,setRing]=useState(false),[mobile,setMobile]=useState(()=>window.matchMedia('(max-width:640px)').matches),[closing,setClosing]=useState(false);
 const root=useRef<HTMLDivElement>(null),sheet=useRef<HTMLDivElement>(null),previous=useRef<number|null>(null),pending=useRef(false),drag=useRef<{y:number;dy:number}|null>(null);
 const load=useCallback(async()=>{
  if(pending.current||document.hidden)return;pending.current=true;
  try{const next=await mediaApi<NotificationFeed>(links.feed,'GET');setFeed(next);setError(false);
   if(previous.current!==null&&next.unread>previous.current&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches){setRing(true);setTimeout(()=>setRing(false),900);}
   previous.current=next.unread;}
  catch{setError(true);}finally{pending.current=false;}
 },[links.feed]);
 useEffect(()=>{void load();const timer=setInterval(()=>void load(),open?15000:45000);const wake=()=>{if(!document.hidden)void load();};window.addEventListener('focus',wake);document.addEventListener('visibilitychange',wake);return()=>{clearInterval(timer);window.removeEventListener('focus',wake);document.removeEventListener('visibilitychange',wake);};},[load,open]);
 useEffect(()=>{const query=window.matchMedia('(max-width:640px)');const change=()=>setMobile(query.matches);query.addEventListener('change',change);return()=>query.removeEventListener('change',change);},[]);
 useNotificationChanges(next=>{if(next){setFeed(next);previous.current=next.unread;}else void load();});
 const close=useCallback(()=>{if(!open)return;setClosing(true);setTimeout(()=>{setOpen(false);setClosing(false);},170);},[open]);
 useEffect(()=>{if(!open)return;
  const outside=(event:PointerEvent)=>{if(!mobile&&!root.current?.contains(event.target as Node))close();};
  const escape=(event:KeyboardEvent)=>{if(event.key==='Escape')close();};
  document.addEventListener('pointerdown',outside);document.addEventListener('keydown',escape);
  const overflow=document.body.style.overflow;if(mobile)document.body.style.overflow='hidden';
  return()=>{document.removeEventListener('pointerdown',outside);document.removeEventListener('keydown',escape);document.body.style.overflow=overflow;};
 },[open,mobile,close]);
 async function act(action:string,ids:number[]=[]){setBusy(true);try{const next=await mediaApi<NotificationFeed>(links.action,'POST',{action,ids});setFeed(next);previous.current=next.unread;announceNotificationChange(next);return true;}catch{toast.error(t('failed'));return false;}finally{setBusy(false);}}
 function openItem(item:CustomerNotification){if(!item.read)void act('read',[item.id]);if(item.url){close();router.visit(item.url);}}
 function readItem(item:CustomerNotification){if(!item.read)void act('read',[item.id]);close();}
 async function markAll(){if(await act('read_all'))toast.success(t('allRead'));}
 const unread=feed?.unread??0;
 const panel=<Panel feed={feed} tab={tab} setTab={setTab} onMarkAll={()=>void markAll()} onOpen={openItem} onRead={readItem} onClose={close} busy={busy} error={error} onRetry={()=>void load()} mobile={mobile}/>;
 return <div className="cn-bell-wrap" ref={root}>
  <ActionTooltip label={t('title')+(unread?' · '+unread+' '+t('unreadCount'):'')}>
   <button type="button" className={'icon-button cn-bell'+(ring?' is-ringing':'')+(open?' is-open':'')} aria-label={t('title')+(unread?' · '+unread+' '+t('unreadCount'):'')} aria-haspopup="dialog" aria-expanded={open} onClick={()=>{if(open)close();else{setOpen(true);void load();}}}>
    <StudioIcon name="bell"/>{unread>0&&<span className="cn-badge">{unread>99?'99+':unread}</span>}
   </button>
  </ActionTooltip>
  {open&&!mobile&&<div className={'cn-panel'+(closing?' is-closing':'')} role="dialog" aria-label={t('title')}>{panel}</div>}
  {open&&mobile&&createPortal(<div className={'cn-sheet-backdrop'+(closing?' is-closing':'')} onClick={event=>{if(event.target===event.currentTarget)close();}}>
   <div ref={sheet} className="cn-sheet" role="dialog" aria-modal="true" aria-label={t('title')}
    onPointerDown={event=>{if((event.target as HTMLElement).closest('.cn-sheet-handle,.cn-panel-head'))drag.current={y:event.clientY,dy:0};}}
    onPointerMove={event=>{const state=drag.current;if(!state||!sheet.current)return;state.dy=Math.max(0,event.clientY-state.y);sheet.current.style.transform='translateY('+state.dy+'px)';}}
    onPointerUp={()=>{const state=drag.current;drag.current=null;if(!sheet.current)return;sheet.current.style.transform='';if(state&&state.dy>90)close();}}>
    {panel}
   </div></div>,document.body)}
 </div>;
}

