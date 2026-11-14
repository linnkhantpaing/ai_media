import {useEffect,useMemo,useRef,useState} from 'react';
import {StudioIcon} from '@/components/studio-icon';
import {SelectField} from '@/components/media-workflow';
import {useRevenueText} from '@/lib/revenue-i18n';
import type {RevenueCurrency,RevenueData} from '@/types/revenue';

export const revenueCurrencies:RevenueCurrency[]=['MMK','THB','USD'];
export const packageColors=['#A36BFF','#28C9AB','#E9BB52','#6098EE','#D97DCE','#94A2BA'];
const series=['gross','refunds','net'] as const;
export function RevenueTrend({data,currency,onCurrency,group,onGroup}:{data:RevenueData;currency:RevenueCurrency;onCurrency:(c:RevenueCurrency)=>void;group:string;onGroup:(v:string)=>void}){
 const {t,money,language}=useRevenueText(),[hover,setHover]=useState<number|null>(null),[shown,setShown]=useState<Set<string>>(new Set(['net']));
 const wrap=useRef<HTMLDivElement>(null),[width,setWidth]=useState(760);
 useEffect(()=>{if(!wrap.current)return;const observer=new ResizeObserver(entries=>setWidth(Math.max(280,entries[0].contentRect.width)));observer.observe(wrap.current);return()=>observer.disconnect();},[]);
 const points=data.trend.points,values=points.map(p=>p.values[currency]),hasData=values.some(v=>v.gross||v.refunds);
 const raw=values.flatMap(v=>series.filter(s=>shown.has(s)).map(s=>v[s]));const min=Math.min(0,...raw),maximum=Math.max(0,...raw),span=maximum-min||100;
 const low=min<0?min-span*.1:0,high=maximum+span*.1;
 const x=(i:number)=>56+(points.length>1?i/(points.length-1):.5)*(width-74);
 const y=(n:number)=>18+(high-n)/(high-low)*157;
 const zero=y(0),label=(value:string)=>new Intl.DateTimeFormat(language,{month:'short',day:'numeric',timeZone:'UTC',calendar:'gregory'}).format(new Date(value+'T12:00:00Z'));
 const ticks=Array.from({length:5},(_,i)=>low+(high-low)*i/4);
 const labels=[...new Set([0,Math.floor((points.length-1)/4),Math.floor((points.length-1)/2),Math.floor((points.length-1)*.75),points.length-1])].filter(n=>n>=0);
 const short=(n:number)=>new Intl.NumberFormat(language,{notation:'compact',maximumFractionDigits:1}).format(n/100);
 function toggle(key:string){setShown(old=>{const next=new Set(old);next.has(key)?next.delete(key):next.add(key);return next;});setHover(null);}
 const focused=hover!==null&&points[hover]?hover:null;
 return <section className="rv-panel rv-trend">
  <header><h2><StudioIcon name="chart"/>{t('trend')}</h2><div className="rv-segment" aria-label={t('currency')}>{revenueCurrencies.map(c=><button key={c} className={currency===c?'is-active':''} aria-pressed={currency===c} onClick={()=>{setHover(null);onCurrency(c);}}>{c}</button>)}</div></header>
  <div className="rv-chart-tools"><div className="rv-legend">{series.map(s=><button key={s} className={'rv-series is-'+s+(shown.has(s)?' is-on':'')} aria-pressed={shown.has(s)} onClick={()=>toggle(s)}><i/>{t(s)}</button>)}</div><SelectField label={t('group')} value={group} options={['auto','day','week','month'].map((v,i)=>({value:v,label:t(['auto','byDay','byWeek','byMonth'][i])}))} onChange={onGroup}/></div>
  <div className="rv-chart-wrap" ref={wrap} onPointerLeave={()=>setHover(null)}>
   {hasData&&shown.size>0?<svg className="rv-chart" viewBox={'0 0 '+width+' 215'} role="img" aria-label={t('trend')+' · '+currency} onPointerMove={e=>{if(points.length){const r=e.currentTarget.getBoundingClientRect(),relative=(e.clientX-r.left)/r.width*width;setHover(Math.max(0,Math.min(points.length-1,Math.round((relative-56)/(width-74)*(points.length-1)))));}}}>
    <defs><linearGradient id="rv-net-fill" x1="0" y1="0" x2="0" y2="1"><stop stopColor="var(--accent)" stopOpacity=".4"/><stop offset="1" stopColor="var(--accent)" stopOpacity=".015"/></linearGradient></defs>
    {ticks.map((v,i)=><g key={i}><line x1="56" x2={width-18} y1={y(v)} y2={y(v)} className="rv-gridline"/><text x="46" y={y(v)+3} textAnchor="end">{short(v)}</text></g>)}
    <line x1="56" x2={width-18} y1={zero} y2={zero} className="rv-zero"/>
    {labels.map(i=><text key={i} x={x(i)} y="197" textAnchor={i===0?'start':i===points.length-1?'end':'middle'}>{label(points[i].date)}</text>)}
    {shown.has('net')&&<path d={'M '+x(0)+' '+zero+' L '+values.map((v,i)=>x(i)+' '+y(v.net)).join(' L ')+' L '+x(points.length-1)+' '+zero+' Z'} fill="url(#rv-net-fill)"/>}
    {series.filter(s=>shown.has(s)).map(s=><polyline key={s} points={values.map((v,i)=>x(i)+','+y(v[s])).join(' ')} className={'rv-line is-'+s}/>)}
    {values.map((_,i)=>i).filter(i=>i===0||i===values.length-1||((values[i].gross!==0||values[i].refunds!==0)&&i%Math.max(1,Math.ceil(values.length/30))===0)).map(i=>{const metric=series.find(s=>shown.has(s))??'net';return <circle key={i} cx={x(i)} cy={y(values[i][metric])} r="3.6" className="rv-point" tabIndex={0} onFocus={()=>setHover(i)} onBlur={()=>setHover(null)} aria-label={label(points[i].date)+', '+currency+' '+money(values[i].net)}><title>{label(points[i].date)+' · '+currency+' '+money(values[i].net)}</title></circle>;})}
    {focused!==null&&<line x1={x(focused)} x2={x(focused)} y1="18" y2="175" className="rv-crosshair"/>}
   </svg>:<div className="rv-chart-empty"><StudioIcon name="chart"/><p>{t(hasData?'chooseMetric':'noChart')}</p></div>}
   {focused!==null&&hasData&&shown.size>0&&<div className="rv-chart-tooltip" role="status" style={{left:Math.max(20,Math.min(78,x(focused)/width*100))+'%'}}><strong>{label(points[focused].date)} · {currency}</strong>{series.map(s=><span key={s} className={'is-'+s}><i/>{t(s)}<b>{money(values[focused][s])}</b></span>)}</div>}
  </div>
  <div className="rv-chart-caption"><span>{currency}</span><span>{t(data.trend.group==='day'?'byDay':data.trend.group==='week'?'byWeek':'byMonth')}</span></div>
 </section>;
}
export function RevenueShare({data}:{data:RevenueData}){
 const {t,number}=useRevenueText();
 const entries=useMemo(()=>{const map=new Map<number,{id:number;name:string;count:number}>();for(const p of data.packages){const old=map.get(p.package_id!);if(old)old.count+=p.payments;else map.set(p.package_id!,{id:p.package_id!,name:p.name,count:p.payments});}const rows=[...map.values()].filter(p=>p.count>0).sort((a,b)=>b.count-a.count||a.name.localeCompare(b.name));return rows.length>5?[...rows.slice(0,5),{id:-1,name:t('others'),count:rows.slice(5).reduce((n,p)=>n+p.count,0)}]:rows;},[data.packages,t('others')]);
 const total=entries.reduce((n,p)=>n+p.count,0);let offset=0;
 const gradient=entries.map((p,i)=>{const start=offset;offset+=p.count/total*100;return packageColors[i]+' '+start+'% '+offset+'%';}).join(',');
 return <section className="rv-panel rv-share"><header><div><h2><StudioIcon name="layers"/>{t('share')}</h2><p>{t('shareHint')}</p></div><span className="rv-currency-label">{data.filters.currency==='all'?t('all'):data.filters.currency}</span></header><div className="rv-share-content">
  <div className={'rv-donut'+(total?'':' is-empty')} role="img" aria-label={t('shareHint')+': '+number(total)} style={{background:total?'conic-gradient('+gradient+')':undefined}}><div><strong>{number(total)}</strong><span>{t('paymentCount')}</span></div></div>
  {total>0?<ul>{entries.map((p,i)=><li key={p.id}><i style={{background:packageColors[i]}}/><span title={p.name}>{p.name}</span><strong>{number(p.count/total*100)}%</strong></li>)}</ul>:<p className="rv-share-empty">{t('noPayments')}</p>}
 </div></section>;
}
