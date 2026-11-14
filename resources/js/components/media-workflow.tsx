import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { StudioIcon, type IconName } from '@/components/studio-icon';
import {useCreatorText} from '@/lib/media-creator-i18n';
import AppLayout from '@/layouts/app-layout';
import { busyStatuses, formatBytes, formatTime, mediaApi } from '@/lib/media-api';
import { useMediaText, type MediaWord } from '@/lib/media-i18n';
import type { FrameKey } from '@/types';
import type { MediaProject } from '@/types/media';

export function Wizard({ step, children }: { step: 1 | 2 | 3; children: ReactNode }) {
    const { t } = useMediaText();
    const frame: FrameKey = step === 1 ? 'newProject' : step === 2 ? 'projectSetup' : 'projectProcessing';
    const title = t(step === 1 ? 'newProject' : step === 2 ? 'setup' : 'analyze');
    return <AppLayout frame={frame} breadcrumbLabel={title}><Head title={title} /><section className="media-wizard"><header className="media-wizard-header"><div className="media-page-title"><span className="media-title-icon"><StudioIcon name={step === 1 ? 'play' : step === 2 ? 'settings' : 'sparkles'} /></span><div><h1>{title}</h1><p>{t(step === 1 ? 'uploadDescription' : step === 2 ? 'setupDescription' : 'analyzeDescription')}</p><i aria-hidden="true" /></div></div><ol className="media-stepper" aria-label={title}>{(['upload', 'setup', 'analyze'] as const).map((key, index) => <li key={key} className={index + 1 === step ? 'is-current' : index + 1 < step ? 'is-done' : ''} aria-current={index + 1 === step ? 'step' : undefined}><span className="media-step-number">{index + 1 < step ? <StudioIcon name="check" /> : index + 1}</span><span>{t(key)}</span>{index < 2 && <StudioIcon name="arrow" />}</li>)}</ol></header>{children}</section></AppLayout>;
}
export function MediaNotice({ children, danger = false }: { children: ReactNode; danger?: boolean }) {
    return <div className={'media-notice' + (danger ? ' is-error' : '')} role={danger ? 'alert' : 'status'}><StudioIcon name={danger ? 'alert' : 'info'} /><div>{children}</div></div>;
}
export function FieldHeading({ icon, title, hint, children }: { icon: IconName; title: string; hint?: string; children?: ReactNode }) {
    return <div className="media-field-heading"><span className="media-field-icon"><StudioIcon name={icon} /></span><div><h2>{title}</h2>{hint && <p>{hint}</p>}</div>{children}</div>;
}
export function MediaSummary({ project, children }: { project: MediaProject; children?: ReactNode }) {
    const { t } = useMediaText(); const [preview, setPreview] = useState(false);
    return <><div className="media-summary"><button type="button" className="media-thumbnail" aria-label={t('source')} onClick={() => setPreview(true)} disabled={!project.metadata?.audio}>{project.metadata?.poster ? <img src={project.links.poster} alt="" /> : <StudioIcon name="play" />}<span className="media-play"><StudioIcon name="play" /></span>{project.metadata && <time>{formatTime(project.metadata.duration)}</time>}</button><div className="media-summary-info"><h2 title={project.filename}>{project.filename}</h2><div className="media-metadata"><span>{formatBytes(project.bytes)}</span>{project.metadata && <><span>{project.metadata.width} × {project.metadata.height}</span><span>{project.metadata.aspect}</span></>}</div>{project.metadata?.audio && <span className="media-badge"><StudioIcon name="check" />{t('videoVerified')}</span>}</div>{children}</div><MediaDialog open={preview} title={project.title} onClose={() => setPreview(false)}><video className="media-preview-video" controls playsInline preload="metadata" src={preview ? project.links.source : undefined} poster={project.metadata?.poster ? project.links.poster : undefined} /></MediaDialog></>;
}
export function MediaDialog({ open, title, onClose, children, className = '', headerIcon, headerDetail, animated = false }: { open: boolean; title: string; onClose: () => void; children: ReactNode; className?: string; headerIcon?: IconName; headerDetail?: ReactNode; animated?: boolean }) {
    const ref = useRef<HTMLDialogElement>(null); const id = useId(); const { t } = useMediaText();
    const [present, setPresent] = useState(open);
    const [motion, setMotion] = useState('closed');
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        let firstFrame = 0;
        let secondFrame = 0;
        let closeTimer: ReturnType<typeof setTimeout> | undefined;
        if (open) {
            setPresent(true);
            if (!dialog.open) dialog.showModal();
            if (animated) {
                setMotion('entering');
                firstFrame = requestAnimationFrame(() => {
                    secondFrame = requestAnimationFrame(() => setMotion('open'));
                });
            }
        } else {
            const finish = () => {
                if (dialog.open) dialog.close();
                setPresent(false);
                setMotion('closed');
            };
            if (dialog.open && animated && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                setMotion('closing');
                closeTimer = setTimeout(finish, 260);
            } else finish();
        }
        return () => {
            cancelAnimationFrame(firstFrame);
            cancelAnimationFrame(secondFrame);
            clearTimeout(closeTimer);
        };
    }, [open, animated]);
    return <dialog className={'media-dialog ' + className} ref={ref} data-state={animated ? motion : undefined} aria-labelledby={id} onKeyDown={(event) => { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); onClose(); } }} onCancel={(event) => { event.preventDefault(); onClose(); }} onClose={() => { if (open && !ref.current?.open) onClose(); }} onClick={(event) => { if (event.target === event.currentTarget) onClose(); }}><div className="media-dialog-inner"><header>{headerIcon && <span className="media-dialog-header-icon" aria-hidden="true"><StudioIcon name={headerIcon} /></span>}{headerIcon || headerDetail ? <div className="media-dialog-heading"><h2 id={id}>{title}</h2>{headerDetail && <div className="media-dialog-header-detail">{headerDetail}</div>}</div> : <h2 id={id}>{title}</h2>}<button type="button" className="icon-button" onClick={onClose} aria-label={t('close')}><StudioIcon name="close" /></button></header>{(animated ? present : open) && children}</div></dialog>;
}
export function ConfirmDialog({ open, message, onConfirm, onClose, busy = false }: { open: boolean; message: string; onConfirm: () => void; onClose: () => void; busy?: boolean }) {
    const { t } = useMediaText();
    return <MediaDialog open={open} title={t('confirm')} onClose={() => { if (!busy) onClose(); }}><p>{message}</p><div className="media-actions"><button type="button" className="media-button" onClick={onClose} disabled={busy}>{t('cancel')}</button><button type="button" className="media-button primary" onClick={onConfirm} disabled={busy}>{t(busy ? 'saving' : 'confirm')}</button></div></MediaDialog>;
}
export function SelectField({ value, options, onChange, label, disabled = false, required = false, invalid = false }: { value: string; options: { value: string; label: string; detail?: string; image?: string }[]; onChange: (value: string) => void; label: string; disabled?: boolean; required?: boolean; invalid?: boolean }) {
    const [open, setOpen] = useState(false); const [active, setActive] = useState(0); const root = useRef<HTMLDivElement>(null); const trigger = useRef<HTMLButtonElement>(null); const id = useId(); const selected = options.find((option) => option.value === value);
    useEffect(() => { if (!open) return; const close = (event: PointerEvent) => { if (!root.current?.contains(event.target as Node)) setOpen(false); }; document.addEventListener('pointerdown', close); return () => document.removeEventListener('pointerdown', close); }, [open]);
    useEffect(() => { if (open) document.getElementById(id + '-' + active)?.scrollIntoView({ block: 'nearest' }); }, [open, active, id]);
    const choose = (next: string) => { onChange(next); setOpen(false); trigger.current?.focus(); };
    return <div className="media-select" ref={root} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false); }}><button ref={trigger} type="button" className="media-select-trigger" disabled={disabled} role="combobox" aria-required={required || undefined} aria-invalid={invalid || undefined} aria-label={label} aria-haspopup="listbox" aria-expanded={open} aria-controls={id} aria-activedescendant={open ? id + '-' + active : undefined} onClick={() => { setActive(Math.max(0, options.findIndex((option) => option.value === value))); setOpen(!open); }} onKeyDown={(event) => { if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) { event.preventDefault(); if (!open) { setOpen(true); setActive(Math.max(0, options.findIndex((option) => option.value === value))); } else setActive(event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length); } else if (event.key === 'Escape') { event.preventDefault(); setOpen(false); } else if ((event.key === 'Enter' || event.key === ' ') && open) { event.preventDefault(); choose(options[active].value); } }}><span className={selected?.image?'media-option-image-label':undefined}>{selected?.image&&<img src={selected.image} alt="" width={22} height={16}/>}<strong>{selected?.label || label}</strong>{selected?.detail && <small>{selected.detail}</small>}</span><StudioIcon name="chevron" /></button>{open && <div id={id} role="listbox" aria-label={label} className="media-select-options">{options.map((option, index) => <button key={option.value} type="button" tabIndex={-1} role="option" id={id + '-' + index} aria-selected={value === option.value} className={active === index ? 'is-focused' : ''} onPointerMove={() => setActive(index)} onMouseDown={(event) => event.preventDefault()} onClick={() => choose(option.value)}><span className={option.image?'media-option-image-label':undefined}>{option.image&&<img src={option.image} alt="" width={22} height={16}/>}<span>{option.label}</span>{option.detail && <small>{option.detail}</small>}</span>{value === option.value && <StudioIcon name="check" />}</button>)}</div>}</div>;
}
export function Choice({ selected, onClick, icon, title, hint, disabled = false, className = '' }: { selected: boolean; onClick: () => void; icon: IconName; title: string; hint?: string; disabled?: boolean; className?: string }) {
    return <button type="button" className={'media-choice ' + className + (selected ? ' is-selected' : '')} aria-pressed={selected} onClick={onClick} disabled={disabled}><span className="media-choice-icon"><StudioIcon name={icon} /></span><span><strong>{title}</strong>{hint && <small>{hint}</small>}</span></button>;
}
export function useMediaProject(initial: MediaProject | null) {
    const [project, setProject] = useState(initial); const [pollError, setPollError] = useState(false);
    useEffect(() => { setProject(initial); }, [initial]);
    useEffect(() => {
        if (!project || !busyStatuses.includes(project.status)) return;
        const abort = new AbortController(); let timer: ReturnType<typeof setTimeout>; let stopped = false;
        const poll = async () => {
            try { const data = await mediaApi<{ project: MediaProject }>(project.links.status, 'GET', undefined, abort.signal); if (!stopped) { setProject(data.project); setPollError(false); } } catch { if (!stopped) setPollError(true); }
            if (!stopped) timer = setTimeout(poll, 2500);
        };
        timer = setTimeout(poll, 1200);
        return () => { stopped = true; clearTimeout(timer); abort.abort(); };
    }, [project?.id, project?.status]);
    return { project, setProject, pollError };
}
export function StageList({ stages }: { stages: Record<string, string> }) {
    const { t } = useMediaText(); const {t:creator}=useCreatorText(); const labels: Record<string, MediaWord> = { prepare: 'prepare', transcribe: 'transcribe', context: 'context', translate: 'translate', voice: 'voice', subtitles: 'subtitles', render: 'render', verify: 'verify', store: 'verify' };
    return <div className="media-stage-list">{['prepare', 'transcribe', 'context', 'translate', 'separate', 'voice', 'subtitles', 'render', 'verify', 'store'].filter((key) => key in stages).map((key) => { const state = stages[key]; return <div key={key} className={'media-stage is-' + state}><StudioIcon name={state === 'completed' ? 'check' : state === 'active' ? 'loader' : 'clock'} /><strong>{key==='separate'?creator('separate'):t(labels[key] || 'active')}</strong><span>{t(state === 'completed' ? 'completed' : state === 'active' ? 'active' : 'waiting')}</span>{state === 'active' && <div className="media-stage-pulse" aria-hidden="true" />}</div>; })}</div>;
}
export function OutputLinks({ project }: { project: MediaProject }) {
    const { t } = useMediaText(); const {t:creator}=useCreatorText();
    return <div className="media-actions">{project.hasAudio&&<a className="media-button primary" href={project.links.audio+'?download=1'}><StudioIcon name="download"/>{creator('downloadAudio')} · {project.metadata?.output?.format?.toUpperCase()}</a>}{project.hasOutput && <a className="media-button primary" href={project.links.video + '?download=1'}><StudioIcon name="download" />{t('downloadVideo')}</a>}{project.hasSubtitles && <a className="media-button" href={project.links.subtitles}><StudioIcon name="file" />{t('downloadSrt')}</a>}</div>;
}
export function SetupLink({ project, disabled = false }: { project: MediaProject; disabled?: boolean }) { const { t } = useMediaText(); return disabled ? <span className="media-button disabled"><StudioIcon name="settings" />{t('viewSetup')}</span> : <Link className="media-button" href={project.links.setup}><StudioIcon name="settings" />{t('viewSetup')}</Link>; }