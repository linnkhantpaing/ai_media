import {createContext,useContext,useEffect,useRef,useState,type PropsWithChildren} from 'react';
import {Link,usePage} from '@inertiajs/react';
import {MediaDialog} from '@/components/media-workflow';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {useNotificationText} from '@/lib/notification-i18n';
import {useDisplayDate} from '@/lib/platform-display';
import {mediaApi,formatBytes} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import {useLoading} from '@/lib/loading';
import type {SharedData} from '@/types';
import type {Notice,NoticeAction,NoticeDetail,NoticeFeed} from '@/types/notifications';
import '../../css/notifications.css';

type Center={feed:NoticeFeed|null;failed:boolean;loading:boolean;mutating:boolean;version:number;refresh:()=>Promise<void>;open:(notice:Notice)=>Promise<void>;update:(action:NoticeAction,ids?:number[],notify?:boolean)=>Promise<boolean>};
const Context=createContext<Center|null>(null);
export const noticeIcons:Record<string,IconName>={payments:'credit-card',jobs:'folder-play',subscriptions:'layers',system:'shield',support:'chat'};
export function useAdminNotifications(){const value=useContext(Context);if(!value)throw Error('Admin notification context is missing');return value;}
export function NoticeBadge({value,issue=false}:{value:string;issue?:boolean}){const {t}=useNotificationText();const icon:IconName=value==='critical'?'bolt':value==='warning'||value==='attention'?'alert':value==='resolved'?'check':'info';return <span className={'nf-badge is-'+value+(issue?' is-issue':'')}><StudioIcon name={icon}/>{t(value)}</span>;}

export function AdminNotificationsProvider({enabled,children}:PropsWithChildren<{enabled:boolean}>){
 const {notificationLinks:links}=usePage<SharedData>().props;const {t}=useNotificationText();const toast=useToast(),{begin}=useLoading();
 const [feed,setFeed]=useState<NoticeFeed|null>(null),[failed,setFailed]=useState(false),[loading,setLoading]=useState(false),[mutating,setMutating]=useState(false),[version,setVersion]=useState(0);
 const [detail,setDetail]=useState<NoticeDetail|null>(null),[drawer,setDrawer]=useState(false);
 const alive=useRef(true),fetcher=useRef<AbortController|null>(null),opener=useRef<AbortController|null>(null),busy=useRef(false);
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;fetcher.current?.abort();opener.current?.abort();};},[]);
 async function refresh(){if(!enabled||!links||busy.current)return;fetcher.current?.abort();const abort=new AbortController();fetcher.current=abort;setLoading(true);try{const result=await mediaApi<NoticeFeed>(links.feed,'GET',undefined,abort.signal);if(alive.current){setFeed(result);setFailed(false);}}catch{if(!abort.signal.aborted&&alive.current)setFailed(true);}finally{if(alive.current&&!abort.signal.aborted)setLoading(false);}}
 useEffect(()=>{if(!enabled)return;void refresh();const timer=setInterval(()=>{if(!document.hidden&&!busy.current)void refresh();},30000);return()=>clearInterval(timer);},[enabled,links?.feed]);
 async function update(action:NoticeAction,ids:number[]=[],notify=true){if(!links||busy.current)return false;busy.current=true;fetcher.current?.abort();setLoading(false);setMutating(true);try{
  const result=await mediaApi<NoticeFeed>(links.action,'POST',{action,...(action==='read_all'?{}:{ids})});
  if(alive.current){setFeed(result);setFailed(false);setVersion(v=>v+1);setDetail(d=>d&&ids.includes(d.id)?{...d,unread:action==='read'?false:action==='unread'?true:d.unread,archived:action==='archive'?true:action==='restore'?false:d.archived}:d);
   if(notify)toast.success(t(action==='archive'?'archiveDone':action==='restore'?'restoreDone':action==='unread'?'unreadDone':'readDone'));}
  return true;
 }catch{if(alive.current)toast.error(t('actionError'));return false;}finally{busy.current=false;if(alive.current)setMutating(false);}}
 async function open(notice:Notice){opener.current?.abort();const abort=new AbortController();opener.current=abort;const done=begin();try{
  const result=await mediaApi<NoticeDetail>(notice.detail_url,'GET',undefined,abort.signal);
  if(abort.signal.aborted||!alive.current)return;
  if(result.unread){const changed=await update('read',[result.id],false);if(changed)result.unread=false;}
  if(!abort.signal.aborted&&alive.current){setDetail(result);setDrawer(true);}
 }catch{if(!abort.signal.aborted&&alive.current)toast.error(t('error'));}finally{done();}}
 return <Context.Provider value={{feed,failed,loading,mutating,version,refresh,open,update}}>{children}{enabled&&<NotificationDetails detail={detail} open={drawer} close={()=>setDrawer(false)}/>}</Context.Provider>;
}

export function AdminNotificationBell(){
 const {t,body,relative}=useNotificationText(),date=useDisplayDate(),center=useAdminNotifications();
 const {notificationLinks}=usePage<SharedData>().props;
 return <details className="studio-header-menu notifications-menu nf-bell-menu" onToggle={e=>{if(e.currentTarget.open)void center.refresh();}}>
  <summary className="icon-button nf-bell" aria-label={t('title')+(center.feed?' · '+center.feed.summary.unread:'')} title={t('title')}><StudioIcon name="bell"/>{center.feed&&<span aria-live="polite" className={'nf-bell-count'+(center.feed.summary.unread===0?' is-empty':'')}>{center.feed.summary.unread>99?'99+':center.feed.summary.unread}</span>}</summary>
  <div className="studio-popover nf-bell-popover"><header><strong>{t('title')}</strong>{center.feed&&<span>{center.feed.summary.unread} {t('unread')}</span>}</header>
   {center.failed&&<div className="nf-bell-failure" role="status"><p>{t('error')}</p><button onClick={()=>void center.refresh()}>{t('retry')}</button></div>}
   {!center.feed&&!center.failed&&<div className="nf-bell-empty"><StudioIcon name="loader"/>{t('loading')}</div>}
   {center.feed?.items.filter(n=>n.unread&&!n.archived).map(n=><button key={n.id} className={'nf-bell-item'+(n.unread?' is-unread':'')} onClick={e=>{e.currentTarget.closest('details')?.removeAttribute('open');void center.open(n);}}><span className={'nf-event-icon is-'+n.category}><StudioIcon name={noticeIcons[n.category]}/></span><span><strong>{t(n.kind)}</strong><small>{body(n)}</small><time title={date(n.received_at,true)}>{relative(n.received_at)}</time></span>{n.unread&&<i/>}</button>)}
   {center.feed&&!center.feed.items.some(n=>n.unread&&!n.archived)&&!center.failed&&<div className="nf-bell-empty"><StudioIcon name="check"/><strong>{t('noUnread')}</strong><span>{t('noUnreadHint')}</span></div>}
   <footer><Link href={notificationLinks?.index??'/admin/notifications'} onClick={e=>e.currentTarget.closest('details')?.removeAttribute('open')}>{t('viewAll')}<StudioIcon name="arrow"/></Link>{!!center.feed?.summary.unread&&<ActionTooltip label={t('markAll')}><button className="icon-button" disabled={center.mutating} aria-label={t('markAll')} onClick={()=>void center.update('read_all')}><StudioIcon name="check"/></button></ActionTooltip>}</footer>
  </div>
 </details>;
}

function NotificationDetails({detail,open,close}:{detail:NoticeDetail|null;open:boolean;close:()=>void}){
 const {t,body,locale}=useNotificationText(),date=useDisplayDate(),center=useAdminNotifications();const [copied,setCopied]=useState(false),timer=useRef<ReturnType<typeof setTimeout>|null>(null);
 useEffect(()=>{setCopied(false);return()=>{if(timer.current)clearTimeout(timer.current);};},[detail?.id]);
 if(!detail)return null;
 const openLabel=detail.target_type==='payment'?'openPayment':detail.target_type==='refund'?'openRefund':detail.target_type==='job'?'openJob':detail.target_type==='subscription'?'openSubscription':detail.target_type==='audit'?'openAudit':detail.target_type==='workers'?'openWorkers':detail.target_type==='schedules'?'openSchedules':'openSettings';
 const field=(key:string,v:string|number|boolean|null)=>{if(v===null||v==='')return '—';if(key.endsWith('_at')||key==='scheduled_start')return date(String(v),true);if(key.endsWith('_bytes'))return formatBytes(Number(v));if(key==='amount_minor')return new Intl.NumberFormat(locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US',{maximumFractionDigits:2}).format(Number(v)/100)+' '+(detail.context.currency??'');if(key==='progress')return v+'%';if(key==='status')return t(String(v));return String(v);};
 async function copy(){try{await navigator.clipboard.writeText(detail!.reference);setCopied(true);if(timer.current)clearTimeout(timer.current);timer.current=setTimeout(()=>setCopied(false),2000);}catch{/* Clipboard availability is optional. */}}
 return <MediaDialog open={open} title={t('details')} headerIcon="bell" headerDetail={detail.code} onClose={close} animated className="nf-panel">
  <div className="nf-panel-body">
   <section className="nf-detail-hero"><span className={'nf-event-icon is-'+detail.category}><StudioIcon name={noticeIcons[detail.category]}/></span><div><h2>{t(detail.kind)}</h2><p>{body(detail)}</p></div></section>
   <div className="nf-detail-badges"><NoticeBadge value={detail.priority}/><NoticeBadge value={detail.issue} issue/>{detail.archived&&<span className="nf-read-pill"><StudioIcon name="archive"/>{t('archived')}</span>}<span className="nf-read-pill"><StudioIcon name={detail.unread?'mail':'check'}/>{t(detail.unread?'unread':'read')}</span></div>
   <section className="nf-detail-section"><h3><StudioIcon name="info"/>{t('overview')}</h3><dl><dt>{t('received')}</dt><dd>{date(detail.received_at,true)}</dd>{detail.resolved_at&&<><dt>{t('resolved_at')}</dt><dd>{date(detail.resolved_at,true)}</dd></>}</dl></section>
   <section className="nf-detail-section"><h3><StudioIcon name="link"/>{t('related')}</h3><dl>{detail.customer.name&&<><dt>{t('customer')}</dt><dd>{detail.customer.name}<small>{detail.customer.email}</small></dd></>}<dt>{t('reference')}</dt><dd className="nf-reference">{detail.reference}<ActionTooltip label={copied?t('copied'):t('copy')}><button className="icon-button" aria-label={t('copy')} onClick={()=>void copy()}><StudioIcon name={copied?'check':'copy'}/></button></ActionTooltip>{copied&&<small role="status">{t('copied')}</small>}</dd>
   {Object.entries(detail.context).filter(([key,v])=>!['payment_id','currency'].includes(key)&&v!==null&&v!=='').map(([key,v])=><div className="nf-detail-pair" key={key}><dt>{t(key)}</dt><dd>{field(key,v)}</dd></div>)}</dl></section>
   {Object.keys(detail.current).length>0&&<section className="nf-detail-section"><h3><StudioIcon name="activity"/>{t('current')}</h3><dl>{Object.entries(detail.current).filter(([key,v])=>key!=='updated_at'&&v!==null&&v!=='').map(([key,v])=><div className="nf-detail-pair" key={key}><dt>{t(key)}</dt><dd>{field(key,v)}</dd></div>)}</dl></section>}
   <section className="nf-next-step"><h3><StudioIcon name="arrow"/>{t('nextStep')}</h3><p>{detail.related_url?t('nextStepHint'):t('unavailable')}</p></section><p className="nf-read-notice"><StudioIcon name="info"/>{t('readNotice')}</p>
   <div className="nf-detail-actions"><button className="media-button" disabled={center.mutating} onClick={()=>void center.update(detail.unread?'read':'unread',[detail.id])}><StudioIcon name={detail.unread?'check':'mail'}/>{t(detail.unread?'markRead':'markUnread')}</button><button className="media-button" disabled={center.mutating} onClick={()=>void center.update(detail.archived?'restore':'archive',[detail.id])}><StudioIcon name={detail.archived?'refresh':'archive'}/>{t(detail.archived?'restore':'archive')}</button></div>
  </div>
  <footer className="nf-panel-footer"><button className="media-button" onClick={close}>{t('close')}</button>{detail.related_url&&<Link href={detail.related_url} className="media-button primary" onClick={close}>{t(openLabel)}<StudioIcon name="arrow"/></Link>}</footer>
 </MediaDialog>;
}
