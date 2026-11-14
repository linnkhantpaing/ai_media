import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmDialog, FieldHeading, MediaNotice, MediaSummary, Wizard, useMediaProject } from '@/components/media-workflow';
import { StudioIcon } from '@/components/studio-icon';
import { MediaApiError, failureCode, formatBytes, formatTime, mediaApi } from '@/lib/media-api';
import { useMediaText, type MediaWord } from '@/lib/media-i18n';
import type { MediaPageProps, MediaProject } from '@/types/media';
import type { SharedData } from '@/types';

async function hash(data: BufferSource): Promise<string> {
    return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', data))).map((byte) => byte.toString(16).padStart(2, '0')).join('');
}
async function fingerprint(file: File): Promise<string> {
    const head = await file.slice(0, 65536).arrayBuffer(); const tail = await file.slice(Math.max(0, file.size - 65536)).arrayBuffer();
    const name = new TextEncoder().encode(file.name + ':' + file.size);
    const data = new Uint8Array(name.length + head.byteLength + tail.byteLength); data.set(name); data.set(new Uint8Array(head), name.length); data.set(new Uint8Array(tail), name.length + head.byteLength);
    return hash(data);
}
export default function CreateProject(props: MediaPageProps) {
    const { t, error } = useMediaText(); const { auth, frameLinks } = usePage<SharedData>().props;
    const { project, setProject, pollError } = useMediaProject(props.initialProject);
    const [title, setTitle] = useState(props.initialProject?.title || '');
    const [source, setSource] = useState<'upload' | 'youtube'>(props.initialProject?.sourceType || 'upload');
    const [youtubeUrl, setYouTubeUrl] = useState(props.initialProject?.sourceUrl || '');
    const [uploading, setUploading] = useState(false); const [importAction, setImportAction] = useState('');
    const [saving, setSaving] = useState(false); const [clearing, setClearing] = useState(false); const [confirmClear, setConfirmClear] = useState(false); const [drag, setDrag] = useState(false); const [code, setCode] = useState('');
    const input = useRef<HTMLInputElement>(null); const abort = useRef<AbortController | null>(null); const lock = useRef(false); const client = useRef<{ id: string; fingerprint: string } | null>(null); const epoch = useRef(0); const titleEdited = useRef(false);
    const storageKey = 'ai-media.upload.' + auth.user?.email; const clientKey = storageKey + '.client';
    useEffect(() => {
        try { const pending = JSON.parse(localStorage.getItem(clientKey) || 'null'); if (pending && /^[a-f0-9-]{36}$/.test(pending.id) && /^[a-f0-9]{64}$/.test(pending.fingerprint)) client.current = pending; } catch { /* Storage is optional. */ }
    }, [clientKey]);
    useEffect(() => {
        if (props.initialProject) return;
        let stopped = false;
        try {
            const id = localStorage.getItem(storageKey);
            if (id && /^[a-f0-9-]{36}$/.test(id)) void mediaApi<{ project: MediaProject }>(props.resumeUrlTemplate.replace('PROJECT_ID', id)).then((data) => {
                if (!stopped && !lock.current) { setProject(data.project); setTitle(data.project.title); }
            }).catch((reason) => { if (reason instanceof MediaApiError && reason.status === 404) localStorage.removeItem(storageKey); });
        } catch { /* Storage is optional. */ }
        return () => { stopped = true; };
    }, [props.initialProject, props.resumeUrlTemplate, storageKey, setProject]);
    useEffect(() => {
        if (!project) return;
        setSource(project.sourceType === 'youtube' ? 'youtube' : 'upload');
        if (project.sourceUrl) setYouTubeUrl(project.sourceUrl);
    }, [project?.id, project?.sourceType, project?.sourceUrl]);
    useEffect(() => { if (project?.sourceType === 'youtube' && !titleEdited.current) setTitle(project.title); }, [project?.id, project?.title, project?.sourceType]);
    useEffect(() => () => { abort.current?.abort(); }, []);
    const ready = !!project?.metadata?.audio && !['uploading', 'validating', 'invalid', 'importing', 'import_failed', 'import_cancelled'].includes(project.status);
    const validating = project?.status === 'validating'; const importing = project?.status === 'importing';
    const canClear = !!(project || title || youtubeUrl || code || uploading || importAction);
    const progress = project && project.bytes > 0 ? Math.min(100, Math.floor(project.uploadedBytes / project.bytes * 100)) : 0;
    const sourceDisabled = !!project || uploading || !!importAction;
    function remember(id?: string) { try { if (id) localStorage.setItem(storageKey, id); else localStorage.removeItem(storageKey); localStorage.removeItem(clientKey); } catch { /* Storage is optional. */ } }
    function clientId(signature: string) {
        if (!client.current || client.current.fingerprint !== signature) client.current = { id: crypto.randomUUID(), fingerprint: signature };
        try { localStorage.setItem(clientKey, JSON.stringify(client.current)); } catch { /* Storage is optional. */ }
        return client.current.id;
    }
    async function select(file: File) {
        if (lock.current || validating || importing || project?.run || project?.sourceType === 'youtube') return;
        setCode('');
        if (!/\.(mp4|mov|mkv|webm|m4v)$/i.test(file.name)) { setCode('unsupported_file'); return; }
        if (file.size <= 0 || file.size > props.limits.bytes) { setCode('too_large'); return; }
        const version = epoch.current; lock.current = true; setUploading(true); const controller = new AbortController(); abort.current = controller;
        try {
            const signature = await fingerprint(file); controller.signal.throwIfAborted(); let current = project;
            if (current) {
                if (current.filename !== file.name || current.bytes !== file.size || current.fingerprint && current.fingerprint !== signature) throw new MediaApiError('upload_conflict');
                if (current.status !== 'uploading') {
                    if (current.status === 'invalid' && current.uploadedBytes === current.bytes) { const data = await mediaApi<{ project: MediaProject }>(current.links.complete, 'POST', {}, controller.signal); if (epoch.current === version) setProject(data.project); return; }
                    throw new MediaApiError('upload_conflict');
                }
                current = (await mediaApi<{ project: MediaProject }>(current.links.status, 'GET', undefined, controller.signal)).project;
            } else {
                const data = await mediaApi<{ project: MediaProject }>(props.initializeUrl, 'POST', { client_id: clientId(signature), filename: file.name, size: file.size, title: title.trim() || undefined, fingerprint: signature }, controller.signal);
                if (epoch.current !== version) return;
                current = data.project; if (!titleEdited.current) setTitle(current.title); remember(current.id);
            }
            if (epoch.current !== version) return;
            setProject(current); let offset = current.uploadedBytes; let conflicts = 0;
            while (offset < file.size) {
                try {
                    const chunk = file.slice(offset, Math.min(offset + props.limits.chunkBytes, file.size));
                    const data = await mediaApi<{ offset: number }>(current.links.upload, 'PUT', chunk, controller.signal, { 'Upload-Offset': String(offset) });
                    if (data.offset <= offset || data.offset > file.size) throw new MediaApiError('upload_conflict');
                    if (epoch.current !== version) return;
                    offset = data.offset; conflicts = 0; setProject({ ...current, uploadedBytes: offset });
                } catch (reason) {
                    if (reason instanceof MediaApiError && reason.status === 409 && typeof reason.offset === 'number' && reason.offset >= 0 && reason.offset <= file.size && conflicts++ < 2) { offset = reason.offset; continue; }
                    throw reason;
                }
            }
            const data = await mediaApi<{ project: MediaProject }>(current.links.complete, 'POST', {}, controller.signal); if (epoch.current === version) setProject(data.project);
        } catch (reason) { if (!controller.signal.aborted && epoch.current === version) setCode(failureCode(reason)); }
        finally { if (epoch.current === version) { setUploading(false); lock.current = false; abort.current = null; } }
    }
    async function startImport() {
        if (lock.current || project || !youtubeUrl.trim()) return;
        const version = epoch.current; lock.current = true; setImportAction('start'); setCode(''); const controller = new AbortController(); abort.current = controller;
        try {
            const signature = await hash(new TextEncoder().encode(youtubeUrl.trim())); controller.signal.throwIfAborted();
            const data = await mediaApi<{ project: MediaProject }>(props.youtubeImportUrl, 'POST', { client_id: clientId(signature), url: youtubeUrl.trim(), title: title.trim() || undefined }, controller.signal);
            if (epoch.current === version) { setProject(data.project); remember(data.project.id); }
        } catch (reason) { if (!controller.signal.aborted && epoch.current === version) setCode(reason instanceof MediaApiError && reason.fields.url ? 'invalid_youtube_url' : failureCode(reason)); }
        finally { if (epoch.current === version) { lock.current = false; setImportAction(''); abort.current = null; } }
    }
    async function importRequest(kind: 'importCancel' | 'importRetry') {
        if (!project || importAction) return;
        const version = epoch.current; setImportAction(kind); setCode('');
        try { const data = await mediaApi<{ project: MediaProject }>(project.links[kind], 'POST', {}); if (epoch.current === version) setProject(data.project); }
        catch (reason) { if (epoch.current === version) setCode(failureCode(reason)); }
        finally { if (epoch.current === version) setImportAction(''); }
    }
    function clear() {
        if (clearing) return;
        epoch.current++; setClearing(true); abort.current?.abort(); abort.current = null; lock.current = false;
        setProject(null); setTitle(''); setYouTubeUrl(''); setSource('upload'); setCode(''); setUploading(false); setImportAction(''); setDrag(false); titleEdited.current = false; client.current = null; remember(); setConfirmClear(false); if (input.current) input.current.value = '';
        router.visit(frameLinks.newProject!, { replace: true, preserveState: false, onFinish: () => setClearing(false) });
    }
    async function next() {
        if (!project || !ready || !title.trim()) return;
        setSaving(true); setCode('');
        try { const data = await mediaApi<{ project: MediaProject }>(project.links.update, 'PATCH', { title: title.trim() }); remember(); router.visit(data.project.links.setup); }
        catch (reason) { setCode(failureCode(reason)); setSaving(false); }
    }
    const importLabels: Record<string, MediaWord> = { queued: 'importQueue', checking: 'importChecking', downloading: 'importDownloading', preparing: 'importPreparing', validating: 'validating', stopping: 'cancelRequested', cancelled: 'importCancelled', failed: 'failed' };
    return <Wizard step={1}>
        {!props.storage.ready && <MediaNotice danger>{error('storage_not_configured')}</MediaNotice>}
        {!props.runtime.mediaReady && <MediaNotice danger>{error('media_tools_missing')}</MediaNotice>}
        {(code || project?.errorCode) && <MediaNotice danger>{error(code || project!.errorCode!)}</MediaNotice>}
        {pollError && <MediaNotice>{t('pollError')}</MediaNotice>}
        <div className="media-upload-grid"><section className="media-card media-upload-form">
            <FieldHeading icon="file" title={t('title')} /><div className="media-title-field"><label htmlFor="project-title" className="sr-only">{t('title')}</label><input id="project-title" value={title} maxLength={250} autoComplete="off" placeholder={t('title')} disabled={saving || !!project?.run} onChange={(event) => { titleEdited.current = true; setTitle(event.target.value); }} /><button type="button" className="icon-button" aria-label={t('clear') + ' ' + t('title')} onClick={() => { titleEdited.current = true; setTitle(''); }} disabled={saving || !!project?.run || !title}><StudioIcon name="close" /></button></div><p className="media-help"><StudioIcon name="info" />{t('titleHint')}</p>
            <FieldHeading icon="play" title={t('source')} />
            <div className="media-source-tabs" role="tablist" aria-label={t('source')}>{(['upload', 'youtube'] as const).map((value) => <button type="button" key={value} id={'source-tab-' + value} role="tab" aria-selected={source === value} aria-controls={'source-panel-' + value} tabIndex={source === value ? 0 : -1} disabled={sourceDisabled} onClick={() => { setSource(value); setCode(''); }} onKeyDown={(event) => { if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) { event.preventDefault(); const target = event.key === 'Home' ? 'upload' : event.key === 'End' ? 'youtube' : source === 'upload' ? 'youtube' : 'upload'; setSource(target); document.getElementById('source-tab-' + target)?.focus(); } }}><StudioIcon name={value === 'upload' ? 'upload' : 'play'} />{t(value === 'upload' ? 'localVideo' : 'youtubeLink')}</button>)}</div>
            {source === 'upload' ? <div id="source-panel-upload" role="tabpanel" aria-labelledby="source-tab-upload" className={'media-dropzone' + (drag ? ' is-dragging' : '') + (uploading || validating ? ' is-busy' : '')} onDragOver={(event) => { event.preventDefault(); if (!uploading && !validating) setDrag(true); }} onDragLeave={() => setDrag(false)} onDrop={(event) => { event.preventDefault(); setDrag(false); if (event.dataTransfer.files.length === 1) void select(event.dataTransfer.files[0]); else setCode('unsupported_file'); }}>
                <svg className="media-drop-waves" viewBox="0 0 600 100" preserveAspectRatio="none" aria-hidden="true"><path d="M0 40C100-30 190 140 340 75S510 15 600 40V100H0Z" /><path d="M0 70C110 0 180 150 350 90S500 25 600 70V100H0Z" /></svg>
                <StudioIcon name={uploading || validating ? 'loader' : 'upload'} className={'media-cloud-icon' + (uploading || validating ? ' is-spinning' : '')} />
                <h2>{uploading ? t('uploading') : validating ? t('validating') : t('drop')}</h2><span>{t('or')}</span>
                <button type="button" className="media-button primary" onClick={() => input.current?.click()} disabled={uploading || validating || !!project?.run || !props.storage.ready || !props.runtime.mediaReady}><StudioIcon name="upload" />{project?.status === 'uploading' && !uploading ? t('chooseResume') : t('choose')}</button>
                <input ref={input} type="file" className="sr-only" tabIndex={-1} accept=".mp4,.mov,.mkv,.webm,.m4v" aria-label={t('choose')} onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) void select(file); }} />
                {project && (uploading || validating || project.status === 'uploading') && <div className="media-upload-progress"><progress aria-label={t('uploading')} value={progress} max={100} /><span>{formatBytes(project.uploadedBytes)} / {formatBytes(project.bytes)} · {progress}%</span>{uploading && <button type="button" className="media-text-button" onClick={() => abort.current?.abort()}>{t('cancel')}</button>}</div>}
            </div> : <section id="source-panel-youtube" role="tabpanel" aria-labelledby="source-tab-youtube" className="media-youtube-panel"><span className="media-youtube-mark"><StudioIcon name="play" /></span><h2>{t('youtubeLink')}</h2><p>{t('youtubeHint')}</p><form onSubmit={(event) => { event.preventDefault(); void startImport(); }}><label className="sr-only" htmlFor="youtube-url">{t('youtubeLink')}</label><input id="youtube-url" type="url" inputMode="url" value={youtubeUrl} onChange={(event) => { setYouTubeUrl(event.target.value); setCode(''); }} placeholder="https://www.youtube.com/watch?v=..." autoComplete="off" spellCheck={false} required disabled={!!project || !!importAction} /><button type="submit" className="media-button primary" disabled={!!project || !!importAction || !youtubeUrl.trim() || !props.storage.ready || !props.runtime.mediaReady || !props.runtime.youtubeReady}><StudioIcon name={importAction === 'start' ? 'loader' : 'download'} />{t(importAction === 'start' ? 'youtubeImporting' : 'importYouTube')}</button></form><p className="media-small-note">{t('youtubeAvailability')}</p>{!props.runtime.youtubeReady && <MediaNotice>{error('youtube_tools_missing')}</MediaNotice>}</section>}
            {project && <p className="media-source-hint"><StudioIcon name="info" />{t('sourceLocked')}</p>}
            <div className="media-file-rules">{source === 'upload' && <span><StudioIcon name="file" />MP4, MOV, MKV, WEBM, M4V</span>}<span><StudioIcon name="database" />{t('upTo')} {formatBytes(props.limits.bytes)}</span><span><StudioIcon name="play" />{t('oneVideo')}</span></div><p className="media-small-note">{t('duration')}: {t('upTo')} {formatTime(props.limits.duration)}</p>
        </section><section className="media-card media-upload-preview" aria-live="polite">{project ? <>{project.sourceType === 'youtube' && !project.metadata?.audio ? <div className="media-youtube-status"><span className="media-empty-icon"><StudioIcon name={importing || validating ? 'loader' : 'play'} className={importing || validating ? 'is-spinning' : ''} /></span><h2>{project.title}</h2><p>{t(importLabels[project.import?.stage || 'queued'] || 'youtubeImporting')}</p>{project.import?.downloadedBytes ? <small>{t('importedBytes')}: {formatBytes(project.import.downloadedBytes)}</small> : null}{(importing || validating) && <div className="media-import-progress" role="status" aria-label={t('youtubeImporting')}><span /></div>}{importing && <button type="button" className="media-button" disabled={!!importAction || project.import?.cancelRequested} onClick={() => void importRequest('importCancel')}><StudioIcon name="close" />{t(project.import?.cancelRequested ? 'cancelRequested' : 'cancelImport')}</button>}{['import_failed', 'import_cancelled', 'invalid'].includes(project.status) && <button type="button" className="media-button" disabled={!!importAction} onClick={() => void importRequest('importRetry')}><StudioIcon name="activity" />{t('retryImport')}</button>}</div> : <><MediaSummary project={project} />{ready && project.metadata ? <div className="media-validation-list">{[
            [t('uploadComplete'), '100%'], [t('readable'), ''], [t('duration'), formatTime(project.metadata.duration)], [t('resolution'), project.metadata.width + ' × ' + project.metadata.height], [t('audio'), t('detected')],
        ].map(([label, value]) => <div key={label}><StudioIcon name="check" /><strong>{label}</strong><span>{value}</span></div>)}<div><StudioIcon name="shield" /><strong>{t('stored')}</strong><span>{project.storageDisk === 'r2' ? 'Cloudflare R2' : 'Local'}</span></div></div> : <MediaNotice>{validating ? t('validating') : uploading ? t('uploading') : t('interrupted')}</MediaNotice>}</>}</> : <div className="media-preview-empty"><span className="media-empty-icon"><StudioIcon name="play" /></span><h2>{t('emptyPreview')}</h2><p>{t('emptyDetail')}</p></div>}</section></div>
        <footer className="media-actions"><button type="button" className="media-button media-clear-button" onClick={() => setConfirmClear(true)} disabled={!canClear || clearing || saving}><StudioIcon name="trash" />{t('clear')}</button>{project?.run ? <Link className="media-button primary" href={project.links.analysis}>{t('resume')}<StudioIcon name="arrow" /></Link> : <button type="button" className="media-button primary" disabled={!ready || !title.trim() || saving || uploading} onClick={() => void next()}>{t(saving ? 'saving' : 'continueSetup')}<StudioIcon name="arrow" /></button>}</footer>
        {!project && !!props.recentProjects?.length && <section className="media-recent"><h2>{t('recent')}</h2><div>{props.recentProjects.map((item) => <Link key={item.id} href={item.run ? item.links.analysis : item.links.uploadPage}><StudioIcon name="play" /><span><strong>{item.title}</strong><small>{item.sourceType === 'youtube' ? 'YouTube' : item.filename}{item.sourceType !== 'youtube' || item.metadata?.audio ? ' · ' + formatBytes(item.bytes) : ''}</small></span><StudioIcon name="arrow" /></Link>)}</div></section>}
        <ConfirmDialog open={confirmClear} message={t('clearFormQuestion')} onClose={() => setConfirmClear(false)} onConfirm={clear} busy={clearing} />
    </Wizard>;
}