import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import AppLayout from '@/layouts/app-layout';
import { StudioIcon, type IconName } from '@/components/studio-icon';
import { MediaDialog, SelectField } from '@/components/media-workflow';
import { DatePicker } from '@/components/date-picker';
import { formatBytes, mediaApi, MediaApiError } from '@/lib/media-api';
import { useToast } from '@/lib/toast';
import { useAiCostText, type AiCostWord } from '@/lib/ai-costs-i18n';
import type { SharedData } from '@/types';
import type { AiCostFilters, AiCostPricing, AiCostProject, AiCostReportData, AiCostState, AiModelPrices } from '@/types/ai-costs';
import '../../../css/revenue.css';
import '../../../css/processing-report.css';
import '../../../css/ai-costs.css';

type Props = { report: AiCostReportData; filters: AiCostFilters; pricing: AiCostPricing; endpoints: { index: string; settings: string; project: string } };
const stateTone: Record<AiCostState, string> = { completed: 'is-good', in_progress: 'is-info', failed: 'is-bad', cancelled: 'is-warn', not_exported: 'is-warn', deleted: 'is-muted' };

export default function AiCosts({ report: initial, filters: initialFilters, pricing: initialPricing, endpoints }: Props) {
    const { t, locale } = useAiCostText();
    const toast = useToast();
    const [report, setReport] = useState(initial);
    const [filters, setFilters] = useState<AiCostFilters>(initialFilters);
    const [pricing, setPricing] = useState(initialPricing);
    const [busy, setBusy] = useState(false);
    const [tab, setTab] = useState<'customers' | 'videos'>('customers');
    const [query, setQuery] = useState(initialFilters.q ?? '');
    const [pricesOpen, setPricesOpen] = useState(false);
    const [detail, setDetail] = useState<AiCostProject | null>(null);
    const request = useRef(0);
    const today = new Date().toISOString().slice(0, 10);
    const lang = locale === 'my' ? 'my-MM' : locale === 'th' ? 'th-TH' : 'en-US';
    const mmk = (value: number) => new Intl.NumberFormat(lang, { maximumFractionDigits: value !== 0 && Math.abs(value) < 10 ? 2 : 0 }).format(value) + ' Ks';
    const count = (value: number) => new Intl.NumberFormat(lang, { notation: value >= 100000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(value);
    const minutes = (seconds: number) => new Intl.NumberFormat(lang, { maximumFractionDigits: 1 }).format(seconds / 60) + ' min';
    const s = report.summary;

    async function load(next: AiCostFilters) {
        const turn = ++request.current;
        setFilters(next);
        if (next.range === 'custom' && (!next.from || !next.to)) return;
        setBusy(true);
        const params = new URLSearchParams(Object.entries(next).filter(([, value]) => value !== null && value !== undefined && value !== '').map(([key, value]) => [key, String(value)]));
        try {
            const data = await mediaApi<AiCostReportData>(endpoints.index + '?' + params.toString());
            if (turn === request.current) setReport(data);
            window.history.replaceState(window.history.state, '', endpoints.index + '?' + params.toString());
        } catch {
            if (turn === request.current) toast.error(t('failedLoad'));
        } finally {
            if (turn === request.current) setBusy(false);
        }
    }
    useEffect(() => {
        if ((initialFilters.q ?? '') === query) return;
        const timer = setTimeout(() => void load({ ...filters, q: query, customers_page: 1, videos_page: 1 }), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    async function openProject(id: string) {
        try {
            setDetail(await mediaApi<AiCostProject>(endpoints.project.replace('PROJECT_ID', id)));
        } catch {
            toast.error(t('failedLoad'));
        }
    }

    const maxDaily = Math.max(1, ...report.daily.map((day) => day.ai_mmk + day.storage_mmk));
    const stageByMoney = report.stages.some((stage) => stage.ai_mmk > 0);
    const stageValue = (stage: { ai_mmk: number; tokens: number }) => (stageByMoney ? stage.ai_mmk : stage.tokens);
    const maxStage = Math.max(1, ...report.stages.map(stageValue));
    const stageWord = (key: string) => t((key === 'translate' ? 'translate_stage' : key) as AiCostWord);
    const wastedShare = s.ai_mmk > 0 ? Math.round((s.wasted_mmk / s.ai_mmk) * 100) + '%' : '0%';
    const stat = (key: string, icon: IconName, label: string, value: string, hint: string, tone = 'is-iris') => <article className={'ac-kpi ' + tone} key={key}><header><span className="ac-kpi-icon"><StudioIcon name={icon} /></span><h2>{label}</h2></header><strong title={value}>{value}</strong><small>{hint}</small></article>;

    return <AppLayout area="admin" frame="preferences" adminPage="aiCosts" breadcrumbLabel={t('title')}><Head title={t('title')} /><section className="rv-page pr-page ac-page">
        <header className="rv-page-heading"><div className="rv-title"><span className="rv-title-icon"><StudioIcon name="wallet" /></span><div><h1>{t('title')}</h1><p>{t('subtitle')}</p></div></div>
            <div className="rv-heading-actions"><button type="button" className="media-button" onClick={() => setPricesOpen(true)}><StudioIcon name="settings" />{t('prices')}</button><button type="button" className="media-button primary" disabled={busy} onClick={() => void load(filters)}><StudioIcon name="refresh" className={busy ? 'accounts-refresh-spin' : ''} />{t('refresh')}</button></div></header>

        <div className="pr-filters ac-filters">
            <SelectField label={t('range')} value={filters.range} options={([['1', 'today'], ['7', 'last7'], ['30', 'last30'], ['90', 'last90'], ['custom', 'custom']] as const).map(([value, key]) => ({ value, label: t(key) }))} onChange={(value) => void load({ ...filters, range: value as AiCostFilters['range'], customers_page: 1, videos_page: 1, from: value === 'custom' ? filters.from ?? report.range.start : null, to: value === 'custom' ? filters.to ?? report.range.end : null })} />
            {filters.range === 'custom' && <div className="ac-dates"><DatePicker id="ac-from" label={t('from')} value={filters.from ?? ''} today={today} max={filters.to || today} locale={locale} onChange={(value) => void load({ ...filters, from: value })} /><DatePicker id="ac-to" label={t('to')} value={filters.to ?? ''} today={today} min={filters.from ?? undefined} max={today} locale={locale} onChange={(value) => void load({ ...filters, to: value })} /></div>}
            <span className="ac-range-label"><StudioIcon name="calendar" />{report.range.start} → {report.range.end} · {report.range.timezone}</span>
        </div>

        {(!report.pricing.configured || report.pricing.rates_missing) && <p className="ac-notice is-warn"><StudioIcon name="alert" /><span>{t('setPrices')}</span><button type="button" className="media-button" onClick={() => setPricesOpen(true)}>{t('prices')}</button></p>}
        {report.pricing.configured && !report.pricing.rates_missing && report.pricing.missing_models.length > 0 && <p className="ac-notice"><StudioIcon name="info" /><span>{t('missingModels', { models: report.pricing.missing_models.join(', ') })}</span><button type="button" className="media-button" onClick={() => setPricesOpen(true)}>{t('prices')}</button></p>}

        <div className={'ac-kpis' + (busy ? ' is-loading' : '')}>
            {stat('tokens', 'bolt', t('tokens'), count(s.total_tokens), t('inOut', { input: count(s.input_tokens), output: count(s.output_tokens) }))}
            {stat('ai', 'sparkles', t('aiCost'), mmk(s.ai_mmk), s.check_mmk > 0 ? t('calls', { count: count(s.calls) }) + ' · ' + t('checkCost', { amount: mmk(s.check_mmk) }) : t('calls', { count: count(s.calls) }), 'is-violet')}
            {stat('storage', 'database', t('storage'), formatBytes(s.storage_bytes), t('storageChange', { added: formatBytes(s.storage_added), removed: formatBytes(s.storage_removed) }), 'is-sky')}
            {stat('storageCost', 'layers', t('storageCost'), mmk(s.storage_mmk), t('perGb', { price: pricing.storage_gb_month }), 'is-teal')}
            {stat('wasted', 'alert', t('wasted'), mmk(s.wasted_mmk), t('wastedHint', { share: wastedShare }), 'is-amber')}
            {stat('profit', 'chart', t('profit'), mmk(s.profit_mmk), t('revenueHint', { revenue: mmk(s.revenue_mmk) }), s.profit_mmk < 0 ? 'is-red' : 'is-mint')}
        </div>

        <div className="ac-charts">
            <section className="rv-panel ac-panel"><h2><StudioIcon name="chart" />{t('daily')}<span className="ac-legend"><i className="is-ai" />{t('ai')}<i className="is-storage" />{t('storageShort')}</span></h2>
                <div className="ac-bars" role="img" aria-label={t('daily')}>{report.daily.map((day) => { const total = day.ai_mmk + day.storage_mmk; return <div className="ac-bar" key={day.day} title={`${day.day} · ${t('ai')} ${mmk(day.ai_mmk)} · ${t('storageShort')} ${mmk(day.storage_mmk)} · ${count(day.tokens)} tokens`}><div className="ac-bar-stack" style={{ height: Math.max(total > 0 ? 3 : 0, (total / maxDaily) * 100) + '%' }}><span className="is-storage" style={{ flex: day.storage_mmk || 0 }} /><span className="is-ai" style={{ flex: day.ai_mmk || 0 }} /></div>{report.daily.length <= 31 && <small>{day.day.slice(8)}</small>}</div>; })}</div>
            </section>
            <section className="rv-panel ac-panel"><h2><StudioIcon name="activity" />{t('byStage')}</h2>
                {report.stages.length ? <ul className="ac-stages">{report.stages.map((stage) => <li key={stage.key}><div><strong>{stageWord(stage.key)}</strong><span>{mmk(stage.ai_mmk)} · {count(stage.tokens)}</span></div><i><b style={{ width: (stageValue(stage) / maxStage) * 100 + '%' }} /></i></li>)}</ul> : <p className="ac-empty"><StudioIcon name="info" />{t('noUsage')}</p>}
            </section>
        </div>

        <section className="rv-panel ac-panel ac-tables">
            <div className="ac-table-head"><div className="rv-view-tabs" role="tablist">{(['customers', 'videos'] as const).map((key) => <button key={key} type="button" role="tab" aria-selected={tab === key} className={tab === key ? 'is-active' : ''} onClick={() => setTab(key)}>{t(key)}<span>{report[key].total}</span></button>)}</div>
                <label className="rv-search"><StudioIcon name="search" /><input value={query} maxLength={100} placeholder={t('search')} aria-label={t('search')} onChange={(event) => setQuery(event.target.value)} /></label></div>
            {tab === 'customers' ? <>{report.customers.data.length ? <div className="ac-table" role="table">
                <div className="ac-row ac-row-head is-customers" role="row"><span>{t('customer')}</span><span>{t('videosCount')}</span><span>{t('tokens')}</span><span>{t('aiCost')}</span><span>{t('storageShort')}</span><span>{t('revenue')}</span><span>{t('profit')}</span></div>
                {report.customers.data.map((row) => <div className="ac-row is-customers" role="row" key={row.id}><span className="ac-who"><strong>{row.name}</strong><small>{row.email}</small></span><span data-label={t('videosCount')}>{row.videos}</span><span data-label={t('tokens')}>{count(row.tokens)}</span><span data-label={t('aiCost')}>{mmk(row.ai_mmk)}</span><span data-label={t('storageShort')}>{formatBytes(row.storage_bytes)}<small>{mmk(row.storage_mmk)}</small></span><span data-label={t('revenue')}>{mmk(row.revenue_mmk)}</span><span data-label={t('profit')} className={row.profit_mmk < 0 ? 'is-loss' : 'is-gain'}>{mmk(row.profit_mmk)}</span></div>)}
            </div> : <p className="ac-empty"><StudioIcon name="info" />{t('noUsage')}</p>}<Pager page={report.customers.page} pages={report.customers.pages} onPage={(page) => void load({ ...filters, customers_page: page })} /></>
                : <>{report.videos.data.length ? <div className="ac-table" role="table">
                    <div className="ac-row ac-row-head is-videos" role="row"><span>{t('video')}</span><span>{t('workflow')}</span><span>{t('source')}</span><span>{t('tokens')}</span><span>{t('aiCost')}</span><span>{t('status')}</span></div>
                    {report.videos.data.map((row) => <button type="button" className="ac-row is-videos is-clickable" role="row" key={row.id} onClick={() => void openProject(row.id)} title={t('open')}><span className="ac-who"><strong>{row.title ?? '—'}</strong><small>{row.customer ? row.customer.name + ' · ' + row.customer.email : '—'}</small></span><span data-label={t('workflow')}>{row.workflow ? t(row.workflow) : '—'}</span><span data-label={t('source')}>{minutes(row.source_seconds)}</span><span data-label={t('tokens')}>{count(row.tokens)}</span><span data-label={t('aiCost')}>{mmk(row.ai_mmk)}</span><span data-label={t('status')}><em className={'ac-state ' + stateTone[row.state]}>{t(row.state)}</em></span></button>)}
                </div> : <p className="ac-empty"><StudioIcon name="info" />{t('noUsage')}</p>}<Pager page={report.videos.page} pages={report.videos.pages} onPage={(page) => void load({ ...filters, videos_page: page })} /></>}
        </section>
        <p className="ac-footnote"><StudioIcon name="info" />{t('estimate')}</p>
    </section>
        <PricesDialog open={pricesOpen} pricing={pricing} endpoint={endpoints.settings} onClose={() => setPricesOpen(false)} onSaved={(next) => { setPricing(next); setPricesOpen(false); toast.success(t('saved')); void load(filters); }} />
        <MediaDialog open={!!detail} title={t('details')} headerIcon="activity" headerDetail={detail?.project.title ?? undefined} animated className="ac-detail-dialog" onClose={() => setDetail(null)}>{detail && <div className="ac-detail">
            <div className="ac-detail-summary"><span>{detail.project.customer ? detail.project.customer.name + ' · ' + detail.project.customer.email : '—'}</span><em className={'ac-state ' + stateTone[detail.project.state]}>{t(detail.project.state)}</em><strong>{count(detail.total_tokens)} · {mmk(detail.ai_mmk)}</strong></div>
            {detail.calls.length ? <div className="ac-table ac-calls" role="table"><div className="ac-row ac-row-head is-calls" role="row"><span>{t('time')}</span><span>{t('step')}</span><span>{t('model')}</span><span>{t('input')}</span><span>{t('output')}</span><span>{t('cost')}</span></div>
                {detail.calls.map((call, index) => <div className="ac-row is-calls" role="row" key={index}><span data-label={t('time')}>{new Intl.DateTimeFormat(lang, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(call.at))}</span><span data-label={t('step')}>{stageWord(call.stage)}</span><span data-label={t('model')} className="ac-model">{call.model}</span><span data-label={t('input')}>{count(call.input_tokens)}{call.audio_input_tokens > 0 && <small>🎧 {count(call.audio_input_tokens)}</small>}</span><span data-label={t('output')}>{count(call.output_tokens)}</span><span data-label={t('cost')}>{call.ai_mmk === null ? '—' : mmk(call.ai_mmk)}</span></div>)}</div> : <p className="ac-empty"><StudioIcon name="info" />{t('noCalls')}</p>}
            <p className="ac-footnote"><StudioIcon name="clock" />{t('detailKept', { days: detail.retention_days })}</p>
            <div className="media-actions"><button type="button" className="media-button" onClick={() => setDetail(null)}>{t('close')}</button></div>
        </div>}</MediaDialog>
    </AppLayout>;
}

function Pager({ page, pages, onPage }: { page: number; pages: number; onPage: (page: number) => void }) {
    const { t } = useAiCostText();
    if (pages <= 1) return null;
    return <div className="ac-pager"><button type="button" className="accounts-action" aria-label={t('previous')} disabled={page <= 1} onClick={() => onPage(page - 1)}><StudioIcon name="back" /></button><strong>{page} / {pages}</strong><button type="button" className="accounts-action" aria-label={t('next')} disabled={page >= pages} onClick={() => onPage(page + 1)}><StudioIcon name="arrow" /></button></div>;
}

function PricesDialog({ open, pricing, endpoint, onClose, onSaved }: { open: boolean; pricing: AiCostPricing; endpoint: string; onClose: () => void; onSaved: (pricing: AiCostPricing) => void }) {
    const { t } = useAiCostText();
    const { locale } = usePage<SharedData>().props;
    const blank = (models: string[]) => Object.fromEntries(models.map((model) => [model, { input: pricing.models[model]?.input ?? '', audio_input: pricing.models[model]?.audio_input ?? '', cached: pricing.models[model]?.cached ?? '', output: pricing.models[model]?.output ?? '' }]));
    const [models, setModels] = useState<Record<string, AiModelPrices>>(() => blank(pricing.known_models));
    const [storage, setStorage] = useState(pricing.storage_gb_month);
    const [usd, setUsd] = useState(pricing.usd_mmk ?? '');
    const [thb, setThb] = useState(pricing.thb_mmk ?? '');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => { if (open) { setModels(blank(pricing.known_models)); setStorage(pricing.storage_gb_month); setUsd(pricing.usd_mmk ?? ''); setThb(pricing.thb_mmk ?? ''); setError(''); } }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
    async function submit(event: FormEvent) {
        event.preventDefault();
        setSaving(true);
        setError('');
        try {
            onSaved(await mediaApi<AiCostPricing>(endpoint, 'PUT', { revision: pricing.revision, models, storage_gb_month: storage || '0', usd_mmk: usd || null, thb_mmk: thb || null }));
        } catch (failure) {
            setError(failure instanceof MediaApiError ? Object.values(failure.fields).flat()[0] ?? failure.code : String(failure));
        } finally {
            setSaving(false);
        }
    }
    const field = (label: string, value: string, change: (value: string) => void, placeholder?: string, id?: string) => <label className="ac-price-field"><span>{label}</span><input id={id} type="number" inputMode="decimal" min="0" step="any" lang={locale} value={value} placeholder={placeholder} onChange={(event) => change(event.target.value)} /></label>;
    return <MediaDialog open={open} title={t('pricesTitle')} headerIcon="wallet" animated className="ac-prices-dialog" onClose={() => { if (!saving) onClose(); }}><form className="ac-prices" onSubmit={(event) => void submit(event)}>
        <p className="ac-footnote"><StudioIcon name="info" />{t('pricesHint')}</p>
        <section className="ac-rates">{field(t('usdRate'), usd, setUsd, '4500', 'ac-usd')}{field(t('thbRate'), thb, setThb, '130')}{field(t('storagePrice'), storage, setStorage, '0.015')}<small>{t('rateHint')}</small></section>
        {pricing.known_models.map((model) => <section className="ac-model-prices" key={model}><h3><StudioIcon name={model === pricing.speech_model ? 'mic' : 'sparkles'} />{model}{model === pricing.speech_model && <small>{t('speechModel')}</small>}</h3>
            <div>{(['input', 'audio_input', 'cached', 'output'] as const).map((key) => <span key={key}>{field(t(key === 'input' ? 'inputPrice' : key === 'audio_input' ? 'audioPrice' : key === 'cached' ? 'cachedPrice' : 'outputPrice'), models[model]?.[key] ?? '', (value) => setModels((current) => ({ ...current, [model]: { ...current[model], [key]: value } })), key === 'audio_input' || key === 'cached' ? t('sameAsInput') : 'USD / 1M')}</span>)}</div></section>)}
        {error && <p className="ac-notice is-bad" role="alert"><StudioIcon name="alert" /><span>{error}</span></p>}
        <div className="media-actions"><button type="button" className="media-button" disabled={saving} onClick={onClose}>{t('cancel')}</button><button type="submit" className="media-button primary" disabled={saving}><StudioIcon name="save" />{t(saving ? 'saving' : 'save')}</button></div>
    </form></MediaDialog>;
}
