import {voiceLabel} from '@/lib/media-voices';
import {useMediaText} from '@/lib/media-i18n';
import {useEffect, useRef, useState, type CSSProperties, type PointerEvent, type ReactNode} from 'react';
import {ActionTooltip} from '@/components/action-tooltip';
import {StudioIcon, type IconName} from '@/components/studio-icon';
import {SelectField, OutputLinks} from '@/components/media-workflow';
import {useRecapText} from '@/lib/recap-i18n';
import {formatBytes, formatTime} from '@/lib/media-api';
import {liveCaption, previewDimensions} from '@/lib/recap-preview';
import type {MediaLine, MediaPreview, MediaProject, MediaSettings} from '@/types/media';
import '../../css/live-recap-editor.css';

type Props = {
    project: MediaProject; settings: MediaSettings; lines: MediaLine[]; selected: number; title: string;
    onSelect: (index: number) => void; onChange: <K extends keyof MediaSettings>(key: K, value: MediaSettings[K]) => void;
    disabled: boolean; dirty: boolean; preview: MediaPreview | null; onPreview: () => void; canPreview: boolean;
    onUndo: () => void; onRedo: () => void; canUndo: boolean; canRedo: boolean; onReset: () => void;
    children: ReactNode; watchRequest?: number;
};

export function LiveRecapEditor({project, settings, lines, selected, title, onSelect, onChange, disabled, dirty, preview, onPreview, canPreview, onUndo, onRedo, canUndo, canRedo, onReset, children, watchRequest = 0}: Props) {
    const t = useRecapText();
    const {locale} = useMediaText();
    const player = useRef<HTMLVideoElement>(null), canvas = useRef<HTMLCanvasElement>(null), viewport = useRef<HTMLDivElement>(null), shell = useRef<HTMLDivElement>(null);
    const [mode, setMode] = useState<'live' | 'original' | 'rendered'>('live');
    const [safe, setSafe] = useState(false), [expanded, setExpanded] = useState(false), [playing, setPlaying] = useState(false), [muted, setMuted] = useState(true);
    const [time, setTime] = useState(0), [width, setWidth] = useState(0), [failed, setFailed] = useState(false), [mediaReady,setMediaReady]=useState(false);
    const [mediaDuration, setMediaDuration] = useState(0), [dragging, setDragging] = useState<'caption' | 'cover' | null>(null);
    const advancing=useRef(false), pendingWatch=useRef(false);
    const draw = useRef<() => void>(() => {}), dragOrigin = useRef({y: 0, value: 0});
    const included = lines.map((line, index) => ({...line, originalIndex: index})).filter(line => line.included !== false);
    const scene = included.find(line => line.originalIndex === selected) ?? included[0];
    const renderedPreview = preview?.kind === 'video' && preview.status === 'completed' && preview.links.file ? preview : null;
    const canRendered = !!renderedPreview || project.hasOutput;
    const rendered = mode === 'rendered' && canRendered;
    const src = rendered ? renderedPreview?.links.file ?? project.links.video : project.links.source;
    const movie = settings.workflow === 'movie';
    const [outWidth, outHeight] = rendered && !renderedPreview && project.metadata?.output?.width && project.metadata.output.height ? [project.metadata.output.width, project.metadata.output.height] : mode === 'original' ? [project.metadata?.width || 1920, project.metadata?.height || 1080] : previewDimensions(settings, project.metadata?.width, project.metadata?.height);
    const scale = width / outWidth;
    const caption = liveCaption(lines, time);
    const start = !rendered && movie && scene ? scene.start : 0;
    const end = !rendered && movie && scene ? scene.end : mediaDuration || project.metadata?.duration || 0;
    const range = Math.max(.01, end - start);
    const coverHeight = Math.max(.01, Math.min(settings.cover_height, 1 - settings.cover_top));

    useEffect(() => {
        const el = viewport.current;
        if (!el) return;
        const observer = new ResizeObserver(entries => setWidth(entries[0].contentRect.width));
        observer.observe(el); return () => observer.disconnect();
    }, [expanded, rendered]);
    useEffect(() => {
        const el = player.current;
        advancing.current=false;
        if (!el || rendered || !scene || el.readyState < 1) return;
        el.currentTime = scene.start; setTime(scene.start); draw.current();
    }, [selected, src, movie]);
    useEffect(() => {setFailed(false); setMediaReady(false); setPlaying(false);}, [src]);
    useEffect(() => {setMode('live');}, [settings]);
    useEffect(() => {if (!canRendered && mode === 'rendered') setMode('live');}, [canRendered, mode]);
    useEffect(() => {if (renderedPreview) setMode('rendered');}, [renderedPreview?.id]);
    useEffect(() => {
        if (!watchRequest || !project.hasOutput) return;
        pendingWatch.current = true;
        setMode('rendered'); setMuted(false);
        shell.current?.scrollIntoView({block:'nearest',behavior:'instant'});
    }, [watchRequest]);
    useEffect(() => {
        if (!pendingWatch.current || !rendered || !player.current || player.current.readyState < 2) return;
        pendingWatch.current = false;
        void player.current.play().catch(() => setPlaying(false));
    }, [mode, src, mediaReady]);
    useEffect(() => {
        if (!expanded) return;
        const previous = document.body.style.overflow;
        const opener = document.activeElement as HTMLElement | null;
        document.body.style.overflow = 'hidden'; shell.current?.focus();
        const close = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setExpanded(false);
            if (e.key === 'Tab' && shell.current) {
                const elements = Array.from(shell.current.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled),select:not(:disabled),[tabindex="0"]'));
                const first = elements[0], last = elements.at(-1);
                if (e.shiftKey && (document.activeElement === first || document.activeElement === shell.current)) {e.preventDefault(); last?.focus();}
                else if (!e.shiftKey && document.activeElement === last) {e.preventDefault(); first?.focus();}
            }
        };
        window.addEventListener('keydown', close);
        return () => {document.body.style.overflow = previous; window.removeEventListener('keydown', close); opener?.focus();};
    }, [expanded]);
    useEffect(() => {
        draw.current = () => {
            const video = player.current, target = canvas.current;
            if (!video || !target || video.readyState < 2 || rendered) return;
            const ctx = target.getContext('2d'); if (!ctx) return;
            const w = target.width, h = target.height, sw = video.videoWidth, sh = video.videoHeight;
            if (!sw || !sh) return;
            ctx.clearRect(0, 0, w, h); ctx.fillStyle = '#000'; ctx.fillRect(0, 0, w, h);
            const contain = Math.min(w / sw, h / sh), cover = Math.max(w / sw, h / sh);
            const image = (ratio: number) => ctx.drawImage(video, (w - sw * ratio) / 2, (h - sh * ratio) / 2, sw * ratio, sh * ratio);
            if (mode === 'live' && settings.aspect !== 'original' && ['auto', 'blur'].includes(settings.fill)) {
                ctx.save(); ctx.filter = 'blur(18px)'; image(cover * 1.12); ctx.restore();
            }
            image(mode === 'live' && settings.fill === 'crop' && settings.aspect !== 'original' ? cover : contain);
            if (mode === 'live' && settings.cover_mode === 'manual') {
                const y = Math.floor(settings.cover_top * h), band = Math.max(2, Math.ceil(coverHeight * h));
                if (settings.cover_style === 'dark_box') {ctx.fillStyle = 'rgba(0,0,0,.9)'; ctx.fillRect(0, y, w, band);}
                else {ctx.save(); ctx.beginPath(); ctx.rect(0, y, w, band); ctx.clip(); ctx.filter = 'blur(8px)'; ctx.drawImage(target, 0, 0); ctx.restore();}
            }
        };
        draw.current();
    }, [settings, mode, rendered, outWidth, outHeight, width, coverHeight]);
    useEffect(() => {
        if (!playing || rendered) return;
        let animation = 0, last = 0;
        const tick = (now: number) => {
            draw.current();
            if (now - last > 100) {setTime(player.current?.currentTime ?? 0); last = now;}
            animation = requestAnimationFrame(tick);
        };
        animation = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(animation);
    }, [playing, rendered]);

    function seek(next: number) {if (player.current) {player.current.currentTime = Math.max(start, Math.min(end, next)); setTime(next);}}
    function onLoaded() {
        const video = player.current; if (!video) return;
        setMediaDuration(video.duration); if (!rendered) video.currentTime = scene?.start ?? 0;
        setTime(rendered ? 0 : scene?.start ?? 0); draw.current();
        if (rendered && pendingWatch.current) {pendingWatch.current = false; void video.play().catch(() => setPlaying(false));}
    }
    function updateTime() {
        const video = player.current; if (!video) return;
        setTime(video.currentTime);
        if (!rendered && movie && scene && !video.paused && !advancing.current && video.currentTime >= scene.end) {
            const next = included[included.findIndex(item => item.originalIndex === scene.originalIndex) + 1];
            if (next) {advancing.current=true;onSelect(next.originalIndex); video.currentTime = next.start;}
            else {video.pause();}
        }
        draw.current();
    }
    function beginDrag(event: PointerEvent, kind: 'caption' | 'cover') {
        if (disabled || mode !== 'live') return;
        event.preventDefault(); event.currentTarget.setPointerCapture(event.pointerId);
        dragOrigin.current = {y: event.clientY, value: kind === 'cover' ? settings.cover_top : settings.subtitle_position === 'top' ? settings.subtitle_lift : settings.subtitle_position === 'middle' ? .5 : 1 - settings.subtitle_lift};
        setDragging(kind);
    }
    function drag(event: PointerEvent) {
        if (!dragging || !viewport.current) return;
        const value = dragOrigin.current.value + (event.clientY - dragOrigin.current.y) / viewport.current.getBoundingClientRect().height;
        if (dragging === 'cover') onChange('cover_top', Math.max(0, Math.min(1 - coverHeight, value)));
        else {const top = value <= .5; onChange('subtitle_position', top ? 'top' : 'bottom'); onChange('subtitle_lift', Math.max(0, Math.min(.5, top ? value : 1 - value)));}
    }
    function icon(name: IconName, label: string, action: () => void, pressed?: boolean, blocked = false) {
        return <ActionTooltip label={t(label)}><button type="button" className={'live-icon'+(name==='reset'?' live-reset-action':'')} aria-label={t(label)} aria-pressed={pressed} disabled={blocked} onClick={action}><StudioIcon name={name}/>{name==='reset'&&<span>{t('Reset')}</span>}</button></ActionTooltip>;
    }
    const fontSize = (settings.subtitle_size || Math.max(18, Math.round(outHeight * .038))) * scale;
    const captionStyle: CSSProperties = {
        color: settings.subtitle_color, fontFamily: settings.subtitle_font === 'Padauk' ? '"Padauk", "Noto Sans Myanmar Variable", sans-serif' : settings.subtitle_font + ', sans-serif',
        fontSize, fontWeight: settings.subtitle_bold ? 700 : 400,
        WebkitTextStroke: (settings.subtitle_background ? 0 : (settings.subtitle_outline ?? 2) * scale) + 'px #101010',
        textShadow: '0 ' + ((settings.subtitle_shadow ?? 1) * scale) + 'px ' + ((settings.subtitle_shadow ?? 1) * scale) + 'px #000',
        ...(settings.subtitle_position === 'middle' ? {top: '50%', transform: 'translateY(-50%)'} : settings.subtitle_position === 'top' ? {top: Math.max(15 / outHeight, settings.subtitle_lift) * 100 + '%'} : {bottom: Math.max(15 / outHeight, settings.subtitle_lift) * 100 + '%'}),
    };
    return <div className={'live-editor-layout'+(project.hasOutput||project.hasAudio||project.hasSubtitles?' has-downloads':'')}>
        <section className="live-preview-column">
            <div ref={shell} tabIndex={-1} style={{'--live-aspect-height':outHeight/outWidth} as CSSProperties} className={'live-preview-shell' + (expanded ? ' expanded' : '')} role={expanded ? 'dialog' : undefined} aria-modal={expanded || undefined} aria-label={t('Live Preview')}>
                <div className="live-preview-toolbar"><div className="live-view-modes">{(['live', 'original', ...(canRendered ? ['rendered'] : [])] as const).map(value => <button key={value} type="button" aria-pressed={mode === value} className={mode === value ? 'selected' : ''} onClick={() => {player.current?.pause(); setMode(value as typeof mode);}}>{t(value === 'live' ? 'Live Preview' : value === 'original' ? 'Original' : 'Rendered')}</button>)}</div><div className="live-toolbar-actions">{!rendered && icon('grid', 'Safe areas', () => setSafe(!safe), safe)}{icon(expanded ? 'close' : 'monitor', expanded ? 'Close preview' : 'Expand preview', () => setExpanded(!expanded))}</div></div>
                <div className="live-stage">
                    <div ref={viewport} className="live-output-frame" aria-busy={!mediaReady&&!failed} style={{aspectRatio: outWidth + '/' + outHeight, width: 'min(100%, calc(var(--live-stage-height) * ' + outWidth / outHeight + '))'}}>
                        <video ref={player} key={src} src={src} playsInline preload="metadata" muted={muted||(!rendered&&mode!=='original'&&(included.length>0&&included.every(line=>line.audio_muted)||!!included.find(line=>time>=line.start&&time<line.end)?.audio_muted))} className={rendered ? 'live-rendered-video' : 'live-source-video'} onLoadedMetadata={onLoaded} onLoadedData={() => {setMediaReady(true);draw.current();}} onSeeked={() => {draw.current(); setTime(player.current?.currentTime ?? 0);}} onTimeUpdate={updateTime} onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)} onEnded={() => setPlaying(false)} onError={() => setFailed(true)}/>
                        {!rendered && <canvas ref={canvas} width={Math.min(1600, outWidth)} height={Math.round(outHeight * Math.min(1600, outWidth) / outWidth)} aria-label={t('Live Preview')}/>}
                        {!rendered && mode === 'live' && settings.cover_mode === 'manual' && <div className={'live-cover-guide' + (dragging === 'cover' ? ' dragging' : '')} style={{top: settings.cover_top * 100 + '%', height: coverHeight * 100 + '%'}} onPointerDown={event => beginDrag(event, 'cover')} onPointerMove={drag} onPointerUp={() => setDragging(null)} onPointerCancel={() => setDragging(null)} title={t('Drag to reposition')}><span>{t('Cover')}</span></div>}
                        {!rendered && mode === 'live' && settings.burn_subtitles && caption && <div className="live-caption" style={captionStyle} onPointerDown={event => beginDrag(event, 'caption')} onPointerMove={drag} onPointerUp={() => setDragging(null)} onPointerCancel={() => setDragging(null)} title={t('Drag to reposition')}><span style={{background: settings.subtitle_background ? 'rgba(0,0,0,' + (settings.subtitle_opacity ?? .5) + ')' : undefined, padding: settings.subtitle_background ? Math.max(1, (settings.subtitle_outline ?? 2) * scale) : 0}}>{caption}</span></div>}
                        {!rendered && safe && <div className="live-safe-area"><span>{t('Safe areas')}</span></div>}
                        {!mediaReady&&!failed&&<div className="live-media-loading" role="status"><StudioIcon name="loader" className="is-spinning"/><span>{t('Loading video…')}</span></div>}{failed && <p className="live-media-error" role="alert">{t('Preview could not load.')} <button type="button" onClick={() => {setFailed(false);setMediaReady(false); player.current?.load();}}>{t('Retry')}</button></p>}
                    </div>
                </div>
                <div className="live-transport">{icon(playing ? 'pause' : 'play', playing ? 'Pause' : 'Play', () => {const el = player.current; if (!el) return; if (el.paused) {if (el.currentTime >= end - .1) el.currentTime = start; void el.play().catch(() => setFailed(true));} else el.pause();}, undefined, failed||!mediaReady)}<span>{formatTime(Math.max(0, time - start))} <small>/ {formatTime(range)}</small></span><input aria-label={t('Seek preview')} type="range" min={start} max={Math.max(start + .01, end)} step=".05" value={Math.max(start, Math.min(end, time))} onChange={e => seek(Number(e.target.value))}/>{icon(muted ? 'headphones' : 'activity', muted ? 'Unmute source audio' : 'Mute audio', () => setMuted(!muted), !muted)}</div>
                <p className="live-preview-hint"><StudioIcon name={rendered ? 'check' : 'info'}/>{t(rendered ? 'Rendered output · saved settings at render time' : 'Live visuals · source audio. Render Preview checks AI voice and final timing.')}{rendered && dirty && <b>{t('Unsaved changes')}</b>}</p>
            </div>
            <section className="live-scene-controls" aria-label={t('Preview scene')}>
                <header><span><StudioIcon name="file"/>{t('Preview scene')}</span><button type="button" className="media-button live-render-preview" disabled={disabled || !canPreview || !scene} onClick={onPreview}><StudioIcon name="play"/>{t('Render Preview')}</button></header>
                <div className="live-scene-strip"><SelectField label={t('Preview scene')} value={String(scene?.originalIndex ?? '')} options={included.map(item => ({value: String(item.originalIndex), label: t('Scene') + ' ' + (item.originalIndex + 1) + ' · ' + formatTime(item.planned_duration??item.end-item.start), detail:t('Source Video')+': '+formatTime(item.start)+' – '+formatTime(item.end)}))} onChange={value => {onSelect(Number(value)); setMode('live');}} disabled={!included.length}/></div>
            </section>
            <section className="live-project-info" aria-label={t('Project details')}><header><span><StudioIcon name="file"/><strong>{t('Project details')}</strong></span><small>{included.length} {t('Scenes')} · {settings.aspect} · {settings.output.toUpperCase()}</small></header><dl><div><dt>{t('Project Title')}</dt><dd title={title}>{title}</dd></div><div><dt>{t('Source Video')}</dt><dd>{formatTime(project.metadata?.duration ?? 0)} · {formatBytes(project.bytes)}</dd></div><div><dt>{t('Output resolution')}</dt><dd>{outWidth} × {outHeight}</dd></div><div><dt>{t('Narration Voice')}</dt><dd>{settings.mode === 'subs' ? '—' : voiceLabel(settings.voice,locale)}</dd></div></dl></section>
            {project.hasAudio && <audio controls preload="none" src={project.links.audio}/>}
            {(project.hasOutput || project.hasAudio || project.hasSubtitles) && <div className="live-downloads"><span className={'live-export-status'+(project.status==='completed'&&!dirty?' is-ready':'')} role="status"><StudioIcon name={project.status==='completed'&&!dirty?'check':'clock'}/>{t(project.status==='completed'&&!dirty?'Your output is ready':'Last completed export')}</span><OutputLinks project={project}/>{project.metadata?.output && 'styled' in project.metadata.output && !!project.metadata.output.styled && <a className="media-button" href={project.links.styledSubtitles}>ASS</a>}</div>}
        </section>
        <aside className="live-tools-column"><div className="live-tools-heading"><strong><StudioIcon name="sliders"/>{t('Editing tools')}</strong><div>{icon('undo', 'Undo', onUndo, undefined, disabled || !canUndo)}{icon('redo', 'Redo', onRedo, undefined, disabled || !canRedo)}{icon('reset', 'Reset tab', onReset, undefined, disabled)}</div></div>{children}<p className="live-tools-footnote"><span/>{t(dirty ? 'Unsaved changes' : 'Saved')}<small>{t('Visual changes update instantly')}</small></p></aside>
    </div>;
}