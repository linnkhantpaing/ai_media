import {PageBoundaryButton} from '@/components/page-boundary-button';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { MediaDialog, MediaNotice, SelectField } from '@/components/media-workflow';
import { LibraryMenu, ProjectTile, projectDestination, type LibraryAction } from '@/components/project-library';
import { StudioIcon, type IconName } from '@/components/studio-icon';
import AppLayout from '@/layouts/app-layout';
import { busyStatuses, failureCode, formatBytes, mediaApi } from '@/lib/media-api';
import { useTranslations } from '@/lib/i18n';
import { useMediaText } from '@/lib/media-i18n';
import {useCreatorText} from '@/lib/media-creator-i18n';
import { useLibraryText } from '@/lib/library-i18n';
import { useLoading } from '@/lib/loading';
import type { FrameKey, SharedData } from '@/types';
import type { LibraryFilters, LibraryPageProps, LibraryProject } from '@/types/media';

const tabs: { value: string; frame: FrameKey; icon: IconName }[] = [
    { value: '', frame:'allProjects', icon:'folder' }, { value:'draft', frame:'drafts', icon:'file' },
    { value:'awaiting_review', frame:'awaitingReview', icon:'clock' }, { value:'processing', frame:'processing', icon:'loader' },
    { value:'completed', frame:'completed', icon:'check' }, { value:'needs_attention', frame:'needsAttention', icon:'alert' },
];
function FilterFields({ value, change, sort = false }: { value: LibraryFilters; change: (next: Partial<LibraryFilters>) => void; sort?: boolean }) {
    const { t } = useLibraryText();
    return <>
        <SelectField label={t('mode')} value={value.mode} onChange={mode => change({ mode })} options={[{value:'',label:t('allModes')},{value:'subs',label:t('subs')},{value:'dub',label:t('dub')}]} />
        <SelectField label={t('source')} value={value.source} onChange={source => change({ source })} options={[{value:'',label:t('allSources')},{value:'upload',label:t('local')},{value:'youtube',label:'YouTube'}]} />
        <div className="library-date-select"><StudioIcon name="calendar" /><SelectField label={t('date')} value={value.date} onChange={date => change({ date, ...(date === 'custom' ? {} : { from:'', to:'' }) })} options={[{value:'',label:t('anyTime')},{value:'today',label:t('today')},{value:'week',label:t('week')},{value:'custom',label:t('custom')}]} /></div>
        {sort && <SortField value={value.sort} change={sort => change({ sort })} />}
    </>;
}
function SortField({ value, change }: { value: string; change: (value: string) => void }) {
    const { t } = useLibraryText();
    return <SelectField label={t('sort')} value={value} onChange={change} options={(['newest','oldest','created','az'] as const).map(key => ({ value:key === 'az' ? 'title' : key, label:t(key) }))} />;
}
function DateFields({ from, to, change }: { from: string; to: string; change: (value: { from?: string; to?: string }) => void }) {
    const { t } = useLibraryText();
    return <><div className="library-date-fields"><label>{t('from')}<input type="date" required value={from} max={to || undefined} onChange={event => change({from:event.target.value})} /></label><label>{t('to')}<input type="date" required value={to} min={from || undefined} onChange={event => change({to:event.target.value})} /></label></div><p className="library-date-hint">{t('dateHint')}</p></>;
}

export default function ProjectIndex({ frame, projects, filters, counts, libraryTimezone, storage }: LibraryPageProps) {
    const {t:creator}=useCreatorText();
    const { t } = useLibraryText(); const { error } = useMediaText(); const { t: shell } = useTranslations(); const { frameLinks, links } = usePage<SharedData>().props;
    const [query, setQuery] = useState(filters.q); const [loading, setLoading] = useState(false); const [loadFailed, setLoadFailed] = useState(false);
    const [code, setCode] = useState(''); const [notice, setNotice] = useState(''); const [actionBusy, setActionBusy] = useState('');
    const [remove, setRemove] = useState<LibraryProject | null>(null); const [rename, setRename] = useState<LibraryProject | null>(null); const [title, setTitle] = useState('');
    const [preview, setPreview] = useState<LibraryProject | null>(null); const [previewError, setPreviewError] = useState(false);
    const [filterOpen, setFilterOpen] = useState(false); const [draft, setDraft] = useState(filters);
    const [dateOpen, setDateOpen] = useState(false); const [dates, setDates] = useState({from:filters.from,to:filters.to});
    const pendingAction = useRef(false);
    const { begin } = useLoading();
    const activeFilters = [!!filters.q,!!filters.mode,!!filters.source,!!filters.date].filter(Boolean).length;
    const url = frameLinks.allProjects!.split('?')[0];
    function httpFailure(response: { status: number }) {
        if ([401,419].includes(response.status)) setCode('session_expired');
        else setLoadFailed(true);
        return false;
    }
    const requestOptions = {
        preserveState:true, preserveScroll:true,
        onStart:() => { setLoading(true); setLoadFailed(false); },
        onFinish:() => setLoading(false),
        onError:() => { setLoadFailed(true); },
        onNetworkError:() => { setLoadFailed(true); return false; },
        onHttpException:httpFailure,
    };
    function navigate(next: Partial<LibraryFilters> = {}, page = 1, replace = false) {
        const merged = {...filters, q:query.trim(), ...next};
        const values = Object.fromEntries(Object.entries(merged).filter(([,value]) => value !== ''));
        router.get(url, {...values,page}, {...requestOptions,replace});
    }
    function refresh() { router.reload({...requestOptions,only:['projects','counts','storage']}); }
    useEffect(() => { setQuery(filters.q); setDateOpen(false); }, [filters.q,filters.date,filters.from,filters.to]);
    useEffect(() => {
        if (query === filters.q) return;
        const timer = setTimeout(() => navigate({q:query.trim()},1,true),350);
        return () => clearTimeout(timer);
    }, [query,filters.q]);
    useEffect(() => {
        if (!counts.processing && !projects.data.some(project => ['uploading','importing','validating'].includes(project.status))) return;
        const timer = setInterval(() => { if (document.visibilityState === 'visible' && !pendingAction.current && !document.querySelector('.library-menu') && !document.querySelector('dialog[open]')) router.reload({only:['projects','counts','storage'],showProgress:false,onNetworkError:() => {setLoadFailed(true);return false;},onHttpException:httpFailure}); },5000);
        return () => clearInterval(timer);
    }, [counts.processing,projects.data]);
    async function act(project: LibraryProject, endpoint: string, method = 'POST', body?: unknown, after?: () => void, reload = true) {
        if (pendingAction.current) return;
        const finishLoading = begin(t('working'));
        pendingAction.current = true; setActionBusy(project.id); setCode(''); setNotice('');
        try { await mediaApi(endpoint,method,body); after?.(); if (reload) refresh(); }
        catch(reason) { setCode(failureCode(reason)); }
        finally { pendingAction.current = false; setActionBusy(''); finishLoading(); }
    }
    function actions(project: LibraryProject): LibraryAction[] {
        const busy = busyStatuses.includes(project.status); const disabled = !!actionBusy; const destination = projectDestination(project);
        const items: LibraryAction[] = [
            {label:t(destination.label),icon:'folder',action:()=>router.visit(destination.href),disabled},
            {label:t('rename'),icon:'edit',action:()=>{setRename(project);setTitle(project.title);setCode('');},disabled:busy||disabled},
        ];
        if (project.hasOutput || project.metadata?.audio) items.push({label:t('preview'),icon:'play',action:()=>{setPreviewError(false);setPreview(project);},disabled});
        if (project.hasScript && !busy) items.push({label:t('editScript'),icon:'captions',disabled,action:()=>project.status === 'awaiting_review' ? router.visit(project.links.analysis) : void act(project,project.links.reopen,'POST',undefined,()=>router.visit(project.links.analysis),false)});
        if (project.metadata?.audio) items.push({label:t('adjust'),icon:'sliders',action:()=>router.visit(project.links.setup),disabled:busy||disabled});
        if (project.hasOutput) items.push({label:t('downloadVideo')+(project.outputState === 'previous' ? ' · '+t('previousOutput') : ''),icon:'download',href:project.links.video+'?download=1'});
        if(project.hasAudio)items.push({label:creator('downloadAudio')+' · '+(project.metadata?.output?.format?.toUpperCase()||''),icon:'download',href:project.links.audio+'?download=1'});
        if (project.hasSubtitles) items.push({label:t('downloadSrt')+(project.outputState === 'previous' ? ' · '+t('previousOutput') : ''),icon:'file',href:project.links.subtitles});
        if (['import_failed','import_cancelled'].includes(project.status)) items.push({label:t('retryImport'),icon:'upload',disabled,action:()=>void act(project,project.links.importRetry)});
        else if (project.run && ['failed','cancelled'].includes(project.status)) items.push({label:t('retry'),icon:'activity',disabled,action:()=>void act(project,project.links.retry)});
        if (project.status === 'importing') items.push({label:t('cancelImport'),icon:'close',disabled:disabled||project.import?.cancelRequested,action:()=>void act(project,project.links.importCancel)});
        else if (project.run && busy && project.status !== 'cancel_requested') items.push({label:t('cancelProcessing'),icon:'close',disabled,action:()=>void act(project,project.links.cancel)});
        items.push({label:t('delete'),icon:'trash',danger:true,disabled:busy||disabled,action:()=>{setCode('');setRemove(project);}});
        return items;
    }
    function reset() { setQuery(''); navigate({q:'',mode:'',source:'',date:'',from:'',to:'',sort:'newest'}); }
    function changeFilters(next: Partial<LibraryFilters>) {
        if (next.date === 'custom') { setDates({from:filters.from,to:filters.to}); setDateOpen(true); }
        else navigate(next);
    }
    const pageNumbers = [...new Set([1,projects.current_page-1,projects.current_page,projects.current_page+1,projects.last_page])].filter(page => page > 0 && page <= projects.last_page).sort((a,b)=>a-b);
    return <AppLayout frame={frame}><Head title={shell(frame)} /><section className="project-library" aria-busy={loading}>
        <header className="library-heading"><span className="library-heading-icon"><StudioIcon name="folder" /></span><div><h1>{shell(frame)}</h1><p>{t('description')}</p></div></header>
        <nav className="library-tabs" aria-label={shell('projects')}>{tabs.map(tab => <button key={tab.value} type="button" className={filters.status === tab.value ? 'is-selected' : ''} aria-current={filters.status === tab.value ? 'page' : undefined} onClick={()=>navigate({status:tab.value})}><StudioIcon name={tab.icon} /><span>{shell(tab.frame)}</span><b>{counts[tab.value || 'all'] ?? 0}</b></button>)}</nav>
        <div className="library-toolbar">
            <div className="library-search"><StudioIcon name="search" /><input type="search" aria-label={t('search')} placeholder={t('search')} value={query} maxLength={250} onChange={event=>setQuery(event.target.value)} />{query && <button type="button" className="icon-button" aria-label={t('clearSearch')} onClick={()=>setQuery('')}><StudioIcon name="close" /></button>}</div>
            <div className="library-desktop-filters"><FilterFields value={filters} change={changeFilters} /></div>
            <button type="button" className="media-button library-mobile-filters" onClick={()=>{setDraft({...filters,q:query});setFilterOpen(true);}}><StudioIcon name="sliders" />{t('filters')}{activeFilters > 0 && <b>{activeFilters}</b>}</button>
            <div className="library-sort"><SortField value={filters.sort} change={sort=>navigate({sort})} /></div>
            <div className="library-view" role="group" aria-label={t('grid')+' / '+t('list')}>{(['grid','list'] as const).map(view=><button key={view} type="button" aria-label={t(view)} aria-pressed={filters.view === view} onClick={()=>navigate({view},projects.current_page,true)}><StudioIcon name={view} /></button>)}</div>
        </div>
        {dateOpen && <form className="library-custom-dates" onSubmit={(event: FormEvent)=>{event.preventDefault();navigate({date:'custom',...dates});setDateOpen(false);}}><DateFields {...dates} change={next=>setDates({...dates,...next})} /><div className="media-actions"><button className="media-button" type="button" onClick={()=>setDateOpen(false)}>{t('cancel')}</button><button className="media-button primary" disabled={!dates.from||!dates.to||dates.to<dates.from}>{t('apply')}</button></div></form>}
        {(activeFilters > 0 || filters.sort !== 'newest') && <div className="library-filter-summary"><span>{filters.date === 'custom' ? filters.from+' – '+filters.to+' · ' : ''}{filters.date ? t('dateHint') : ''}</span><button type="button" onClick={reset}><StudioIcon name="close" />{t('reset')}</button></div>}
        <div className="library-live-message" role="status" aria-live="polite">{notice}</div>
        {code && <MediaNotice danger>{error(code)}{code === 'session_expired' && <a className="library-inline-button" href={links.login}>{t('signIn')}</a>}</MediaNotice>}
        {loadFailed && <MediaNotice danger>{t('loadFailed')} <button type="button" className="library-inline-button" onClick={refresh}>{t('tryAgain')}</button></MediaNotice>}        {projects.data.length > 0 ? <>
            {filters.view === 'list' && <div className="library-list-heading" aria-hidden="true"><span>{t('project')}</span><span>{t('status')}</span><span>{t('updated')}</span><span>{t('files')}</span><span>{t('actions')}</span></div>}
            <div className={'library-projects is-'+filters.view}>{projects.data.map(project=><ProjectTile key={project.id} project={project} list={filters.view === 'list'} timezone={libraryTimezone} onPreview={()=>{setPreviewError(false);setPreview(project);}} menu={<LibraryMenu title={project.title} actions={actions(project)} busyHint={busyStatuses.includes(project.status) ? t('busyHint') : undefined} />} />)}</div>
        </> : <div className="library-empty"><span><StudioIcon name={activeFilters || filters.status ? 'search' : 'folder'} /></span><h2>{t(activeFilters || filters.status ? 'noMatches' : 'empty')}</h2><p>{t(activeFilters || filters.status ? 'noMatchesHint' : 'emptyHint')}</p>{activeFilters ? <button type="button" className="media-button" onClick={reset}>{t('reset')}</button> : <Link href={frameLinks.newProject!} className="media-button primary"><StudioIcon name="plus" />{shell('newProject')}</Link>}</div>}
        <footer className="library-footer"><p>{t('showing',{from:projects.from ?? 0,to:projects.to ?? 0,total:projects.total})}</p><nav className="library-pagination" aria-label={shell('allProjects')}><PageBoundaryButton edge="first" page={projects.current_page} pages={projects.last_page} onNavigate={target=>navigate({},target)} disabled={loading}/><button type="button" aria-label={t('previousPage')} disabled={projects.current_page <= 1 || loading} onClick={()=>navigate({},projects.current_page-1)}><StudioIcon name="back" /></button>{pageNumbers.map((page,index)=><span key={page}>{index > 0 && page-pageNumbers[index-1] > 1 && <span className="library-pagination-gap">…</span>}<button type="button" aria-label={t('page',{page})} aria-current={page === projects.current_page ? 'page' : undefined} onClick={()=>navigate({},page)} disabled={loading}>{page}</button></span>)}<button type="button" aria-label={t('nextPage')} disabled={projects.current_page >= projects.last_page || loading} onClick={()=>navigate({},projects.current_page+1)}><StudioIcon name="arrow" /></button><PageBoundaryButton edge="last" page={projects.current_page} pages={projects.last_page} onNavigate={target=>navigate({},target)} disabled={loading}/></nav></footer>
        <p className="library-storage"><StudioIcon name="database" />{formatBytes(storage.used)} / {formatBytes(storage.limit)} · {storage.disk === 'r2' ? 'Cloudflare R2' : 'Local'}</p>
        <MediaDialog open={filterOpen} title={t('filters')} onClose={()=>setFilterOpen(false)}><form className="library-filter-sheet" onSubmit={event=>{event.preventDefault();navigate(draft);setFilterOpen(false);}}><FilterFields value={draft} change={next=>setDraft({...draft,...next})} sort />{draft.date === 'custom' && <DateFields from={draft.from} to={draft.to} change={next=>setDraft({...draft,...next})} />}<div className="media-actions"><button className="media-button" type="button" onClick={()=>setDraft({...draft,mode:'',source:'',date:'',from:'',to:'',sort:'newest',q:''})}>{t('reset')}</button><button className="media-button primary" disabled={draft.date === 'custom' && (!draft.from || !draft.to || draft.to<draft.from)}>{t('apply')}</button></div></form></MediaDialog>
        <MediaDialog open={!!rename} title={t('rename')} onClose={()=>{if(!actionBusy)setRename(null);}}><form className="library-rename-form" onSubmit={event=>{event.preventDefault();if(rename&&title.trim())void act(rename,rename.links.update,'PATCH',{title:title.trim()},()=>{setRename(null);setNotice(t('saved'));});}}><label htmlFor="library-project-title">{t('title')}</label><input id="library-project-title" autoFocus required maxLength={250} value={title} onChange={event=>setTitle(event.target.value)} disabled={!!actionBusy} />{code && <MediaNotice danger>{error(code)}</MediaNotice>}<div className="media-actions"><button type="button" className="media-button" disabled={!!actionBusy} onClick={()=>setRename(null)}>{t('cancel')}</button><button className="media-button primary" disabled={!!actionBusy||!title.trim()}>{t(actionBusy ? 'working' : 'save')}</button></div></form></MediaDialog>
        <MediaDialog open={!!remove} title={t('delete')} onClose={()=>{if(!actionBusy)setRemove(null);}}><div className="library-delete-dialog"><span className="library-delete-icon"><StudioIcon name="trash" /></span><h3>{remove?.title}</h3><p>{t('deleteHint')}</p>{code && <MediaNotice danger>{error(code)}</MediaNotice>}<div className="media-actions"><button className="media-button" type="button" disabled={!!actionBusy} onClick={()=>setRemove(null)}>{t('cancel')}</button><button className="media-button library-delete-button" type="button" disabled={!!actionBusy} onClick={()=>{if(remove)void act(remove,remove.links.destroy,'DELETE',{confirm:true},()=>{setRemove(null);setNotice(t('deleted'));});}}><StudioIcon name="trash" />{t(actionBusy ? 'working' : 'delete')}</button></div></div></MediaDialog>
        <MediaDialog open={!!preview} title={preview?.title || t('preview')} onClose={()=>setPreview(null)}>{preview && <div className="library-preview"><span className="library-preview-label">{preview.hasOutput||preview.hasAudio ? t(preview.outputState === 'previous' ? 'previousOutput' : preview.outputState === 'current' ? 'current' : 'output') : t('sourceVideo')}</span><>{preview.hasAudio&&!preview.hasOutput?<audio className="creator-result-audio" controls preload="metadata" src={preview.links.audio} onError={()=>setPreviewError(true)}/>:<video key={preview.id} controls playsInline preload="metadata" poster={preview.metadata?.poster ? preview.links.poster : undefined} src={preview.hasOutput ? preview.links.video : preview.links.source} onError={()=>setPreviewError(true)} />}</>{previewError && <MediaNotice danger>{t('fileUnavailable')}</MediaNotice>}{preview.changesPending && <p>{t('pendingHint')}</p>}<div className="media-actions">{preview.hasOutput && <a href={preview.links.video+'?download=1'} className="media-button primary"><StudioIcon name="download" />{t('downloadVideo')}</a>}{preview.hasAudio&&<a className="media-button primary" href={preview.links.audio+'?download=1'}><StudioIcon name="download"/>{preview.metadata?.output?.format?.toUpperCase()||'Audio'}</a>}{preview.hasSubtitles && <a href={preview.links.subtitles} className="media-button"><StudioIcon name="file" />{t('downloadSrt')}</a>}<Link className="media-button" href={projectDestination(preview).href}>{t('open')}<StudioIcon name="arrow" /></Link></div></div>}</MediaDialog>
    </section></AppLayout>;
}