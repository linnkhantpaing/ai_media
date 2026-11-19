import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useMediaProject } from '@/components/media-workflow';
import { MediaApiError, failureCode, mediaApi } from '@/lib/media-api';
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
export function useRecapSource(props: MediaPageProps) {
    const { auth, frameLinks } = usePage<SharedData>().props;
    const { project, setProject, pollError } = useMediaProject(props.initialProject);
    const [title, setTitle] = useState(props.initialProject?.title || '');
    const [source, setSource] = useState<'upload' | 'youtube'>(props.initialProject?.sourceType || 'upload');
    const [youtubeUrl, setYouTubeUrl] = useState(props.initialProject?.sourceUrl || '');
    const [uploading, setUploading] = useState(false); const [selectedFile, setSelectedFile] = useState<{name:string;size:number}|null>(null); const [importAction, setImportAction] = useState('');
    const [clearing, setClearing] = useState(false); const [drag, setDrag] = useState(false); const [code, setCode] = useState('');
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
    const progress = project && project.bytes > 0 ? Math.min(100, Math.floor(project.uploadedBytes / project.bytes * 100)) : 0;
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
        const version = epoch.current; lock.current = true; setSelectedFile({name:file.name,size:file.size}); setUploading(true); const controller = new AbortController(); abort.current = controller;
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
        setProject(null); setSelectedFile(null); setTitle(''); setYouTubeUrl(''); setSource('upload'); setCode(''); setUploading(false); setImportAction(''); setDrag(false); titleEdited.current = false; client.current = null; remember(); if (input.current) input.current.value = '';
        router.visit(frameLinks.newProject!, { replace: true, preserveState: false, onFinish: () => setClearing(false) });
    }
    return {project,setProject,pollError,title,setTitle,source,setSource,youtubeUrl,setYouTubeUrl,uploading,selectedFile,importAction,input,select,startImport,importRequest,clear,drag,setDrag,code,setCode,ready,validating,importing,progress,remember,titleEdited};
}
