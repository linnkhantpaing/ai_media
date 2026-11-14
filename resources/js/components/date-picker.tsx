import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { StudioIcon } from '@/components/studio-icon';

type Locale = 'en'|'my'|'th';
type View = 'days'|'months'|'years';
const copy = {
 en:{today:'Today',close:'Close calendar',previous:'Previous',next:'Next',month:'Choose month',year:'Choose year',choose:'Choose date'},
 my:{today:'ယနေ့',close:'ပြက္ခဒိန် ပိတ်ရန်',previous:'ရှေ့သို့',next:'နောက်သို့',month:'လ ရွေးရန်',year:'နှစ် ရွေးရန်',choose:'ရက်စွဲ ရွေးရန်'},
 th:{today:'วันนี้',close:'ปิดปฏิทิน',previous:'ก่อนหน้า',next:'ถัดไป',month:'เลือกเดือน',year:'เลือกปี',choose:'เลือกวันที่'},
};
function date(value:string){return new Date(value+'T12:00:00Z');}
function iso(value:Date){return value.toISOString().slice(0,10);}
function plus(value:string,days:number){const next=date(value);next.setUTCDate(next.getUTCDate()+days);return iso(next);}
export function DatePicker({id,label,value,onChange,today,min,max,locale='en',disabled=false,readOnly=false,required=false,invalid=false}:{id:string;label:string;value:string;onChange:(value:string)=>void;today:string;min?:string;max?:string;locale?:Locale;disabled?:boolean;readOnly?:boolean;required?:boolean;invalid?:boolean}){
 const words=copy[locale];const language=locale==='my'?'my-MM':locale==='th'?'th-TH':'en-GB';
 const popupId=useId();const trigger=useRef<HTMLButtonElement>(null);const popup=useRef<HTMLDivElement>(null);
 const [open,setOpen]=useState(false);const [view,setView]=useState<View>('days');const [cursor,setCursor]=useState(value||today);const [focused,setFocused]=useState(value||today);
 const [position,setPosition]=useState({left:0,top:0});
 const allowed=(day:string)=>(!min||day>=min)&&(!max||day<=max);
 const shown=date(cursor);const year=shown.getUTCFullYear();const month=shown.getUTCMonth();
 const monthName=(index:number)=>new Intl.DateTimeFormat(language,{month:'long',timeZone:'UTC',calendar:'gregory'}).format(new Date(Date.UTC(year,index,1,12)));
 const number=(n:number)=>new Intl.NumberFormat(language,{useGrouping:false}).format(n);
 function close(restore=true){setOpen(false);if(restore)trigger.current?.focus();}
 function choose(day:string){if(!allowed(day))return;onChange(day);close();}
 function move(amount:number){const next=date(cursor);if(view==='years')next.setUTCFullYear(year+amount*12,0,1);else if(view==='months')next.setUTCFullYear(year+amount,0,1);else next.setUTCMonth(month+amount,1);const nextDay=iso(next);setCursor(nextDay);setFocused(min&&nextDay<min?min:max&&nextDay>max?max:nextDay);}
 function show(){if(disabled||readOnly)return;let initial=value||today;if(min&&initial<min)initial=min;if(max&&initial>max)initial=max;setCursor(initial);setFocused(initial);setView('days');setOpen(!open);}
 useLayoutEffect(()=>{
  if(!open||!popup.current?.isConnected||!trigger.current)return;
  const element=popup.current;if(element.showPopover&&!element.matches(':popover-open'))element.showPopover();
  const anchor=trigger.current.getBoundingClientRect();const bounds=element.getBoundingClientRect();const gap=8;const padding=12;
  const left=Math.max(padding,Math.min(anchor.left,window.innerWidth-bounds.width-padding));
  const below=anchor.bottom+gap+bounds.height<=window.innerHeight-padding;
  const top=below?anchor.bottom+gap:Math.max(padding,anchor.top-gap-bounds.height);
  setPosition({left,top});
 },[open,view,cursor]);
 useEffect(()=>{
  if(!open)return;
  const dismiss=(event:PointerEvent)=>{if(!popup.current?.contains(event.target as Node)&&!trigger.current?.contains(event.target as Node))close(false);};
  const escape=(event:KeyboardEvent)=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close();}};
  const scroll=(event:Event)=>{if(!(event.target instanceof Node)||!popup.current?.contains(event.target))close(false);};
  document.addEventListener('pointerdown',dismiss);document.addEventListener('keydown',escape,true);window.addEventListener('scroll',scroll,true);window.addEventListener('resize',scroll);
  return()=>{document.removeEventListener('pointerdown',dismiss);document.removeEventListener('keydown',escape,true);window.removeEventListener('scroll',scroll,true);window.removeEventListener('resize',scroll);};
 },[open]);
 useEffect(()=>{if(!open)return;const frame=requestAnimationFrame(()=>{(popup.current?.querySelector<HTMLElement>('[data-focused="true"]')??popup.current)?.focus();});return()=>cancelAnimationFrame(frame);},[open,view,cursor,focused]);
 const start=new Date(Date.UTC(year,month,1,12));start.setUTCDate(1-start.getUTCDay());const days=Array.from({length:42},(_,index)=>{const day=new Date(start);day.setUTCDate(start.getUTCDate()+index);return iso(day);});
 const firstYear=Math.floor(year/12)*12;const years=Array.from({length:12},(_,index)=>firstYear+index);
 const host=trigger.current?.closest('dialog')??document.body;
 const display=value?new Intl.DateTimeFormat(language,{day:'2-digit',month:'short',year:'numeric',timeZone:'UTC',calendar:'gregory'}).format(date(value)):words.choose;
 return <><button ref={trigger} id={id} type="button" className="date-picker-trigger" disabled={disabled} aria-label={label} aria-required={required||undefined} aria-invalid={invalid||undefined} aria-haspopup="dialog" aria-controls={popupId} aria-expanded={open} aria-readonly={readOnly||undefined} onClick={show}><StudioIcon name="calendar"/><span>{display}</span><StudioIcon name={readOnly?'shield':'chevron'}/></button>
 {open&&createPortal(<div ref={popup} id={popupId} className="date-picker-popup" popover="manual" role="dialog" aria-modal="false" aria-label={label} tabIndex={-1} style={position}>
  <header><button type="button" className="date-picker-nav" aria-label={words.previous} onClick={()=>move(-1)}><StudioIcon name="back"/></button><div><button type="button" aria-label={words.month} aria-expanded={view==='months'} onClick={()=>setView(view==='months'?'days':'months')}>{monthName(month)}<StudioIcon name="chevron"/></button><button type="button" aria-label={words.year} aria-expanded={view==='years'} onClick={()=>setView(view==='years'?'days':'years')}>{number(year)}<StudioIcon name="chevron"/></button></div><button type="button" className="date-picker-nav" aria-label={words.next} onClick={()=>move(1)}><StudioIcon name="arrow"/></button></header>
  {view==='days'?<><div className="date-picker-week" aria-hidden="true">{Array.from({length:7},(_,index)=><span key={index}>{new Intl.DateTimeFormat(language,{weekday:'short',timeZone:'UTC'}).format(new Date(Date.UTC(2026,10,8+index,12)))}</span>)}</div><div className="date-picker-days" role="grid" aria-label={monthName(month)+' '+number(year)}>{Array.from({length:6},(_,week)=><div className="date-picker-row" role="row" key={week}>{days.slice(week*7,week*7+7).map(day=><button type="button" role="gridcell" key={day} data-date={day} data-focused={day===focused} tabIndex={day===focused?0:-1} disabled={!allowed(day)} aria-selected={day===value} aria-current={day===today?'date':undefined} className={(date(day).getUTCMonth()!==month?'is-outside ':'')+(day===value?'is-selected ':'')+(day===today?'is-today':'')} aria-label={new Intl.DateTimeFormat(language,{dateStyle:'full',timeZone:'UTC',calendar:'gregory'}).format(date(day))} onClick={()=>choose(day)} onKeyDown={event=>{const steps:Record<string,number>={ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7,Home:-date(day).getUTCDay(),End:6-date(day).getUTCDay()};if(event.key in steps){event.preventDefault();const next=plus(day,steps[event.key]);if(allowed(next)){setFocused(next);if(next.slice(0,7)!==cursor.slice(0,7))setCursor(next);}}else if(event.key==='PageUp'||event.key==='PageDown'){event.preventDefault();const next=date(day);next.setUTCMonth(next.getUTCMonth()+(event.key==='PageUp'?-1:1),1);const nextDay=iso(next);if(allowed(nextDay)){setCursor(nextDay);setFocused(nextDay);}}}}>{number(date(day).getUTCDate())}</button>)}</div>)}</div></>:<div className="date-picker-options" role="group" aria-label={view==='months'?words.month:words.year}>{(view==='months'?Array.from({length:12},(_,index)=>index):years).map(item=>{const optionYear=view==='months'?year:item;const optionMonth=view==='months'?item:0;const begins=iso(new Date(Date.UTC(optionYear,optionMonth,1,12)));const ends=iso(new Date(Date.UTC(optionYear,view==='months'?optionMonth+1:12,0,12)));const unavailable=!!((min&&ends<min)||(max&&begins>max));const selected=view==='months'?item===month:item===year;return <button type="button" key={item} data-focused={selected} disabled={unavailable} aria-pressed={selected} onClick={()=>{let next=begins;if(min&&next<min)next=min;if(max&&next>max)next=max;setCursor(next);setFocused(next);setView(view==='years'?'months':'days');}}>{view==='months'?monthName(item):number(item)}</button>;})}</div>}
  <footer><button type="button" disabled={!allowed(today)} onClick={()=>choose(today)}><StudioIcon name="calendar"/>{words.today}</button><button type="button" aria-label={words.close} onClick={()=>close()}><StudioIcon name="close"/></button></footer>
 </div>,host)}</>;
}
