import {useEffect,useId,useRef,useState} from 'react';
import {StudioIcon} from '@/components/studio-icon';
import {useProcessingText} from '@/lib/processing-report-i18n';
import type {ProcessingData} from '@/types/processing-report';

const palette=['var(--primary)','#36D9B6','#F18AAB','#6B9DF6','#E7BC59'];
export function ProcessingTrend({data}:{data:ProcessingData}){
 const {t,number,language}=useProcessingText(),wrap=useRef<HTMLDivElement>(null),[width,setWidth]=useState(650),[hover,setHover]=useState<number|null>(null),[bars,setBars]=useState(true),[line,setLine]=useState(true),id=useId().replace(/:/g,'');
 useEffect(()=>{if(!wrap.current)return;const observer=new ResizeObserver(entries=>setWidth(Math.max(240,entries[0].contentRect.width)));observer.observe(wrap.current);return()=>observer.disconnect();},[]);
 const points=data.trend.points,has=points.some(p=>p.seconds||p.exports),max=Math.max(1,...points.map(p=>p.seconds/60)),min=Math.min(0,...points.map(p=>p.seconds/60)),range=max-min||1,exportMax=Math.max(3,Math.ceil(Math.max(0,...points.map(p=>p.exports))/3)*3);
 const left=43,right=width-35,top=15,bottom=170,step=(right-left)/Math.max(1,points.length),x=(i:number)=>left+step*(i+.5),y=(n:number)=>bottom-(n-min)/range*(bottom-top),yExport=(n:number)=>bottom-n/exportMax*(bottom-top);
 const date=(v:string)=>new Intl.DateTimeFormat(language,{month:'short',day:'numeric',timeZone:'UTC',calendar:'gregory'}).format(new Date(v+'T12:00:00Z'));
 const tickIndexes=[...new Set([0,Math.round((points.length-1)/3),Math.round((points.length-1)*2/3),points.length-1])].filter(i=>i>=0);
 return <section className="rv-panel pr-trend"><header><h2><StudioIcon name="chart"/>{t('trend')}</h2><small>{t(data.trend.group==='month'?'monthGroup':data.trend.group)}</small></header><div className="pr-chart-legend"><button type="button" aria-pressed={bars} onClick={()=>setBars(!bars)} className={bars?'is-on':''}><i/>{t('usageBar')}</button><button type="button" aria-pressed={line} onClick={()=>setLine(!line)} className={line?'is-on':''}><i className="is-mint"/>{t('exportsLine')}</button></div><div className="pr-trend-wrap" ref={wrap} onPointerLeave={()=>setHover(null)}>
 {has&&(bars||line)?<svg viewBox={'0 0 '+width+' 205'} role="img" aria-label={t('trend')} onPointerMove={e=>{const r=e.currentTarget.getBoundingClientRect();setHover(Math.max(0,Math.min(points.length-1,Math.floor(((e.clientX-r.left)/r.width*width-left)/step))));}}>
 <defs><linearGradient id={'processing-'+id} x1="0" y1="0" x2="0" y2="1"><stop stopColor="var(--primary)" stopOpacity=".95"/><stop offset="1" stopColor="var(--accent)" stopOpacity=".45"/></linearGradient></defs>
 {[0,1,2,3].map(i=>{const value=min+range*i/3;return <g key={i}><line x1={left} x2={right} y1={y(value)} y2={y(value)} className="rv-gridline"/>{bars&&<text x={left-8} y={y(value)+3} textAnchor="end">{number(value)}</text>}{line&&<text x={right+7} y={bottom-(bottom-top)*i/3+3}>{number(exportMax*i/3)}</text>}</g>;})}
 {bars&&points.map((p,i)=><rect key={p.date} x={x(i)-Math.max(1,step*.62)/2} y={Math.min(y(p.seconds/60),y(0))} width={Math.max(1,step*.62)} height={Math.max(p.seconds?1:0,Math.abs(y(p.seconds/60)-y(0)))} rx={2} fill={'url(#processing-'+id+')'}/>)}
 {line&&<polyline points={points.map((p,i)=>x(i)+','+yExport(p.exports)).join(' ')} className="pr-export-line"/>}
 {points.map((p,i)=>i).filter(i=>i===0||i===points.length-1||points[i].exports>0).map(i=><circle key={i} tabIndex={0} cx={x(i)} cy={line?yExport(points[i].exports):y(points[i].seconds/60)} r={3} className="pr-export-point" onFocus={()=>setHover(i)} onBlur={()=>setHover(null)} aria-label={date(points[i].date)+': '+number(points[i].seconds/60)+' '+t('minutes')+', '+number(points[i].exports)+' '+t('successful')}><title>{date(points[i].date)}</title></circle>)}
 {tickIndexes.map(i=><text key={i} x={x(i)} y={194} textAnchor={i===0?'start':i===points.length-1?'end':'middle'}>{date(points[i].date)}</text>)}
 </svg>:<div className="rv-chart-empty"><StudioIcon name="chart"/><p>{t('noChart')}</p></div>}
 {hover!==null&&has&&points[hover]&&<div className="pr-chart-tip" role="status"><strong>{date(points[hover].date)}</strong><span>{t('processing')}: {number(points[hover].seconds/60)} {t('minutes')}</span><span>{t('successful')}: {number(points[hover].exports)}</span></div>}
 </div></section>;
}
export function ProcessingRing({title,icon='layers',values,center,unit,compact=false}:{title:string;icon?:'layers'|'activity'|'play';values:{key:string;label:string;value:number;color?:string;formatted?:string}[];center:string;unit:string;compact?:boolean}){
 const {t,number}=useProcessingText();const total=values.every(v=>v.value>=0)?values.reduce((sum,v)=>sum+v.value,0):0;let at=0;
 const gradient=values.filter(v=>v.value>0).map((v,i)=>{const start=at;at+=v.value/total*100;return (v.color??palette[i%palette.length])+' '+start+'% '+at+'%';}).join(',');
 return <section className={'rv-panel pr-ring-panel'+(compact?' is-compact':'')}><header><h2><StudioIcon name={icon}/>{title}</h2></header><div className="pr-ring-content"><div className={'pr-ring'+(!total?' is-empty':'')} style={{background:total?'conic-gradient('+gradient+')':undefined}} role="img" aria-label={title+': '+center+' '+unit}><div><strong>{center}</strong><small>{unit}</small></div></div><ul>{values.map((v,i)=><li key={v.key}><i style={{background:v.color??palette[i%palette.length]}}/><span>{v.label}</span><strong>{v.formatted??number(v.value)}{total>0&&v.value>=0&&<small> ({number(v.value/total*100)}%)</small>}</strong></li>)}{!total&&<li className="pr-ring-empty">{t(values.some(v=>v.value<0)?'netCorrection':'noChart')}</li>}</ul></div></section>;
}
