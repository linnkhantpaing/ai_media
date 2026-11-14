import { Link } from '@inertiajs/react';
import { useEffect, useId, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { StudioIcon, type IconName } from '@/components/studio-icon';
import { useTranslations } from '@/lib/i18n';
import { useLibraryText, type LibraryWord } from '@/lib/library-i18n';
import { useMediaText, type MediaWord } from '@/lib/media-i18n';
import { busyStatuses, formatBytes, formatTime } from '@/lib/media-api';
import type { LibraryProject } from '@/types/media';

export function projectDestination(project: LibraryProject): { href: string; label: LibraryWord } {
    if (project.sourceType === 'youtube' && ['importing','import_failed','import_cancelled'].includes(project.status)) return { href: project.links.uploadPage, label: 'viewImport' };
    if (project.status === 'uploading') return { href: project.links.uploadPage, label: 'resume' };
    if (project.status === 'ready') return { href: project.links.setup, label: 'setup' };
    if (project.status === 'validating') return { href: project.links.uploadPage, label: 'progress' };
    if (project.status === 'awaiting_review') return { href: project.links.analysis, label: 'review' };
    if (project.status === 'completed') return { href: project.links.analysis, label: 'result' };
    if (project.run || project.hasScript) return { href: project.links.analysis, label: busyStatuses.includes(project.status) ? 'progress' : 'open' };
    return { href: project.metadata?.audio ? project.links.setup : project.links.uploadPage, label: 'open' };
}

export interface LibraryAction { label: string; icon: IconName; action?: () => void; href?: string; disabled?: boolean; danger?: boolean }
export function LibraryMenu({ title, actions, busyHint, label }: { title: string; actions: LibraryAction[]; busyHint?: string; label?: string }) {
    const { t } = useLibraryText();
    const [position, setPosition] = useState<{ top: number; left: number; maxHeight: number } | null>(null);
    const trigger = useRef<HTMLButtonElement>(null); const menu = useRef<HTMLDivElement>(null); const id = useId();
    const close = (restore = false) => { setPosition(null); if (restore) trigger.current?.focus({ preventScroll: true }); };
    useEffect(() => {
        if (!position) return;
        const focus = requestAnimationFrame(() => menu.current?.querySelector<HTMLElement>('[role=menuitem]:not(:disabled)')?.focus({ preventScroll: true }));
        const outside = (event: PointerEvent) => { if (!menu.current?.contains(event.target as Node) && !trigger.current?.contains(event.target as Node)) close(); };
        const scroll = (event: Event) => { if (!menu.current?.contains(event.target as Node)) close(); };
        const resize = () => close();
        document.addEventListener('pointerdown', outside); window.addEventListener('scroll', scroll, true); window.addEventListener('resize', resize);
        return () => { cancelAnimationFrame(focus); document.removeEventListener('pointerdown', outside); window.removeEventListener('scroll', scroll, true); window.removeEventListener('resize', resize); };
    }, [position]);
    useLayoutEffect(() => {
        if (!position || !menu.current || !trigger.current) return;
        const anchor = trigger.current.getBoundingClientRect();
        const height = menu.current.getBoundingClientRect().height;
        const top = Math.max(12, Math.min(window.innerHeight - height - 12, anchor.bottom + height + 18 <= window.innerHeight ? anchor.bottom + 6 : anchor.top - height - 6));
        if (position.top !== top) setPosition(current => current ? { ...current, top } : null);
    }, [position, actions.length, busyHint]);
    function show() {
        if (position) { close(true); return; }
        const box = trigger.current!.getBoundingClientRect();
        const height = window.innerHeight - 24;
        setPosition({ left: Math.min(window.innerWidth - 264, Math.max(12, box.right - 252)), top: Math.max(12, box.bottom + height + 8 > window.innerHeight ? box.top - height - 6 : box.bottom + 6), maxHeight: height });
    }
    return <><button type="button" ref={trigger} className="library-more icon-button" aria-label={(label || t('more')) + ': ' + title} aria-haspopup="menu" aria-expanded={!!position} aria-controls={position ? id : undefined} onClick={show} onKeyDown={(event) => { if (event.key === 'ArrowDown') { event.preventDefault(); if (!position) show(); } }}><StudioIcon name="more" /></button>{position && createPortal(<div className="library-menu" ref={menu} id={id} role="menu" aria-label={title} style={position} onKeyDown={(event) => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); }
        if (event.key === 'Tab') close();
        if (['ArrowDown','ArrowUp','Home','End'].includes(event.key)) { event.preventDefault(); const items = [...menu.current!.querySelectorAll<HTMLElement>('[role=menuitem]:not(:disabled)')]; const index = items.indexOf(document.activeElement as HTMLElement); items[event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus(); }
    }}>{actions.map((item, index) => item.href ? <a key={index} role="menuitem" tabIndex={-1} href={item.href} onClick={() => close(true)}><StudioIcon name={item.icon} /><span>{item.label}</span></a> : <button key={index} type="button" role="menuitem" tabIndex={-1} disabled={item.disabled} className={item.danger ? 'is-danger' : ''} onClick={() => { close(true); item.action?.(); }}><StudioIcon name={item.icon} /><span>{item.label}</span></button>)}{busyHint && <p>{busyHint}</p>}</div>, document.body)}</>;
}

const statuses: Record<string, MediaWord> = { uploading:'uploading', importing:'youtubeImporting', validating:'validating', ready:'ready', queued:'queued', analyzing:'analyzing', translating:'translate', rendering:'generating', awaiting_review:'reviewTitle', completed:'completed', failed:'failed', invalid:'failed', cancelled:'cancelled', import_failed:'failed', import_cancelled:'cancelled', cancel_requested:'cancelRequested' };
export function ProjectStatus({ project }: { project: LibraryProject }) {
    const { t } = useMediaText(); const { t: text } = useLibraryText(); const { t: shell } = useTranslations();
    const busy = busyStatuses.includes(project.status); const failed = ['failed','invalid','cancelled','import_failed','import_cancelled'].includes(project.status);
    const tone = project.status === 'completed' ? 'success' : failed ? 'danger' : project.status === 'awaiting_review' ? 'warning' : busy ? 'active' : 'neutral';
    const icon: IconName = project.status === 'completed' ? 'check' : failed ? 'alert' : project.status === 'awaiting_review' ? 'clock' : busy ? 'loader' : 'file';
    const processing = ['analyzing','translating','rendering'].includes(project.status);
    const uploaded = project.status === 'uploading' && project.bytes > 0 ? Math.min(100, Math.floor(project.uploadedBytes / project.bytes * 100)) : null;
    const label = project.run?.status === 'retry_waiting' ? text('retryWaiting') : project.status === 'queued' ? text('queued') : ['failed','invalid','import_failed'].includes(project.status) ? shell('needsAttention') : project.status === 'completed' ? shell('completed') : project.status === 'awaiting_review' ? shell('awaitingReview') : project.status === 'ready' ? shell('drafts') : t(statuses[project.status] || 'waiting');
    return <span className={'library-status is-' + tone}><StudioIcon name={icon} /><span>{label}{uploaded !== null ? ' (' + uploaded + '%)' : processing && project.run ? ' (' + Math.min(100, Math.max(0, project.run.progress)) + '%)' : ''}</span></span>;
}

export function ProjectTile({ project, menu, list, onPreview, timezone }: { project: LibraryProject; menu: ReactNode; list: boolean; onPreview: () => void; timezone: string }) {
    const { t, locale } = useLibraryText(); const { error, t: mediaText } = useMediaText(); const destination = projectDestination(project);
    const [posterError, setPosterError] = useState(false);
    const previewable = project.hasOutput || !!project.metadata?.audio;
    const uploadProgress = project.status === 'uploading' && project.bytes > 0 ? Math.min(100, Math.floor(project.uploadedBytes / project.bytes * 100)) : null;
    const importLabels: Record<string, MediaWord> = {queued:'importQueue',checking:'importChecking',downloading:'importDownloading',preparing:'importPreparing',stopping:'cancelRequested'};
    const date = new Intl.DateTimeFormat(locale === 'th' ? 'th-TH-u-ca-gregory' : locale === 'my' ? 'my-MM' : 'en-US', { timeZone: timezone, month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' }).format(new Date(project.updatedAt));
    const picture = <>{project.metadata?.poster && !posterError ? <img src={project.links.poster} alt="" loading="lazy" onError={() => setPosterError(true)} /> : <span className="library-poster-placeholder"><StudioIcon name={project.sourceType === 'youtube' ? 'youtube' : 'play'} /></span>}{previewable && <span className={'library-play' + (project.hasOutput ? ' is-ready' : '')}><StudioIcon name="play" /></span>}{project.metadata?.duration != null && <time aria-label={t('duration')}>{formatTime(project.metadata.duration).replace(/^00:/,'')}</time>}</>;
    return <article className={'library-project' + (list ? ' is-list' : '')}>
        <div className="library-poster">{previewable ? <button type="button" onClick={onPreview} aria-label={t('preview') + ': ' + project.title}>{picture}</button> : <Link href={destination.href} aria-label={t(destination.label) + ': ' + project.title}>{picture}</Link>}</div>
        <div className="library-identity"><h2><Link href={destination.href} title={project.title}>{project.title}</Link></h2><div className="library-tags"><span className="library-tag is-mode"><StudioIcon name={project.settings.mode === 'dub' ? 'mic' : 'captions'} />{t(project.settings.mode)}</span><span className="library-tag"><StudioIcon name={project.sourceType === 'youtube' ? 'youtube' : 'folder'} className={project.sourceType === 'youtube' ? 'youtube-icon' : undefined} />{t(project.sourceType === 'youtube' ? 'youtube' : 'local')}</span></div></div>
        <div className="library-state"><ProjectStatus project={project} />{uploadProgress !== null ? <progress max={100} value={uploadProgress} aria-label={t('progress')} /> : ['analyzing','translating','rendering'].includes(project.status) && project.run && <progress max={100} value={project.run.progress} aria-label={t('progress')} />}{project.status === 'importing' && project.import && <small className="library-ingest-stage">{mediaText(importLabels[project.import.stage] || 'importChecking')}{project.import.downloadedBytes > 0 ? ' · '+formatBytes(project.import.downloadedBytes) : ''}</small>}{project.changesPending && <small className="library-pending" title={t('pendingHint')}>{t('pending')}</small>}{project.errorCode && <small className="library-issue" title={error(project.errorCode)}>{error(project.errorCode)}</small>}</div>
        <time className="library-updated" dateTime={project.updatedAt} title={t('updated') + ': ' + date}>{date}</time>
        <div className="library-file-links">{project.outputState === 'previous' && <span>{t('previousOutput')}</span>}{project.hasOutput && <a href={project.links.video + '?download=1'} title={t('downloadVideo') + (project.outputState === 'previous' ? ' · ' + t('previousOutput') : '')}><StudioIcon name="download" />MP4</a>}{project.hasAudio&&<a href={project.links.audio+'?download=1'}><StudioIcon name="download"/>{project.metadata?.output?.format?.toUpperCase()||'Audio'}</a>}{project.hasSubtitles && <a href={project.links.subtitles} title={t('downloadSrt') + (project.outputState === 'previous' ? ' · ' + t('previousOutput') : '')}>SRT</a>}</div>
        <div className="library-tile-actions">{menu}</div><Link className="library-primary-link" href={destination.href}>{t(destination.label)}<StudioIcon name="arrow" /></Link>
    </article>;
}