import {Head,usePage} from '@inertiajs/react';
import {useEffect,useRef,useState} from 'react';
import AppLayout from '@/layouts/app-layout';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {ActionTooltip} from '@/components/action-tooltip';
import {MediaDialog,SelectField,ConfirmDialog} from '@/components/media-workflow';
import {AttachmentPreview,AttachmentView,ContextContent,CopySupport,NewSupportConversation,PendingFiles,SafeMessage,SupportAvatar,SupportBadge,SupportPriorityMark,validFiles} from '@/components/support-inbox';
import {useSupportText} from '@/lib/support-i18n';
import {useDisplayDate} from '@/lib/platform-display';
import {mediaApi,MediaApiError} from '@/lib/media-api';
import {useToast} from '@/lib/toast';
import {useLoading} from '@/lib/loading';
import type {SharedData} from '@/types';
import type {SupportAttachment,SupportDetail,SupportFilters,SupportInboxData,SupportMessage,SupportRow} from '@/types/support';
import '../../../css/support.css';

const params=(f:SupportFilters)=>new URLSearchParams(Object.entries(f).filter(([,v])=>v!=='').map(([k,v])=>[k,String(v)])).toString();

function matchesList(row:SupportRow,f:SupportFilters){
 return (!f.category||row.category===f.category)&&(f.tab==='all'||f.tab==='unread'&&row.unread>0||f.tab==='needs_reply'&&row.needs_reply||['in_progress','resolved'].includes(f.tab)&&row.status===f.tab);
}
function compareRows(a:SupportRow,b:SupportRow,sort:string){
 if(sort==='waiting'){const group=Number(!a.needs_reply)-Number(!b.needs_reply);if(group)return group;const at=Date.parse(a.waiting_at??'1970-01-01T00:00:00Z')-Date.parse(b.waiting_at??'1970-01-01T00:00:00Z');if(at)return at;}
 else{const at=Date.parse(b.at)-Date.parse(a.at);if(at)return at;}
 return b.id-a.id;
}

function mergeMessages(previous:SupportMessage[],next:SupportMessage[],deletedIds:number[]=[]){
 const removed=new Set(deletedIds);
 return Array.from(new Map([...previous,...next].map(m=>[m.id,m])).values()).sort((a,b)=>a.id-b.id).map(m=>{
  if(m.deleted||removed.has(m.id))return {...m,deleted:true,can_delete:false,body:'',attachments:[],reply:null};
  return m.reply&&(m.reply.deleted||removed.has(m.reply.id))?{...m,reply:{...m.reply,body:'',deleted:true}}:m;
 });
}

type Draft={body:string;files:File[];quote:SupportMessage|null};
type SendKeys=Record<string,{signature:string;id:string}>;
export default function Support({inbox}:{inbox:SupportInboxData}){
 const{t,number}=useSupportText(),date=useDisplayDate(),toast=useToast(),{begin}=useLoading();
 const page=usePage<SharedData>().props;const [width,setWidth]=useState(window.innerWidth);useEffect(()=>{let previous=window.innerWidth;const resize=()=>{const next=window.innerWidth;if(previous>=1250&&next<1250)setContext(false);previous=next;setWidth(next);};window.addEventListener('resize',resize);return()=>window.removeEventListener('resize',resize);},[]);
 const[data,setData]=useState(inbox),[filters,setFilters]=useState(inbox.filters),[detail,setDetail]=useState<SupportDetail|null>(null),[context,setContext]=useState(()=>window.innerWidth>=1250),[create,setCreate]=useState(false),[stale,setStale]=useState(false),[opening,setOpening]=useState(false),[mutating,setMutating]=useState(false),[olderBusy,setOlderBusy]=useState(false),[preview,setPreview]=useState<SupportAttachment|null>(null),[jump,setJump]=useState(false),[confirm,setConfirm]=useState(false),[newReply,setNewReply]=useState<SupportMessage|null>(null),[refreshing,setRefreshing]=useState(false),[moreBusy,setMoreBusy]=useState(false),[moreError,setMoreError]=useState(false),[deleting,setDeleting]=useState<SupportMessage|null>(null),[away,setAway]=useState(false),[newCount,setNewCount]=useState(0),[highlight,setHighlight]=useState<number|null>(null),[editing,setEditing]=useState<{id:number;body:string}|null>(null),[editBusy,setEditBusy]=useState(false);
 const listData=useRef(data),listScroller=useRef<HTMLDivElement>(null),listSentinel=useRef<HTMLDivElement>(null),moreRef=useRef<HTMLDetailsElement>(null),moreRequest=useRef<AbortController|null>(null),morePending=useRef(false),deletingRequest=useRef(false);listData.current=data;
 const alive=useRef(true),listRequest=useRef<AbortController|null>(null),threadRequest=useRef<AbortController|null>(null),actionRequest=useRef<AbortController|null>(null),historyRequest=useRef<AbortController|null>(null),listSerial=useRef(0),threadSerial=useRef(0),current=useRef(detail),latestFilters=useRef(filters),lastFilter=useRef(JSON.stringify(inbox.filters)),scroller=useRef<HTMLDivElement>(null),nearBottom=useRef(true),ack=useRef<Record<number,number>>({}),drafts=useRef<Record<string,Draft>>({}),sendKeys=useRef<SendKeys>({}),mutatingRef=useRef(false),thread=useRef<HTMLDivElement>(null),highlightTimer=useRef<ReturnType<typeof setTimeout>|undefined>(undefined),olderPending=useRef(false);
 current.current=detail;latestFilters.current=filters;mutatingRef.current=mutating;
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;listRequest.current?.abort();moreRequest.current?.abort();threadRequest.current?.abort();actionRequest.current?.abort();historyRequest.current?.abort();clearTimeout(highlightTimer.current);};},[]);
 // Keep the newest message in view while pinned, even after images load.
 useEffect(()=>{const content=thread.current;if(!content||typeof ResizeObserver==='undefined')return;const observer=new ResizeObserver(()=>{const el=scroller.current;if(el&&nearBottom.current)el.scrollTop=el.scrollHeight;});observer.observe(content);return()=>observer.disconnect();},[detail?.id]);
 // Messenger-style history: load the previous page near the top and fill short threads.
 useEffect(()=>{const frame=requestAnimationFrame(()=>maybeOlder());return()=>cancelAnimationFrame(frame);});
 async function reload(f=latestFilters.current,notify=false,reset=false){
  if(morePending.current&&!reset)return;
  listRequest.current?.abort();const a=new AbortController();listRequest.current=a;const serial=++listSerial.current;
  try{const result=await mediaApi<SupportInboxData>(data.endpoints.index+'?'+params(f),'GET',undefined,a.signal);if(alive.current&&serial===listSerial.current&&params(f)===params(latestFilters.current)){
   const root=listScroller.current;const anchor=root&&root.scrollTop>0?Array.from(root.querySelectorAll<HTMLButtonElement>('[data-conversation-id]')).find(row=>row.getBoundingClientRect().bottom>root.getBoundingClientRect().top):null;const anchorId=anchor?.dataset.conversationId,anchorOffset=anchor&&root?anchor.getBoundingClientRect().top-root.getBoundingClientRect().top:0;
   setData(old=>{
    if(reset||params(old.filters)!==params(f)||!result.batch.has_more)return result;
    const items=Array.from(new Map([...old.items,...result.items].map(row=>[row.id,row])).values()).filter(row=>matchesList(row,f)).sort((a,b)=>compareRows(a,b,f.sort));
    return {...result,items,batch:old.items.length>20?{...old.batch,total:result.batch.total}:result.batch};
   });if(anchorId)requestAnimationFrame(()=>{if(params(f)!==params(latestFilters.current))return;const row=listScroller.current?.querySelector<HTMLElement>('[data-conversation-id="'+anchorId+'"]');if(row&&listScroller.current)listScroller.current.scrollTop+=row.getBoundingClientRect().top-listScroller.current.getBoundingClientRect().top-anchorOffset;});setStale(false);if(notify)toast.success(t('updated'));
  }}
  catch{if(alive.current&&!a.signal.aborted){setStale(true);if(notify)toast.error(t('error'));}}
 }
 async function loadMore(){
  const f=latestFilters.current,d=listData.current;if(morePending.current||!d.batch.has_more||!d.batch.next_cursor||params(f)!==params(d.filters))return;
  morePending.current=true;setMoreBusy(true);setMoreError(false);listRequest.current?.abort();++listSerial.current;
  const a=new AbortController();moreRequest.current=a;const key=params(f),cursor=d.batch.next_cursor;
  try{const result=await mediaApi<SupportInboxData>(data.endpoints.index+'?'+key+'&after='+encodeURIComponent(cursor),'GET',undefined,a.signal);if(alive.current&&!a.signal.aborted&&key===params(latestFilters.current)){
   setData(old=>params(old.filters)!==key?old:{...result,items:Array.from(new Map([...old.items,...result.items].map(row=>[row.id,row])).values()).filter(row=>matchesList(row,f)).sort((a,b)=>compareRows(a,b,f.sort))});
  }}
  catch{if(alive.current&&!a.signal.aborted&&key===params(latestFilters.current))setMoreError(true);}
  finally{if(moreRequest.current===a){morePending.current=false;if(alive.current)setMoreBusy(false);}}
 }

 useEffect(()=>{
  const close=(event:PointerEvent)=>{if(moreRef.current?.open&&!moreRef.current.contains(event.target as Node))moreRef.current.removeAttribute('open');};
  const escape=(event:KeyboardEvent)=>{if(event.key==='Escape'&&!event.defaultPrevented&&moreRef.current?.open){moreRef.current.removeAttribute('open');moreRef.current.querySelector('summary')?.focus();}};
  document.addEventListener('pointerdown',close);document.addEventListener('keydown',escape);
  return()=>{document.removeEventListener('pointerdown',close);document.removeEventListener('keydown',escape);};
 },[]);
 useEffect(()=>{
  if(!data.batch.has_more||moreBusy||moreError||!listScroller.current||!listSentinel.current)return;
  const observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting))void loadMore();},{root:listScroller.current,rootMargin:'0px 0px 160px 0px'});
  observer.observe(listSentinel.current);return()=>observer.disconnect();
 },[data.batch.next_cursor,data.batch.has_more,data.items.length,moreBusy,moreError,filters]);

 useEffect(()=>{const key=JSON.stringify(filters);if(lastFilter.current===key)return;moreRequest.current?.abort();moreRequest.current=null;morePending.current=false;setMoreBusy(false);setMoreError(false);if(listScroller.current)listScroller.current.scrollTop=0;const timer=setTimeout(()=>{lastFilter.current=key;void reload(filters,false,true);},280);return()=>clearTimeout(timer);},[filters]);
 async function read(d:SupportDetail){
  if(document.visibilityState!=='visible'||!nearBottom.current||d.latest_id<=(ack.current[d.id]??d.read_through))return;
  const through=d.latest_id;ack.current[d.id]=through;
  try{await mediaApi(data.endpoints.index+'/'+d.id+'/read','POST',{through});if(alive.current){setData(old=>({...old,items:old.items.map(row=>row.id===d.id?{...row,unread:0}:row).filter(row=>matchesList(row,latestFilters.current))}));void reload();}}
  catch{delete ack.current[d.id];}
 }
 function toBottom(smooth=false){const el=scroller.current;if(!el)return;nearBottom.current=true;el.scrollTo({top:el.scrollHeight,behavior:smooth?'smooth':'auto'});setJump(false);setAway(false);setNewCount(0);if(current.current)void read(current.current);}
 async function load(id:number,blocking=false){
  threadRequest.current?.abort();const a=new AbortController();threadRequest.current=a;const serial=++threadSerial.current;
  if(blocking)setOpening(true);const finish=blocking?begin():()=>{};
  try{const loaded=current.current?.id===id?current.current.messages[0]?.id:null,since=current.current?.id===id?current.current.snapshot:undefined;let result=await mediaApi<SupportDetail>(data.endpoints.index+'/'+id+(loaded?'?loaded_from='+loaded+(since!==undefined?'&since='+since:''):''),'GET',undefined,a.signal);if(result.reset)result=await mediaApi<SupportDetail>(data.endpoints.index+'/'+id,'GET',undefined,a.signal);if(!alive.current||a.signal.aborted||serial!==threadSerial.current)return;
   const previous=current.current,same=previous?.id===id&&!result.reset,newMessages=!same||result.latest_id>(previous?.latest_id??0);
   const merged=mergeMessages(same?previous.messages:[],[...result.messages,...(result.changes??[])],result.deleted_ids);
   const next={...result,messages:merged,has_older:same?previous.has_older:result.has_older,read_through:same?previous.read_through:result.read_through};
   const arrived=same?result.messages.filter(m=>m.id>(previous?.latest_id??0)&&m.sender_kind==='customer').length:0;
   setDetail(next);setStale(false);
   if(!same){moreRef.current?.removeAttribute('open');nearBottom.current=true;setJump(false);setAway(false);setNewCount(0);setEditing(null);setNewReply(null);historyRequest.current?.abort();history.replaceState(null,'',data.endpoints.index+'?conversation='+id);}
   if(newMessages){if(nearBottom.current)requestAnimationFrame(()=>{if(alive.current&&scroller.current){scroller.current.scrollTop=scroller.current.scrollHeight;void read(next);}});else{setJump(true);setNewCount(count=>count+arrived);}}
   else if(nearBottom.current)void read(next);
  }catch{if(alive.current&&!a.signal.aborted){setStale(true);if(blocking)toast.error(t('error'));}}
  finally{finish();if(alive.current&&serial===threadSerial.current)setOpening(false);}
 }
 useEffect(()=>{if(inbox.selected)void load(inbox.selected,true);},[]);
 useEffect(()=>{const timer=setInterval(()=>{if(document.visibilityState==='visible'&&!mutatingRef.current){void reload();if(current.current&&!opening)void load(current.current.id);}},8000);const onVisible=()=>{if(document.visibilityState==='visible'){if(current.current)void load(current.current.id);void reload();}};document.addEventListener('visibilitychange',onVisible);return()=>{clearInterval(timer);document.removeEventListener('visibilitychange',onVisible);};},[opening]);
 function change<K extends keyof SupportFilters>(key:K,value:SupportFilters[K]){
  listRequest.current?.abort();++listSerial.current;moreRequest.current?.abort();moreRequest.current=null;morePending.current=false;setMoreBusy(false);setMoreError(false);
  if(listScroller.current)listScroller.current.scrollTop=0;setFilters(old=>({...old,[key]:value}));
 }
 async function update(values:Record<string,string>){
  const d=current.current;if(!d||mutatingRef.current)return;setMutating(true);threadRequest.current?.abort();const a=new AbortController();actionRequest.current=a;
  try{await mediaApi(data.endpoints.index+'/'+d.id,'PATCH',{revision:d.revision,...values},a.signal);if(alive.current){await load(d.id);await reload();toast.success(t('updated'));}}
  catch(error){if(alive.current&&!a.signal.aborted){toast.error(error instanceof MediaApiError&&error.status===409?t('conflict'):t('error'));if(error instanceof MediaApiError&&error.status===409)await load(d.id);}}
  finally{if(alive.current){setMutating(false);setConfirm(false);}}
 }
 async function removeMessage(){
  const d=current.current,target=deleting;if(!d||!target?.can_delete||deletingRequest.current)return;
  deletingRequest.current=true;mutatingRef.current=true;setMutating(true);threadRequest.current?.abort();const a=new AbortController();actionRequest.current=a;
  try{await mediaApi(data.endpoints.index+'/'+d.id+'/messages/'+target.id,'DELETE',undefined,a.signal);if(alive.current){
   if(current.current?.id===d.id){setDetail(old=>old?{...old,deleted_ids:[...(old.deleted_ids??[]),target.id],messages:mergeMessages(old.messages,[],[target.id])}:old);setNewReply(null);setPreview(null);await load(d.id);}
   setDeleting(null);await reload();toast.success(t('messageDeleted'));
  }}catch{if(alive.current&&!a.signal.aborted)toast.error(t('error'));}
  finally{deletingRequest.current=false;mutatingRef.current=false;if(alive.current)setMutating(false);}
 }
 async function older(until?:number):Promise<boolean>{
  const d=current.current,el=scroller.current;if(!d||!el||!d.has_older||!d.messages.length||olderPending.current)return false;olderPending.current=true;setOlderBusy(true);const a=new AbortController();historyRequest.current=a;
  try{const result=await mediaApi<SupportDetail>(data.endpoints.index+'/'+d.id+'?before='+d.messages[0].id+(until?'&until='+until:''),'GET',undefined,a.signal);if(!alive.current||current.current?.id!==d.id)return false;
   const height=el.scrollHeight,top=el.scrollTop;const next={...current.current,has_older:result.has_older,messages:mergeMessages(current.current.messages,result.messages,result.deleted_ids)};current.current=next;setDetail(next);
   await new Promise<void>(resolve=>requestAnimationFrame(()=>{if(scroller.current)scroller.current.scrollTop=top+scroller.current.scrollHeight-height;resolve();}));return true;}
  catch{if(!a.signal.aborted)toast.error(t('error'));return false;}finally{olderPending.current=false;if(alive.current)setOlderBusy(false);}
 }
 function maybeOlder(){const el=scroller.current,d=current.current;if(el&&d?.has_older&&!olderPending.current&&el.scrollTop<140)void older();}
 async function jumpTo(id:number){
  const find=()=>document.getElementById('support-message-'+id);let el=find();
  if(!el&&current.current?.has_older&&id<(current.current.messages[0]?.id??0)){historyRequest.current?.abort();olderPending.current=false;await older(id);el=find();}
  if(!el){toast.error(t('messageUnavailable'));return;}
  nearBottom.current=false;el.scrollIntoView({block:'center',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
  setHighlight(id);clearTimeout(highlightTimer.current);highlightTimer.current=setTimeout(()=>setHighlight(null),1800);
 }
 async function saveEdit(){
  const d=current.current,target=editing;if(!d||!target||editBusy)return;const body=target.body.trim();const original=d.messages.find(m=>m.id===target.id);
  if(!body){toast.error(t('editEmpty'));return;}if(original&&original.body===body){setEditing(null);return;}
  setEditBusy(true);mutatingRef.current=true;
  try{await mediaApi(data.endpoints.index+'/'+d.id+'/messages/'+target.id,'PATCH',{body});if(!alive.current)return;
   const at=new Date().toISOString();setDetail(old=>old?{...old,messages:old.messages.map(m=>m.id===target.id?{...m,body,edited:true,edited_at:at}:m.reply?.id===target.id?{...m,reply:{...m.reply,body:Array.from(body).slice(0,180).join('')}}:m)}:old);
   setEditing(null);toast.success(t('messageEdited'));await load(d.id);void reload();}
  catch(error){if(alive.current)toast.error(error instanceof MediaApiError&&error.status===422?Object.values(error.fields).flat()[0]??t('error'):t('error'));}
  finally{mutatingRef.current=false;if(alive.current)setEditBusy(false);}
 }
 async function refresh(){if(refreshing)return;setRefreshing(true);await reload(undefined,true);if(current.current)await load(current.current.id);if(alive.current)setRefreshing(false);}
 return <AppLayout area="admin" adminPage="support" frame="dashboard" breadcrumbLabel={t('title')}><Head title={t('title')}/><section className={'support-page'+(detail?' is-chat':'')+(context&&detail?' has-context':'')}>
  <h1 className="sr-only">{t('title')}</h1><header className="sp-heading"><div className="sp-counters" role="group" aria-label={t('title')}>{['all','unread','needs_reply','in_progress','resolved'].map(key=><button type="button" key={key} aria-pressed={filters.tab===key} title={t(key)} className={'sp-counter is-'+key+(filters.tab===key?' selected':'')} onClick={()=>change('tab',key)}><span>{t(key)}</span><strong>{number(data.counts[key]??0)}</strong></button>)}</div><button type="button" className="media-button primary" onClick={()=>setCreate(true)}><StudioIcon name="chat"/>{t('newConversation')}</button></header>
  {stale&&<div className="sp-connection" role="status"><StudioIcon name="alert"/>{t('stale')}<button onClick={()=>void refresh()}>{t('retry')}</button></div>}
  <div className="sp-board">
   <aside className="sp-list"><div className="sp-list-tools"><label className="sp-search"><StudioIcon name="search"/><input value={filters.q} onChange={e=>change('q',e.target.value)} placeholder={t('search')} aria-label={t('search')} autoComplete="off"/></label>
    <div className="sp-list-filters"><SelectField label={t('category')} value={filters.category} onChange={v=>change('category',v)} options={[{value:'',label:t('categories')},...['video','payment','subscription','account','general'].map(value=>({value,label:t(value)}))]}/><SelectField label={t('latest')} value={filters.sort} onChange={v=>change('sort',v)} options={['latest','waiting'].map(value=>({value,label:t(value)}))}/></div></div>
    <div className="sp-conversations" ref={listScroller}>{data.items.length?data.items.map(row=><button key={row.id} data-conversation-id={row.id} className={'sp-conversation'+(detail?.id===row.id?' selected':'')+(row.unread?' unread':'')} aria-current={detail?.id===row.id?true:undefined} onClick={()=>{if(!opening)void load(row.id,true);}}><SupportAvatar customer={row.customer}/><span className="sp-conversation-main"><span className="sp-conversation-name"><strong>{row.customer.name}</strong><time title={date(row.at,true)}>{date(row.at,false)===date(new Date().toISOString(),false)?timeOnly(row.at):date(row.at,false)}</time></span><span className={'sp-conversation-preview'+(row.preview_sender==='admin'?' is-outgoing':row.preview_sender==='customer'?' is-incoming':'')}>{row.preview_sender&&<><b>{t(row.preview_sender==='admin'?'previewSent':'previewReceived')}</b></>}<span>{row.preview_deleted?t('messageDeleted'):row.preview||t(row.preview_sender?'attachment':'noMessages')}</span></span></span><span className="sp-conversation-indicators">{row.unread>0&&<b>{number(row.unread)}</b>}<SupportPriorityMark value={row.priority}/>{row.needs_reply&&<i aria-label={t('needs_reply')}/>}</span></button>):<div className="sp-empty"><StudioIcon name="chat"/><h3>{data.counts.all?t('noMatch'):t('empty')}</h3><p>{t('emptyHint')}</p>{data.counts.all>0&&<button className="media-button" onClick={()=>setFilters({...inbox.filters,q:'',category:'',tab:'all',sort:'latest'})}>{t('clear')}</button>}</div>}<div ref={listSentinel} className="sp-list-end" aria-live="polite">{moreBusy?<><StudioIcon name="loader" className="is-spinning"/>{t('loadingMore')}</>:moreError?<button type="button" className="media-button" onClick={()=>void loadMore()}>{t('retry')}</button>:data.items.length>0?<span>{number(data.items.length)} / {number(data.batch.total)} · {t(data.batch.has_more?'scrollMore':'allLoaded')}</span>:null}</div></div>

   </aside>
   <section className="sp-chat">{detail?<><header className="sp-chat-header"><button className="icon-button sp-mobile-back" onClick={()=>{threadRequest.current?.abort();setDetail(null);history.replaceState(null,'',data.endpoints.index);}} aria-label={t('back')}><StudioIcon name="back"/></button><SupportAvatar customer={detail.customer}/><div className="sp-chat-identity"><strong>{detail.customer.name}</strong><span>{detail.customer.email}<CopySupport value={detail.customer.email}/><i/> <StudioIcon name="calendar"/>{detail.code}</span></div><div className="sp-chat-actions"><SupportPriorityMark value={detail.priority}/><SelectField label={t('status')} value={detail.status} onChange={status=>{if(status==='resolved')setConfirm(true);else void update({status});}} disabled={mutating} options={['open','in_progress','waiting_customer','resolved'].map(value=>({value,label:t(value)}))}/><ActionTooltip label={t('pin')}><button className={'icon-button'+(context?' selected':'')} aria-label={t('pin')} aria-pressed={context} onClick={()=>setContext(!context)}><StudioIcon name="users"/></button></ActionTooltip><details className="sp-more" ref={moreRef}><summary className="icon-button" aria-label={t('more')}><StudioIcon name="more"/></summary><div><label>{t('priority')}<SelectField label={t('priority')} value={detail.priority} onChange={priority=>void update({priority})} disabled={mutating} options={['normal','high','urgent'].map(value=>({value,label:t(value)}))}/></label><label>{t('category')}<SelectField label={t('category')} value={detail.category} onChange={category=>void update({category})} disabled={mutating} options={['video','payment','subscription','account','general'].map(value=>({value,label:t(value)}))}/></label><button className="media-button" onClick={()=>{if(detail.status==='resolved')void update({status:'open'});else setConfirm(true);}}><StudioIcon name="check"/>{t(detail.status==='resolved'?'reopen':'resolve')}</button><button className="media-button" onClick={()=>void refresh()}><StudioIcon name={refreshing?'loader':'refresh'} className={refreshing?'is-spinning':''}/>{t('refresh')}</button></div></details></div></header>
    <div className="sp-messages-wrap"><div ref={scroller} className="sp-messages" onScroll={()=>{const el=scroller.current;if(!el)return;const distance=el.scrollHeight-el.scrollTop-el.clientHeight;nearBottom.current=distance<70;setAway(distance>320);if(nearBottom.current){setJump(false);setNewCount(0);if(current.current)void read(current.current);}maybeOlder();}} onLoadCapture={()=>{if(nearBottom.current&&scroller.current)scroller.current.scrollTop=scroller.current.scrollHeight;}}>
     {detail.related&&<div className="sp-related-card"><span><StudioIcon name={detail.related.type==='job'?'play':detail.related.type==='payment'?'wallet':'crown'}/></span><div><small>{t('related')}</small><strong>{detail.related.title}</strong><div>{detail.related.code} <SupportBadge value={detail.related.status}/></div></div>{detail.related.url&&<a className="media-button" href={detail.related.url}>{t('view')}<StudioIcon name="arrow"/></a>}</div>}
     <div ref={thread} className="sp-thread">
     {olderBusy?<div className="sp-history-loading" role="status"><span className="sp-typing" aria-hidden="true"><i/><i/><i/></span>{t('loadingOlder')}</div>:detail.has_older?<button className="sp-older" onClick={()=>void older()}>{t('older')}</button>:<div className="sp-history-start"><span>{t('historyStart')}</span></div>}
     {detail.messages.map((m,index)=>{const day=date(m.at,false),previous=index?date(detail.messages[index-1].at,false):'';const firstUnread=!m.deleted&&m.sender_kind==='customer'&&m.id>detail.read_through&&!detail.messages.slice(0,index).some(p=>!p.deleted&&p.sender_kind==='customer'&&p.id>detail.read_through);return <div key={m.id} id={'support-message-'+m.id} className={highlight===m.id?'sp-highlighted':undefined}>
      {day!==previous&&<div className="sp-day"><span>{day}</span></div>}{firstUnread&&<div className="sp-unread-line">{t('unread')}</div>}
      {m.kind==='event'?<div className="sp-event"><StudioIcon name="check"/><span>{Object.entries(m.event??{}).map(([k,v])=>t(k)+': '+t(v)).join(' · ')} · {m.sender}</span><time>{timeOnly(m.at)}</time></div>:<div className={'sp-message is-'+m.sender_kind+(m.kind==='note'?' is-note':'')}>
       {m.sender_kind==='customer'&&<SupportAvatar customer={detail.customer} small/>}<div className="sp-message-content">
        {m.kind==='note'&&<strong className="sp-note-label"><StudioIcon name="key"/>{t('note')} · {t('private')}</strong>}
        {m.deleted?<div className="sp-bubble sp-deleted"><StudioIcon name="ban"/><span>{t('messageDeleted')}</span></div>:<>
         {m.reply&&<button type="button" title={t('jumpToOriginal')} disabled={m.reply.deleted} className={'sp-quote'+(m.reply.deleted?' is-deleted':'')} onClick={()=>void jumpTo(m.reply!.id)}><StudioIcon name="reply"/>{m.reply.deleted?t('messageDeleted'):m.reply.body||t('attachment')}</button>}
         {editing?.id===m.id?<div className="sp-edit-box"><textarea autoFocus value={editing.body} maxLength={4000} aria-label={t('editMessage')} disabled={editBusy} rows={Math.min(8,Math.max(2,editing.body.split('\n').length))}
           onChange={e=>setEditing({id:m.id,body:e.target.value})} onKeyDown={e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.nativeEvent.isComposing){e.preventDefault();void saveEdit();}else if(e.key==='Escape'){e.preventDefault();setEditing(null);}}}/>
           <div><small>{t('editHint')}</small><button type="button" className="media-button" disabled={editBusy} onClick={()=>setEditing(null)}>{t('cancelEdit')}</button><button type="button" className="media-button primary" disabled={editBusy||!editing.body.trim()} onClick={()=>void saveEdit()}>{editBusy&&<StudioIcon name="loader" className="is-spinning"/>}{t('saveEdit')}</button></div></div>
          :m.body&&<div className="sp-bubble"><SafeMessage text={m.body}/></div>}{m.attachments.map(file=><AttachmentView key={file.id} file={file} onPreview={setPreview}/>)}
        </>}
        <div className="sp-message-meta"><time title={date(m.at,true)}>{timeOnly(m.at)}</time>{m.edited&&<span className="sp-edited" title={m.edited_at?t('edited')+' · '+date(m.edited_at,true):t('edited')}>{t('edited')}</span>}{!m.deleted&&<>
         <ActionTooltip label={t('replyAction')}><button type="button" aria-label={t('replyAction')} onClick={()=>setNewReply(m)}><StudioIcon name="reply"/></button></ActionTooltip>
         {m.can_edit&&editing?.id!==m.id&&<ActionTooltip label={t('editMessage')}><button type="button" aria-label={t('editMessage')} disabled={mutating||editBusy} onClick={()=>setEditing({id:m.id,body:m.body})}><StudioIcon name="edit"/></button></ActionTooltip>}
         {m.can_delete&&<ActionTooltip label={t('deleteMessage')}><button type="button" className="sp-delete-message" aria-label={t('deleteMessage')} disabled={mutating} onClick={()=>setDeleting(m)}><StudioIcon name="trash"/></button></ActionTooltip>}
        </>}</div>
       </div></div>}
     </div>;})}
    </div></div>{(away||jump)&&<button type="button" className="sp-jump-bottom" title={t('jumpLatest')} aria-label={t('jumpLatest')+(newCount?' · '+newCount+' '+t('newMessages'):'')} onClick={()=>toBottom(true)}><StudioIcon name="chevron"/>{newCount>0&&<span>{newCount>99?'99+':newCount}</span>}</button>}</div>
    <SupportComposer key={detail.id} detail={detail} endpoint={data.endpoints.index} drafts={drafts.current} sendKeys={sendKeys.current} quote={newReply} onQuoteUsed={()=>setNewReply(null)} onSending={setMutating} onSent={async()=>{nearBottom.current=true;await load(detail.id);await reload();}}/>
   </>:<div className="sp-empty sp-chat-empty"><span><StudioIcon name="chat"/></span><h2>{t('select')}</h2><p>{t('selectHint')}</p><small>{t('integration')}</small></div>}</section>
   {detail&&context&&<aside className="sp-context"><header><StudioIcon name="users"/><strong>{t('customerContext')}</strong><button className="sp-copy" onClick={()=>setContext(false)} aria-label={t('close')}><StudioIcon name="close"/></button></header><ContextContent detail={detail}/></aside>}
  </div>
  {detail&&<MediaDialog animated open={context&&width<1250} title={t('customerContext')} onClose={()=>setContext(false)} className="sp-context-dialog" headerIcon="users"><div className="sp-context-dialog-body"><ContextContent detail={detail}/></div></MediaDialog>}
  {create&&<NewSupportConversation endpoint={data.endpoints.options} onClose={()=>setCreate(false)} onCreated={id=>{setCreate(false);void reload();void load(id,true);}}/>}
  <ConfirmDialog open={!!deleting} message={t('deleteMessageHint')} onClose={()=>{if(!mutating)setDeleting(null);}} onConfirm={()=>void removeMessage()} busy={mutating}/>
  <AttachmentPreview file={preview} onClose={()=>setPreview(null)}/>
  <ConfirmDialog open={confirm} message={t('resolveHint')} onClose={()=>{if(!mutating)setConfirm(false);}} onConfirm={()=>void update({status:'resolved'})} busy={mutating}/>
 </section></AppLayout>;
 function timeOnly(value:string){const{app,locale}=page;return new Intl.DateTimeFormat(locale==='my'?'my-MM':locale==='th'?'th-TH':'en',{hour:'2-digit',minute:'2-digit',timeZone:app.timezone??'UTC',hour12:app.time_format==='12'}).format(new Date(value));}
}

function SupportComposer({detail,endpoint,drafts,sendKeys,quote,onQuoteUsed,onSending,onSent}:{detail:SupportDetail;endpoint:string;drafts:Record<string,Draft>;sendKeys:SendKeys;quote:SupportMessage|null;onQuoteUsed:()=>void;onSending:(value:boolean)=>void;onSent:()=>Promise<void>}){
 const{t}=useSupportText(),toast=useToast(),{auth}=usePage<SharedData>().props;
 const[kind,setKind]=useState<'reply'|'note'>('reply'),[values,setValues]=useState<Record<string,Draft>>(()=>{
  const read=(mode:string):Draft=>{const key=auth.user?.email+':'+detail.id+':'+mode;if(drafts[key])return drafts[key];try{return {body:sessionStorage.getItem('zatlan.support.draft:'+key)??'',files:[],quote:null};}catch{return{body:'',files:[],quote:null};}};
  return{reply:read('reply'),note:read('note')};
 }),[busy,setBusy]=useState(false),[error,setError]=useState(''),[quick,setQuick]=useState(false),[emoji,setEmoji]=useState(false);
 const current=values[kind],input=useRef<HTMLTextAreaElement>(null),file=useRef<HTMLInputElement>(null),alive=useRef(true),controller=useRef<AbortController|null>(null),sending=useRef(false),menu=useRef<HTMLDivElement>(null),emojiMenu=useRef<HTMLDivElement>(null);
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;controller.current?.abort();};},[]);
 useEffect(()=>{for(const mode of ['reply','note']){const key=auth.user?.email+':'+detail.id+':'+mode;drafts[key]=values[mode];try{if(values[mode].body)sessionStorage.setItem('zatlan.support.draft:'+key,values[mode].body);else sessionStorage.removeItem('zatlan.support.draft:'+key);}catch{}}},[values]);
 useEffect(()=>{if(!quote)return;const mode=quote.kind==='note'?'note':kind;setKind(mode);setValues(old=>({...old,[mode]:{...old[mode],quote}}));onQuoteUsed();input.current?.focus();},[quote]);
 useEffect(()=>{const close=(e:PointerEvent)=>{if(!menu.current?.contains(e.target as Node))setQuick(false);if(!emojiMenu.current?.contains(e.target as Node))setEmoji(false);};document.addEventListener('pointerdown',close);return()=>document.removeEventListener('pointerdown',close);},[]);
 useEffect(()=>{const el=input.current;if(!el)return;el.style.height='0px';el.style.height=Math.min(84,Math.max(36,el.scrollHeight))+'px';},[current.body]);
 useEffect(()=>{const removed=new Set(detail.deleted_ids??[]);setValues(old=>{let next=old;for(const mode of ['reply','note'])if(old[mode].quote&&(old[mode].quote!.deleted||removed.has(old[mode].quote!.id))){next={...next,[mode]:{...old[mode],quote:null}};}return next;});},[detail.deleted_ids]);
 function patch(value:Partial<Draft>){setValues(old=>({...old,[kind]:{...old[kind],...value}}));setError('');}
 function insert(before:string,after=''){const el=input.current,start=el?.selectionStart??current.body.length,end=el?.selectionEnd??start;patch({body:(current.body.slice(0,start)+before+current.body.slice(start,end)+after+current.body.slice(end)).slice(0,4000)});setQuick(false);setEmoji(false);requestAnimationFrame(()=>{el?.focus();el?.setSelectionRange(start+before.length,end+before.length);});}
 async function send(){
  if(sending.current)return;if(!current.body.trim()&&!current.files.length){input.current?.focus();return;}if(!validFiles(current.files)){setError(t('fileError'));return;}
  const sentKind=kind,sentDraft=current;const signature=JSON.stringify([detail.id,kind,current.body,current.quote?.id,current.files.map(f=>[f.name,f.size,f.lastModified])]);const sendKey=auth.user?.email+':'+detail.id+':'+kind;if(sendKeys[sendKey]?.signature!==signature)sendKeys[sendKey]={signature,id:crypto.randomUUID()};
  const form=new FormData();form.append('kind',kind);form.append('body',current.body);form.append('request_id',sendKeys[sendKey].id);if(current.quote)form.append('reply_to',String(current.quote.id));current.files.forEach(f=>form.append('files[]',f));
  sending.current=true;setBusy(true);onSending(true);setError('');const a=new AbortController();controller.current=a;
  try{await mediaApi(endpoint+'/'+detail.id+'/messages','POST',form,a.signal);if(alive.current){setValues(old=>({...old,[sentKind]:old[sentKind]===sentDraft?{body:'',files:[],quote:null}:old[sentKind]}));delete sendKeys[sendKey];await onSent();input.current?.focus();}}
  catch(err){if(alive.current&&!a.signal.aborted){const field=err instanceof MediaApiError?Object.values(err.fields).flat()[0]:null;setError(field??t('failed'));toast.error(t('failed'));}}
  finally{sending.current=false;onSending(false);if(alive.current)setBusy(false);}
 }
 return <form className={'sp-composer'+(kind==='note'?' is-note':'')} onSubmit={e=>{e.preventDefault();void send();}}>
  <div className="sp-composer-top"><div className="sp-composer-tabs" role="group" aria-label={t('message')}>{(['reply','note'] as const).map(value=><button type="button" key={value} className={kind===value?'selected':''} onClick={()=>{setKind(value);setError('');}}>{value==='note'&&<StudioIcon name="key"/>}{t(value)}</button>)}<span>{current.body.length>0?t('draft'):''}</span></div>
  <div className="sp-format-tools">{[['bold','B'],['italic','I'],['bullet','•']] .map(([key,label])=><ActionTooltip key={key} label={t(key)}><button type="button" aria-label={t(key)} onClick={()=>key==='bold'?insert('**','**'):key==='italic'?insert('_','_'):insert((current.body?'\n':'')+'• ')}>{label}</button></ActionTooltip>)}<span className="sp-format-spacer"/><div className="sp-quick-wrap" ref={menu}><button type="button" className="sp-quick-trigger" title={t('quick')} aria-label={t('quick')} onClick={()=>setQuick(!quick)} aria-expanded={quick}><StudioIcon name="sparkles"/></button>{quick&&<div className="sp-quick-menu" role="group" aria-label={t('quick')}>{['quickScreenshot','quickPayment','quickJob'].map(key=><button type="button" key={key} onClick={()=>insert(t(key))}>{t(key)}</button>)}</div>}</div><div ref={emojiMenu} className="sp-emoji-wrap"><button type="button" aria-label={t('emoji')} aria-expanded={emoji} className="sp-emoji-trigger" onClick={()=>setEmoji(!emoji)}><StudioIcon name="smile"/></button>{emoji&&<div className="sp-emoji-menu">{['🙂','👍','🙏','✅','💜','🎬'].map(e=><button type="button" key={e} onClick={()=>insert(e)}>{e}</button>)}</div>}</div></div></div>
  {kind==='note'&&<small className="sp-internal-hint"><StudioIcon name="shield"/>{t('private')}</small>}
  {current.quote&&<div className="sp-compose-quote"><span><small>{t('quoted')}</small>{current.quote.body||t('attachment')}</span><button type="button" aria-label={t('clearReply')} onClick={()=>patch({quote:null})}><StudioIcon name="close"/></button></div>}
  <textarea ref={input} value={current.body} maxLength={4000} placeholder={t('placeholder')} aria-label={t('message')} aria-invalid={!!error} rows={1} onChange={e=>patch({body:e.target.value})} onKeyDown={e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.nativeEvent.isComposing&&e.nativeEvent.keyCode!==229){e.preventDefault();void send();}else if(e.key==='Escape'){setQuick(false);setEmoji(false);}}}/>
  <PendingFiles files={current.files} onRemove={i=>patch({files:current.files.filter((_,n)=>n!==i)})}/>
  {error&&<div className="sp-send-error" role="alert"><StudioIcon name="alert"/><span>{error}</span><button type="button" onClick={()=>void send()} disabled={busy}>{t('retry')}</button></div>}
  <div className="sp-composer-footer"><ActionTooltip label={t('attachment')}><button type="button" className="icon-button" onClick={()=>file.current?.click()} aria-label={t('attachment')}><StudioIcon name="paperclip"/></button></ActionTooltip><small className="sp-file-limit" title={t('attachmentHint')}>≤5 · 10 MiB</small><input ref={file} hidden type="file" multiple accept=".png,.jpg,.jpeg,.webp,.pdf" onChange={e=>{const next=[...current.files,...Array.from(e.target.files??[])];if(validFiles(next))patch({files:next});else toast.error(t('fileError'));e.target.value='';}}/>
   <button className="media-button primary sp-send" disabled={busy||(!current.body.trim()&&!current.files.length)}>{t(busy?'sending':'send')}<StudioIcon name={busy?'loader':'send'} className={busy?'is-spinning':''}/></button>
  </div>
 </form>;
}
