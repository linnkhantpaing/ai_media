import {ProcessingAllowanceNotice} from '@/components/processing-allowance-notice';
import {useToast} from '@/lib/toast';
import { useEffect, useState } from 'react';
import { ConfirmDialog, MediaNotice, MediaSummary, OutputLinks, SetupLink, StageList, Wizard, useMediaProject } from '@/components/media-workflow';
import {useCreatorText} from '@/lib/media-creator-i18n';
import {MediaCreatorTools} from '@/components/media-creator-tools';
import { StudioIcon } from '@/components/studio-icon';
import { MediaApiError, busyStatuses, failureCode, formatTime, mediaApi } from '@/lib/media-api';
import { useMediaText } from '@/lib/media-i18n';
import type { MediaLine, MediaPageProps, MediaProject } from '@/types/media';

export default function AnalyzeProject(props: MediaPageProps) {
    const toast=useToast();
    const {t:creator}=useCreatorText();const { t, error } = useMediaText(); const { project: current, setProject, pollError } = useMediaProject(props.initialProject); const project = current!;
    const [toolBusy,setToolBusy]=useState(project.previewBusy); const [action, setAction] = useState(''); const [code, setCode] = useState(''); const [lines, setLines] = useState<MediaLine[]>(project.lines); const [saved, setSaved] = useState(false); const [confirmCancel, setConfirmCancel] = useState(false);
    useEffect(() => { if (project.status === 'awaiting_review') { setLines(project.lines); setSaved(false); } }, [project.id, project.run?.id, project.status]);
    const busy = busyStatuses.includes(project.status); const review = project.status === 'awaiting_review'; const completed = project.status === 'completed'; const failed = project.status === 'failed'; const cancelled = project.status === 'cancelled';
    const generating = project.run?.phase === 'generate'; const progress = project.run?.progress || 0;
    const stages = Object.keys(project.run?.stages || {}).length ? project.run!.stages : { prepare: 'waiting', transcribe: 'waiting', context: 'waiting', translate: 'waiting' };
    const disabled = !!action||toolBusy; const canStart = props.runtime.aiReady && props.runtime.mediaReady;
    async function perform(kind: 'start' | 'retry' | 'cancel' | 'script' | 'generate' | 'reopen') {
        if (action) return; setAction(kind); setCode(''); setSaved(false);
        try {
            if (kind === 'script' || kind === 'generate') {
                const data = await mediaApi<{ project: MediaProject }>(project.links.script, 'PATCH', { lines: lines.map((line) => ({ index: line.index, text: line.text })) });
                setProject(data.project); setSaved(true);
                if (kind === 'script') return;
            }
            const data = await mediaApi<{ project: MediaProject }>(project.links[kind], 'POST', {});
            setProject(data.project); setConfirmCancel(false);
        } catch (reason) { if(reason instanceof MediaApiError && Object.keys(reason.fields).length)toast.error(Object.values(reason.fields).flat()[0]);else setCode(failureCode(reason)); }
        finally { setAction(''); }
    }
    const heading = review ? t('reviewTitle') : completed ? t('result') : failed ? t('failed') : cancelled ? t('cancelled') : project.status === 'cancel_requested' ? t('cancelRequested') : project.status === 'queued' ? t('queued') : busy ? t(generating ? 'generating' : 'analyzing') : t('start');
    return <Wizard step={3}>
        {!busy&&!completed&&<ProcessingAllowanceNotice project={project}/>}
        {(code || project.errorCode) && <MediaNotice danger>{error(code || project.errorCode!)}</MediaNotice>}
        {!props.runtime.aiReady && <MediaNotice danger>{error('ai_key_missing')}</MediaNotice>}
        {!props.runtime.mediaReady && <MediaNotice danger>{error('media_tools_missing')}</MediaNotice>}
        {pollError && <MediaNotice>{t('pollError')}</MediaNotice>}
        <div className="media-analysis-summary"><MediaSummary project={project}><SetupLink project={project} disabled={busy||toolBusy} /></MediaSummary></div>
        <div className="media-analysis-grid"><ol className="media-phases">{(['analyzeVideo', 'translate', 'generate'] as const).map((key, index) => {
            const active = index === 0 && !generating && !review && !completed || index === 1 && (review || project.status === 'translating') || index === 2 && (generating || completed);
            const done = completed || index < 2 && (generating || review) || index === 0 && project.status === 'translating';
            return <li key={key} className={done ? 'is-done' : active ? 'is-current' : ''}><span className="media-phase-number">{done ? <StudioIcon name="check" /> : index + 1}</span><div><h2>{index===2&&['mp3','wav'].includes(project.settings.output)?creator('generateAudio'):t(key)}</h2><p>{t((['analyzeVideoHint', 'translateHint', 'generateHint'] as const)[index])}</p></div></li>;
        })}</ol><section className="media-card media-progress-card" aria-live="polite"><div className="media-progress-heading"><div className={'media-progress-ring' + (failed || cancelled ? ' is-stopped' : '')} role="progressbar" aria-label={heading} aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100} style={{ background: 'conic-gradient(var(--primary) ' + progress * 3.6 + 'deg, var(--surface-elevated) 0)' }}><span>{completed || review ? <StudioIcon name="check" /> : progress + '%'}</span></div><div><h2>{heading}</h2><p>{review ? t('reviewDetail') : project.status === 'queued' ? t('queuedHint') : t('progressHint')}</p></div></div>
            <StageList stages={stages} />
            {busy && <MediaNotice>{t('backgroundProcessing')}</MediaNotice>}
            {review && <span className="media-badge"><StudioIcon name="eye" />{t('review')}</span>}
            {!busy && !review && !completed && <div className="media-actions"><SetupLink project={project} /><button type="button" className="media-button primary" disabled={disabled || !canStart} onClick={() => void perform(failed ? 'retry' : 'start')}><StudioIcon name={failed ? 'activity' : 'sparkles'} />{t(failed ? 'retry' : 'start')}</button></div>}
        </section></div>
        {review && <section className="media-card media-script-review"><header><div><h2>{t('reviewTitle')}</h2><p>{t('reviewDetail')}</p></div>{saved && <span className="media-badge"><StudioIcon name="check" />{t('saved')}</span>}</header><fieldset disabled={disabled}>{lines.map((line, index) => <div className="media-script-line" key={line.index}><div className="media-script-time"><span>{index + 1}</span><time>{formatTime(line.start)} – {formatTime(line.end)}</time></div><div className="media-original-line"><span>{t('originalText')}</span><p>{line.source_text}</p></div><label><span>{t('burmeseText')}</span><textarea rows={Math.max(2, Math.min(6, Math.ceil(line.text.length / 65)))} maxLength={1500} value={line.text} onChange={(event) => { setLines((current) => current.map((item, currentIndex) => currentIndex === index ? { ...item, text: event.target.value } : item)); setSaved(false); }} required /></label></div>)}</fieldset><footer className="media-actions"><button type="button" className="media-button" onClick={() => void perform('script')} disabled={disabled || lines.some((line) => !line.text.trim())}><StudioIcon name="file" />{t(action === 'script' ? 'saving' : 'save')}</button><button type="button" className="media-button primary" onClick={() => void perform('generate')} disabled={disabled || !canStart || lines.some((line) => !line.text.trim())}><StudioIcon name="sparkles" />{t(action === 'generate' ? 'saving' : 'saveGenerate')}</button></footer></section>}
        {review&&<MediaCreatorTools project={project} settings={project.settings} lines={lines} disabled={!!action||!props.runtime.aiReady} onBusyChange={setToolBusy} onTextChange={(index,text)=>{setLines(current=>current.map(line=>line.index===index?{...line,text}:line));setSaved(false);}}/>}
        {completed && <section className="media-card media-result"><div className="media-result-heading"><span className="media-field-icon"><StudioIcon name="check" /></span><h2>{t('result')}</h2></div>{project.hasAudio&&<audio className="creator-result-audio" controls preload="metadata" src={project.links.audio}/>} {project.hasOutput && <video controls playsInline preload="metadata" className="media-result-video" src={project.links.video} poster={project.links.poster} />}<OutputLinks project={project} /><div className="media-actions"><button type="button" className="media-button" onClick={() => void perform('reopen')} disabled={disabled}><StudioIcon name="edit" />{t('editAgain')}</button></div></section>}
        {!completed && (project.hasOutput || project.hasAudio || project.hasSubtitles) && <section className="media-card media-result"><p>{t('previousOutput')}</p><OutputLinks project={project} /></section>}
        {busy && <footer className="media-actions"><button type="button" className="media-button" onClick={() => setConfirmCancel(true)} disabled={disabled || project.status === 'cancel_requested'}><StudioIcon name="close" />{t(project.status === 'cancel_requested' ? 'cancelRequested' : 'cancelAnalysis')}</button></footer>}
        <ConfirmDialog open={confirmCancel} message={t('cancelQuestion')} onClose={() => setConfirmCancel(false)} onConfirm={() => void perform('cancel')} busy={action === 'cancel'} />
    </Wizard>;
}