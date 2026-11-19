import {Link,usePage} from '@inertiajs/react';
import {useEffect,useLayoutEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {StudioIcon} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {mediaApi} from '@/lib/media-api';
import {useRecapText} from '@/lib/recap-i18n';
import type {SharedData} from '@/types';
import '../../css/customer-queue.css';

type QueueItem={id:number;title:string;type:string;status:string;progress:number;url:string};
type QueueFeed={total:number;running:number;queued:number;retrying:number;stopping:number;items:QueueItem[]};

export function CustomerQueue(){
 const {queueFeed,frameLinks}=usePage<SharedData>().props;
 const t=useRecapText();
 const [feed,setFeed]=useState<QueueFeed|null>(null),[open,setOpen]=useState(false),[unavailable,setUnavailable]=useState(false);
 const [position,setPosition]=useState({left:12,top:60});
 const root=useRef<HTMLDivElement>(null),button=useRef<HTMLButtonElement>(null),panel=useRef<HTMLDivElement>(null);
 const refresh=useRef<()=>void>(()=>{});
 useEffect(()=>{
  if(!queueFeed)return;
  let stopped=false,pending=false,timer:ReturnType<typeof setTimeout>,refreshAgain=false;
  const controller=new AbortController();
  const load=async()=>{
   clearTimeout(timer);
   if(stopped)return;
   if(document.hidden){timer=setTimeout(()=>void load(),15000);return;}
   if(pending){refreshAgain=true;return;}
   pending=true;let delay=5000;
   try{const data=await mediaApi<QueueFeed>(queueFeed,'GET',undefined,controller.signal);if(!stopped){setFeed(data);setUnavailable(false);delay=data.total?3000:10000;}}
   catch{if(!stopped)setUnavailable(true);}
   finally{pending=false;if(!stopped){timer=setTimeout(()=>void load(),refreshAgain?200:delay);refreshAgain=false;}}
  };
  refresh.current=()=>void load();
  const wake=()=>{if(!document.hidden)void load();};
  const changed=()=>{clearTimeout(timer);timer=setTimeout(()=>void load(),180);};
  void load();window.addEventListener('focus',wake);document.addEventListener('visibilitychange',wake);window.addEventListener('media-queue-changed',changed);
  return()=>{stopped=true;clearTimeout(timer);controller.abort();window.removeEventListener('focus',wake);document.removeEventListener('visibilitychange',wake);window.removeEventListener('media-queue-changed',changed);};
 },[queueFeed]);
 useLayoutEffect(()=>{
  if(!open)return;
  const locate=()=>{const rect=button.current?.getBoundingClientRect();if(rect)setPosition({left:Math.max(12,Math.min(rect.right-350,window.innerWidth-Math.min(350,window.innerWidth-24)-12)),top:Math.min(rect.bottom+12,window.innerHeight-180)});};
  locate();const focus=requestAnimationFrame(()=>panel.current?.focus());
  const outside=(event:PointerEvent)=>{if(!root.current?.contains(event.target as Node)&&!panel.current?.contains(event.target as Node))setOpen(false);};
  const escape=(event:KeyboardEvent)=>{if(event.key==='Escape'){setOpen(false);button.current?.focus();}};
  const close=()=>setOpen(false);
  document.addEventListener('pointerdown',outside);window.addEventListener('keydown',escape);window.addEventListener('resize',locate);window.addEventListener('scroll',close);
  return()=>{cancelAnimationFrame(focus);document.removeEventListener('pointerdown',outside);window.removeEventListener('keydown',escape);window.removeEventListener('resize',locate);window.removeEventListener('scroll',close);};
 },[open]);
 const status=(value:string)=>t(({running:'Running',queued:'Queued',retry_waiting:'Retry scheduled',stopping:'Stopping'} as Record<string,string>)[value]??value);
 const type=(value:string)=>t(({analysis:'Analyze',generate:'Generate',import:'Import Video',validation:'Validate',preview:'Quick Preview'} as Record<string,string>)[value]??value);
 return <div className="customer-queue" ref={root}>
  <ActionTooltip label={t('Queue jobs')}><button ref={button} type="button" className={'icon-button customer-queue-trigger'+(feed?.total?' has-work':'')} aria-label={t('Queue jobs')+(feed?' · '+feed.total:'')} aria-haspopup="dialog" aria-expanded={open} aria-controls="customer-queue-panel" onClick={()=>{setOpen(!open);if(!open)refresh.current();}}><StudioIcon name="jobs"/>{!!feed?.total&&<span className="customer-queue-count">{feed.total>99?'99+':feed.total}</span>}{unavailable&&<i className="customer-queue-offline"/>}</button></ActionTooltip>
  {open&&createPortal(<div ref={panel} id="customer-queue-panel" role="dialog" aria-label={t('Queue jobs')} tabIndex={-1} className="customer-queue-popover" data-fixed-theme="dark" style={position} onBlur={event=>{if(event.relatedTarget&&!event.currentTarget.contains(event.relatedTarget as Node)&&!root.current?.contains(event.relatedTarget as Node))setOpen(false);}}>
   <header><div><StudioIcon name="jobs"/><strong>{t('Queue jobs')}</strong></div><span>{feed?.total??'—'} {t('Active')}</span></header>
   {feed&&<div className="customer-queue-summary"><span><i/>{feed.running} {t('Running')}</span><span>{feed.queued} {t('Queued')}</span>{!!feed.retrying&&<span>{feed.retrying} {t('Retry scheduled')}</span>}</div>}
   {unavailable&&<p className="customer-queue-hint" role="status">{t('Status unavailable. Retrying…')}</p>}
   {!feed&&!unavailable&&<p className="customer-queue-empty"><StudioIcon name="loader" className="is-spinning"/>{t('Checking jobs…')}</p>}
   {feed?.items.length?<div className="customer-queue-items">{feed.items.map(item=><Link key={item.id} href={item.url} className="customer-queue-item" onClick={()=>setOpen(false)}><span className="customer-queue-item-icon"><StudioIcon name={item.status==='running'?'loader':'clock'} className={item.status==='running'?'is-spinning':''}/></span><div><strong>{item.title}</strong><small>{type(item.type)} · {status(item.status)} <b>{item.progress}%</b></small><progress max={100} value={item.progress}/></div><StudioIcon name="arrow"/></Link>)}</div>:feed&&!unavailable?<div className="customer-queue-empty"><StudioIcon name="check"/><strong>{t('No active jobs')}</strong></div>:null}
   <footer><small>{t('Jobs continue when you leave this page.')}</small><Link href={frameLinks.allProjects!} onClick={()=>setOpen(false)}>{t('All Projects')}<StudioIcon name="arrow"/></Link></footer>
  </div>,document.body)}
 </div>;
}