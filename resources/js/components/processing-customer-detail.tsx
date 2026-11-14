import {Link,usePage} from '@inertiajs/react';
import {useState} from 'react';
import {MediaDialog} from '@/components/media-workflow';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {useProcessingText} from '@/lib/processing-report-i18n';
import type {SharedData} from '@/types';
import {formatPlatformDate,useDisplayDate} from '@/lib/platform-display';
import type {ProcessingDetail} from '@/types/processing-report';

function DetailWave(){return <svg className="pr-detail-wave" viewBox="0 0 440 130" preserveAspectRatio="none" aria-hidden="true"><path d="M0 130C95 130 118 40 225 74S349 13 440 19V130Z"/><path d="M0 130C98 118 150 110 228 83S365 110 440 53V130Z"/></svg>;}
function ExportThumbnail({job,onNavigate}:{job:ProcessingDetail['recent'][number];onNavigate:()=>void}){
 const {t}=useProcessingText(),[failed,setFailed]=useState(false);
 const seconds=job.source_duration===null?null:Math.round(job.source_duration);
 const clock=seconds===null?null:(seconds>=3600?Math.floor(seconds/3600)+':':'')+String(Math.floor(seconds/60)%60).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');
 return <Link href={job.job_url} onClick={onNavigate} className="pr-export-thumb" aria-label={t('openJob')+' '+job.title} title={t('openJob')}>
 {job.poster_url&&!failed?<img src={job.poster_url} alt="" loading="lazy" onError={()=>setFailed(true)}/>:<span className="pr-thumb-placeholder"><StudioIcon name="folder-play"/></span>}
 <span className="pr-thumb-play" aria-hidden="true"><StudioIcon name="triangle-play"/></span>{clock&&<time>{clock}</time>}
 </Link>;
}
export function ProcessingCustomerDetail({detail,open,onClose}:{detail:ProcessingDetail|null;open:boolean;onClose:()=>void}){
 const {t,number,minutes,duration,bytes}=useProcessingText(),date=useDisplayDate();
 const {app,locale}=usePage<SharedData>().props;
 const rate=(n:number|null)=>n===null?'—':number(n)+'%';
 const metrics: {key:string;value:string;icon:IconName;tone:string;current?:boolean}[]=detail?[
  {key:'processing',value:minutes(detail.summary.seconds),icon:'triangle-play',tone:'mint'},
  {key:'successful',value:number(detail.summary.successful),icon:'check',tone:'mint'},
  {key:'failed',value:number(detail.summary.failed),icon:'circle-x',tone:'error'},
  {key:'successRate',value:rate(detail.summary.success_rate),icon:'layers',tone:'info'},
  {key:'average',value:duration(detail.summary.average_duration),icon:'clock',tone:'primary'},
  {key:'storage',value:bytes(detail.summary.storage),icon:'server',tone:'primary',current:true},
  {key:'reserved',value:minutes(detail.summary.reserved),icon:'layers',tone:'primary',current:true},
 ]:[];
 const dateOnly=(value:string)=>formatPlatformDate(value+'T12:00:00Z',locale,{...app,timezone:'UTC'},false);
 return <MediaDialog open={open} title={t('details')} headerIcon="chart" headerDetail={detail?.customer.email} className="rv-detail-panel pr-detail-panel pr-detail-redesign" animated onClose={onClose}>
 {detail&&<><div className="rv-detail-body pr-detail-content">
  <div className="pr-customer-hero"><DetailWave/><div className="pr-hero-identity"><span className="pr-hero-avatar">{detail.customer.name.split(/\s+/).slice(0,2).map(word=>word[0]).join('').toUpperCase()}</span><div><strong>{detail.customer.name}</strong><small>{detail.customer.email}</small></div></div><div className="pr-hero-period"><span><StudioIcon name="calendar"/>{dateOnly(detail.range.from)} – {dateOnly(detail.range.to)}</span><i/><span><StudioIcon name="globe"/>{detail.range.timezone}</span></div></div>
  <section className="pr-summary-section"><h3><StudioIcon name="chart"/>{t('usageSummary')}</h3><div className="pr-summary-cards">{metrics.map(metric=><article className={'pr-summary-card tone-'+metric.tone+(metric.key==='reserved'?' is-full':'')} key={metric.key}><span className="pr-summary-icon"><StudioIcon name={metric.icon}/></span><div><small>{t(metric.key)}{metric.current&&' · '+t('current')}</small><strong>{metric.value}</strong></div></article>)}</div></section>
  <section className="pr-subscription-section"><h3><StudioIcon name="layers"/>{t('currentPackage')}</h3>{detail.current?<div className="pr-subscription-strip"><DetailWave/><span className="pr-subscription-icon"><StudioIcon name={detail.current.type==='paid'?'crown':'sparkles'}/></span><div className="pr-subscription-name"><strong>{detail.current.name}</strong><small>{t('version')} {number(detail.current.version)}</small></div><span className={'pr-subscription-status is-'+detail.current.status}><StudioIcon name={detail.current.status==='active'?'check':'clock'}/>{t(detail.current.status)}</span><div className="pr-subscription-dates"><StudioIcon name="calendar"/><span>{date(detail.current.from,false)} – {date(detail.current.to,false)}</span></div></div>:<p className="pr-detail-no-data">{t('unknown')}</p>}</section>
  <section className="pr-recent-section"><div className="pr-recent-heading"><h3><StudioIcon name="folder-play"/>{t('recent')}</h3><Link href={detail.jobs_url} className="pr-recent-open" onClick={onClose}>{t('openJobs')}<StudioIcon name="arrow"/></Link></div><p className="pr-recent-description">{t('recentHint')}</p>
   {detail.recent.length?<div className="pr-export-list">{detail.recent.map(job=><article className="pr-export-row" key={job.id}><ExportThumbnail job={job} onNavigate={onClose}/><div className="pr-export-main"><header><strong title={job.title}>{job.title}</strong><span className={'pr-export-status is-'+job.status}><StudioIcon name={job.status==='completed'?'check':job.status==='failed'?'circle-x':'ban'}/>{t(job.status==='failed'?'failedState':job.status==='cancelled'?'cancelledState':'completed')}</span></header><time><StudioIcon name="calendar"/>{date(job.at,true)}</time><div className="pr-export-tags"><span>{t(job.mode)}</span>{(job.output==='burn'||job.output==='mp4_srt')?<><span><StudioIcon name="triangle-play"/>MP4</span><span><StudioIcon name="captions"/>SRT</span></>:<span>{t(job.output??'unknown')}</span>}<small className="pr-export-duration"><StudioIcon name="clock"/>{duration(job.duration)}</small></div><footer>{job.package?<span className="pr-export-package"><strong>{job.package.name}</strong><i/><small>v{number(job.package.version)}</small></span>:<span className="pr-muted">{t('unknown')}</span>}<Link href={job.job_url} className="pr-export-open" onClick={onClose}>{t('openJob')}<StudioIcon name="arrow"/></Link></footer></div></article>)}</div>:<p className="pr-detail-no-data">{t('noFinished')}</p>}
  </section>
  <p className="pr-detail-information"><StudioIcon name="info"/><span>{t('readOnly')}</span></p>
 </div><footer className="rv-detail-footer pr-detail-actions"><Link href={detail.ledger_url} className="media-button" onClick={onClose}><StudioIcon name="layers"/>{t('openLedger')}</Link><Link href={detail.jobs_url} className="media-button primary" onClick={onClose}><StudioIcon name="triangle-play"/>{t('openJobs')}<StudioIcon name="arrow"/></Link></footer></>}
 </MediaDialog>;
}
