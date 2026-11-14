import {PageBoundaryButton} from '@/components/page-boundary-button';
import { Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { MediaDialog, MediaNotice } from '@/components/media-workflow';
import { StudioIcon, type IconName } from '@/components/studio-icon';
import {useCreatorText} from '@/lib/media-creator-i18n';
import { useOperationsText, type OperationsWord } from '@/lib/operations-i18n';
import { useLibraryText } from '@/lib/library-i18n';
import { useMediaText, type MediaWord } from '@/lib/media-i18n';
import { formatBytes, formatTime } from '@/lib/media-api';
import type { SharedData } from '@/types';
import type { OperationJob, JobStatus } from '@/types/operations';

export function OpsHeader({ workers = false, refresh, busy }: { workers?: boolean; refresh: () => void; busy: boolean }) {
    const { t } = useOperationsText(); const { frameLinks } = usePage<SharedData>().props;
    return <><header className="ops-heading"><span className="ops-heading-icon"><StudioIcon name={workers ? 'activity' : 'jobs'} /></span><div><h1>{t(workers ? 'workers' : 'title')}</h1><p>{t(workers ? 'workerDescription' : 'description')}</p></div><div className="ops-heading-actions"><Link href={frameLinks[workers ? 'jobMonitor' : 'workerHealth']!} className="media-button"><StudioIcon name={workers ? 'jobs' : 'activity'} />{t(workers ? 'title' : 'workers')}</Link><button type="button" className="media-button" onClick={refresh} disabled={busy}><StudioIcon name="loader" />{t('refresh')}</button></div></header></>;
}

export function useOpsDate(timezone: string) {
    const { locale } = useOperationsText();
    return (value: string | null): string => value ? new Intl.DateTimeFormat(locale === 'th' ? 'th-TH-u-ca-gregory' : locale === 'my' ? 'my-MM' : 'en-US', {timeZone:timezone,month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',second:'2-digit'}).format(new Date(value)) : '—';
}
export const statusTone = (status: JobStatus): string => status === 'completed' ? 'success' : status === 'failed' ? 'danger' : ['queued','retry_waiting','stopping'].includes(status) ? 'warning' : status === 'cancelled' ? 'neutral' : 'primary';
export function OpsStatus({ status }: { status: JobStatus }) {
    const { t } = useOperationsText(); const icon: IconName = status === 'completed' ? 'check' : status === 'failed' ? 'alert' : status === 'running' ? 'loader' : status === 'cancelled' ? 'close' : 'clock';
    return <span className={'ops-status is-'+statusTone(status)+' status-'+status}><StudioIcon name={icon} /><span>{t(status)}</span></span>;
}
export function JobTypeBadge({ job }: { job: OperationJob }) {
    const { t } = useOperationsText();
    return <span className="ops-type"><StudioIcon name={job.type === 'generate' ? 'play' : job.type === 'analysis' ? 'activity' : job.type === 'import' ? 'upload' : 'shield'} />{t(job.type)}</span>;
}
export function useOpsStage() {
    const {t:creator}=useCreatorText(); const { t } = useMediaText(); const { t: ops } = useOperationsText();
    const labels: Record<string,MediaWord> = {prepare:'prepare',preparing:'prepare',transcribe:'transcribe',context:'context',translate:'translate',voice:'voice',subtitles:'subtitles',render:'render',rendering:'render',verify:'verify',store:'verify',checking:'importChecking',downloading:'importDownloading',queued:'queued',stopping:'cancelRequested'};
    return (stage: string | null): string => stage==='separate'?creator('separate'):stage==='preview'?creator('preview'):stage && labels[stage] ? t(labels[stage]) : stage === 'validate' || stage === 'validating' ? ops('validation') : '—';
}
export function JobProgress({ job }: { job: OperationJob }) {
    const { t } = useOperationsText(); const { error } = useMediaText(); const stage = useOpsStage();
    const percent = job.status === 'queued' ? null : job.progress;
    const label = job.status === 'failed' ? error(job.errorCode || 'processing_failed') : ['queued','retry_waiting','cancelled','stopping'].includes(job.status) ? t(job.status) : percent !== null ? percent+'%' : stage(job.stage);
    return <div className={'ops-progress is-'+statusTone(job.status)}><span title={label}>{label}</span>{percent !== null && job.status !== 'queued' ? <progress max={100} value={percent} aria-label={t('progress')} /> : <span className={'ops-progress-track'+(job.status === 'running' ? ' is-indeterminate' : '')} aria-hidden="true"><i /></span>}{job.status === 'running' && <small>{job.type === 'import' && job.downloadedBytes ? formatBytes(job.downloadedBytes) : stage(job.stage)}</small>}</div>;
}
export function JobIdentity({ job, onOpen }: { job: OperationJob; onOpen: () => void }) {
    const [failed,setFailed] = useState(false);const { t } = useOperationsText();
    return <div className="ops-identity"><button className="ops-thumbnail" type="button" onClick={onOpen} aria-label={t('viewDetails')+': '+job.title}>{job.hasPoster&&!failed ? <img src={job.links.poster} alt="" loading="lazy" onError={()=>setFailed(true)} /> : <StudioIcon name="play" />}{job.sourceDuration !== null && <time>{formatTime(job.sourceDuration).replace(/^00:/,'')}</time>}</button><div><button className="ops-title-link" type="button" onClick={onOpen} title={job.title}>{job.title}</button><small>{job.reference}</small></div></div>;
}
export function OpsPagination({ current, last, from, to, total, busy, navigate }: { current:number;last:number;from:number|null;to:number|null;total:number;busy:boolean;navigate:(page:number)=>void }) {
    const { t } = useOperationsText(); const { t: text } = useLibraryText();
    const pages=[...new Set([1,current-1,current,current+1,last])].filter(value=>value>0&&value<=last).sort((a,b)=>a-b);
    return <footer className="ops-pagination"><p>{t('showing',{from:from??0,to:to??0,total})}</p><nav aria-label={t('title')}><PageBoundaryButton edge="first" page={current} pages={last} onNavigate={target=>navigate(target)} disabled={busy}/><button type="button" aria-label={text('previousPage')} disabled={current<=1||busy} onClick={()=>navigate(current-1)}><StudioIcon name="back" /></button>{pages.map((page,index)=><span key={page}>{index>0&&page-pages[index-1]>1&&<i>…</i>}<button type="button" aria-label={text('page',{page})} aria-current={current===page?'page':undefined} disabled={busy} onClick={()=>navigate(page)}>{page}</button></span>)}<button type="button" aria-label={text('nextPage')} disabled={current>=last||busy} onClick={()=>navigate(current+1)}><StudioIcon name="arrow" /></button><PageBoundaryButton edge="last" page={current} pages={last} onNavigate={target=>navigate(target)} disabled={busy}/></nav></footer>;
}
export function JobDrawer({ open, job, code, retryLoad, close, timezone, actions }: { open:boolean;job:OperationJob|null;code:string;retryLoad:()=>void;close:()=>void;timezone:string;actions:ReactNode }) {
    const {t:creator}=useCreatorText(); const { t }=useOperationsText(); const { t:text }=useLibraryText(); const { error }=useMediaText(); const date=useOpsDate(timezone); const stage=useOpsStage();
    return <MediaDialog open={open} title={t('details')} onClose={close} className="ops-drawer" animated headerIcon="jobs" headerDetail={job ? <><span className="ops-header-reference">{job.reference}</span><span className="ops-header-context">{t(job.isCurrent?'current':'historical')}</span></> : undefined}><div className="ops-drawer-body" tabIndex={0} role="region" aria-label={t('details')}>{code&&<MediaNotice danger>{error(code)} <button type="button" className="library-inline-button" onClick={retryLoad}>{text('tryAgain')}</button></MediaNotice>}{job&&<div className="ops-job-details"><div className="ops-detail-identity"><h3>{job.title}</h3><small>{job.reference} · {t(job.isCurrent?'current':'historical')}</small><div><JobTypeBadge job={job}/><OpsStatus status={job.status}/></div></div><JobProgress job={job}/>{job.errorCode&&<MediaNotice danger>{error(job.errorCode)}</MediaNotice>}<dl className="ops-facts">{([
        ['stage',stage(job.stage)],['attempt',job.attempt===null?t('notReported'):job.attempt+(job.maxAttempts?'/'+job.maxAttempts:'')],['elapsed',job.elapsedSeconds===null?'—':formatTime(job.elapsedSeconds)],['wait',job.queueWaitSeconds===null?'—':formatTime(job.queueWaitSeconds)],['queuedAt',date(job.queuedAt)],['started',date(job.startedAt)],['finished',date(job.finishedAt)],['updatedAt',date(job.updatedAt)],['nextRetry',date(job.nextRetryAt)],
    ] as [OperationsWord,string][]).map(([key,value])=><div key={key}><dt>{t(key)}</dt><dd>{value}</dd></div>)}</dl><p className="ops-timezone">{timezone} (UTC+7){job.sourceDuration!==null ? ' · '+text('duration')+': '+formatTime(job.sourceDuration) : ''}</p>{Object.keys(job.stages).length>0&&<section><h4>{t('timeline')}</h4><ol className="ops-stage-checkpoints">{Object.entries(job.stages).map(([key,state])=><li key={key} className={'is-'+state}><StudioIcon name={state==='completed'?'check':state==='active'?'loader':'clock'}/><span>{stage(key)}</span></li>)}</ol></section>}<section><h4>{t('events')}</h4>{!job.historyAvailable&&<p className="ops-muted">{t('historyUnavailable')}</p>}<ol className="ops-events">{job.events?.map((event,index)=><li key={index}><i/><div><strong>{t(event.status)}{event.stage?' · '+stage(event.stage):''}</strong><time>{date(event.at)}</time>{event.errorCode&&<small>{error(event.errorCode)}</small>}</div></li>)}</ol></section><div className="ops-detail-actions">{actions}{job.links.project?<Link className="media-button" href={job.links.project}>{text('open')}<StudioIcon name="arrow"/></Link>:<p className="ops-muted">{t('unavailableProject')}</p>}{job.hasAudio&&<a className="media-button primary" href={job.links.audio+'?download=1'}><StudioIcon name="download"/>{creator('downloadAudio')}</a>}{job.hasVideo&&<a className="media-button primary" href={job.links.video+'?download=1'}><StudioIcon name="download"/>{text('downloadVideo')}</a>}{job.hasSubtitles&&<a className="media-button" href={job.links.subtitles}>{text('downloadSrt')}</a>}</div></div>}</div></MediaDialog>;
}