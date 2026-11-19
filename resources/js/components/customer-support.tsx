import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState, type CSSProperties, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { StudioIcon } from '@/components/studio-icon';
import { ActionTooltip } from '@/components/action-tooltip';
import { mediaApi, MediaApiError, formatBytes } from '@/lib/media-api';
import { useCustomerSupportText } from '@/lib/customer-support-i18n';
import type { SharedData } from '@/types';
import type { SupportMessage } from '@/types/support';
import '../../css/customer-support.css';

type Message = SupportMessage & { can_edit: boolean; edited: boolean };
type ChatPage = {
    messages: Message[];
    changes: Message[];
    has_older: boolean;
    has_newer: boolean;
    reset: boolean;
    snapshot: number;
    unread: number;
};
type ComposerAction = { kind: 'reply' | 'edit' | 'delete'; message: Message };
type MenuAnchor = { id: number; button: HTMLButtonElement };

export function CustomerSupport() {
    const { supportFeed, auth } = usePage<SharedData>().props;
    if (!supportFeed || !auth.user || auth.user.is_admin) return null;
    return <SupportChat key={auth.user.email} endpoint={supportFeed} />;
}

function SupportChat({ endpoint }: { endpoint: string }) {
    const { t, language } = useCustomerSupportText();
    const [open, setOpen] = useState(false);
    const [closing, setClosing] = useState(false);
    const [messages, setMessages] = useState<Message[]>([]);
    const [unread, setUnread] = useState(0);
    const [hasOlder, setHasOlder] = useState(false);
    const [loading, setLoading] = useState(true);
    const [olderBusy, setOlderBusy] = useState(false);
    const [newCount, setNewCount] = useState(0);
    const [awayFromBottom, setAwayFromBottom] = useState(false);
    const [highlight, setHighlight] = useState<number | null>(null);
    const [error, setError] = useState('');
    const [body, setBody] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [action, setAction] = useState<ComposerAction | null>(null);
    const [editBody, setEditBody] = useState('');
    const [working, setWorking] = useState(false);
    const [sendError, setSendError] = useState('');
    const [menu, setMenu] = useState<MenuAnchor | null>(null);
    const [menuPosition, setMenuPosition] = useState({ left: 0, top: 0 });
    const [viewport, setViewport] = useState({ height: 0, bottom: 0 });

    const trigger = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const scroller = useRef<HTMLDivElement>(null);
    const thread = useRef<HTMLDivElement>(null);
    const hasOlderRef = useRef(false);
    hasOlderRef.current = hasOlder;
    const highlightTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const input = useRef<HTMLTextAreaElement>(null);
    const picker = useRef<HTMLInputElement>(null);
    const menuElement = useRef<HTMLDivElement>(null);
    const state = useRef({ messages: [] as Message[], snapshot: 0, hasNewer: false });
    const mutation = useRef(0);
    const busy = useRef(false);
    const mounted = useRef(true);
    const nearBottom = useRef(true);
    const openRef = useRef(open);
    openRef.current = open && !closing;
    const words = useRef(t);
    words.current = t;
    const requestId = useRef<string | null>(null);
    const acknowledged = useRef(0);
    const readPending = useRef(false);
    const refresh = useRef<() => void>(() => {});
    const closeTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const olderRequest = useRef<AbortController | null>(null);
    const menuRef = useRef<MenuAnchor | null>(null);
    menuRef.current = menu;

    const scrollBottom = useCallback((smooth = false) => {
        nearBottom.current = true;
        requestAnimationFrame(() => {
            const element = scroller.current;
            if (element) element.scrollTo({ top: element.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
            setNewCount(0);
            setAwayFromBottom(false);
        });
    }, []);

    // Keep the newest message in view while pinned to the bottom, even when
    // images or fonts finish loading after the first render.
    useLayoutEffect(() => {
        const content = thread.current;
        if (!open || !content || typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(() => {
            const element = scroller.current;
            if (element && nearBottom.current) element.scrollTop = element.scrollHeight;
        });
        observer.observe(content);
        return () => observer.disconnect();
    }, [open, loading]);

    const acknowledge = useCallback(() => {
        const current = state.current;
        const through = current.messages.at(-1)?.id ?? 0;
        if (!openRef.current || current.hasNewer || !nearBottom.current || document.hidden || !document.hasFocus()
            || through <= acknowledged.current || readPending.current) return;
        readPending.current = true;
        void mediaApi<{ unread: number }>(endpoint + '/read', 'POST', { through })
            .then(result => {
                acknowledged.current = through;
                if (mounted.current) setUnread(result.unread);
            })
            .catch(() => {})
            .finally(() => { readPending.current = false; });
    }, [endpoint]);

    const dismiss = useCallback(() => {
        setMenu(null);
        olderRequest.current?.abort();
        olderRequest.current = null;
        setOlderBusy(false);
        setClosing(true);
        clearTimeout(closeTimer.current);
        closeTimer.current = setTimeout(() => {
            setOpen(false);
            setClosing(false);
            trigger.current?.focus();
        }, 160);
    }, []);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            clearTimeout(closeTimer.current);
            clearTimeout(highlightTimer.current);
            olderRequest.current?.abort();
        };
    }, []);

    useEffect(() => {
        let stopped = false;
        let pending = false;
        let again = false;
        let timer: ReturnType<typeof setTimeout>;
        const controller = new AbortController();

        async function load() {
            clearTimeout(timer);
            if (stopped) return;
            if (pending) { again = true; return; }
            if (document.hidden) {
                timer = setTimeout(() => void load(), 20000);
                return;
            }
            pending = true;
            let delay = open ? 4000 : 20000;
            try {
                if (!open) {
                    const result = await mediaApi<{ unread: number }>(endpoint + '?badge=1', 'GET', undefined, controller.signal);
                    if (!stopped) setUnread(result.unread);
                    return;
                }
                if (olderRequest.current) return;
                const version = mutation.current;
                const previous = state.current;
                const first = previous.messages[0]?.id;
                const after = previous.messages.at(-1)?.id ?? 0;
                const query = first ? '?after=' + after + '&loaded_from=' + first + '&since=' + previous.snapshot : '';
                let result = await mediaApi<ChatPage>(endpoint + query, 'GET', undefined, controller.signal);
                if (stopped) return;
                const replace = !first || result.reset;
                if (result.reset) result = await mediaApi<ChatPage>(endpoint, 'GET', undefined, controller.signal);
                if (stopped) return;
                if (version !== mutation.current) { again = true; return; }
                const merged = new Map((replace ? [] : state.current.messages).map(message => [message.id, message]));
                for (const message of [...result.messages, ...result.changes]) merged.set(message.id, message);
                const rows = [...merged.values()].sort((first, second) => first.id - second.id);
                state.current = { messages: rows, snapshot: result.snapshot, hasNewer: result.has_newer };
                setMessages(rows);
                setUnread(result.unread);
                setError('');
                setLoading(false);
                if (replace) setHasOlder(result.has_older);
                const incoming = result.messages.filter(message => message.id > after);
                if (replace || (incoming.length && nearBottom.current)) scrollBottom();
                else if (incoming.length) setNewCount(count => count + incoming.filter(message => message.sender_kind === 'admin').length);
                requestAnimationFrame(acknowledge);
                if (result.has_newer) delay = 200;
            } catch (reason) {
                if (stopped) return;
                delay = open ? 10000 : 30000;
                if (open) {
                    setLoading(false);
                    setError(words.current(reason instanceof MediaApiError && reason.code === 'session_expired' ? 'sessionExpired' : 'failed'));
                }
            } finally {
                pending = false;
                if (!stopped) {
                    timer = setTimeout(() => void load(), again ? 200 : delay);
                    again = false;
                }
            }
        }

        refresh.current = () => void load();
        const wake = () => { if (!document.hidden) void load(); };
        void load();
        window.addEventListener('focus', wake);
        document.addEventListener('visibilitychange', wake);
        return () => {
            stopped = true;
            controller.abort();
            clearTimeout(timer);
            window.removeEventListener('focus', wake);
            document.removeEventListener('visibilitychange', wake);
        };
    }, [open, endpoint, acknowledge, scrollBottom]);

    useLayoutEffect(() => {
        if (!open) return;
        const locate = () => {
            const visible = window.visualViewport;
            setViewport({ height: visible?.height || window.innerHeight, bottom: visible ? Math.max(0, window.innerHeight - visible.height - visible.offsetTop) : 0 });
        };
        locate();
        window.visualViewport?.addEventListener('resize', locate);
        window.visualViewport?.addEventListener('scroll', locate);
        window.addEventListener('resize', locate);
        const focus = requestAnimationFrame(() => {
            panel.current?.focus({ preventScroll: true });
            scrollBottom();
        });
        const escape = (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || event.defaultPrevented) return;
            if (menuRef.current) {
                menuRef.current.button.focus({ preventScroll: true });
                setMenu(null);
            } else {
                dismiss();
            }
        };
        window.addEventListener('keydown', escape);
        return () => {
            cancelAnimationFrame(focus);
            window.visualViewport?.removeEventListener('resize', locate);
            window.visualViewport?.removeEventListener('scroll', locate);
            window.removeEventListener('resize', locate);
            window.removeEventListener('keydown', escape);
        };
    }, [open, dismiss, scrollBottom]);

    useLayoutEffect(() => {
        if (!menu || !panel.current || !menuElement.current) return;
        const bounds = panel.current.getBoundingClientRect();
        const anchor = menu.button.getBoundingClientRect();
        const { offsetWidth: width, offsetHeight: height } = menuElement.current;
        const left = Math.max(8, Math.min(anchor.right - width - bounds.left, bounds.width - width - 8));
        const above = anchor.top - bounds.top - height - 4;
        const wantedTop = above > 8 ? above : anchor.bottom - bounds.top + 4;
        const top = Math.max(8, Math.min(wantedTop, bounds.height - height - 8));
        setMenuPosition({ left, top });
        menuElement.current.querySelector('button')?.focus({ preventScroll: true });

        const close = () => setMenu(null);
        const outside = (event: PointerEvent) => {
            if (!(event.target instanceof Node)) return;
            if (!menuElement.current?.contains(event.target) && !menu.button.contains(event.target)) close();
        };
        document.addEventListener('pointerdown', outside);
        window.addEventListener('resize', close);
        window.visualViewport?.addEventListener('resize', close);
        return () => {
            document.removeEventListener('pointerdown', outside);
            window.removeEventListener('resize', close);
            window.visualViewport?.removeEventListener('resize', close);
        };
    }, [menu]);

    const activeMessage = action && messages.find(message => message.id === action.message.id);
    const selectedMessage = menu && messages.find(message => message.id === menu.id);
    const editing = action?.kind === 'edit';
    const deleting = action?.kind === 'delete';
    const value = editing ? editBody : body;
    const canSubmit = Boolean(editing ? editBody.trim() : body.trim() || files.length);

    useEffect(() => {
        const element = input.current;
        if (!element || !open) return;
        element.style.height = 'auto';
        element.style.height = Math.min(94, Math.max(38, element.scrollHeight)) + 'px';
    }, [open, value, deleting]);

    function describeError(reason: unknown, fallback: 'failed' | 'sendFailed' | 'changeFailed') {
        if (reason instanceof MediaApiError) {
            if (reason.code === 'session_expired') return t('sessionExpired');
            if (reason.code === 'rate_limited') return t('rateLimited');
            if (reason.status === 422) return Object.values(reason.fields).flat()[0] || t(fallback);
        }
        return t(fallback);
    }

    /** Loads the previous page, or everything down to `until` for a reply jump, keeping the visible position. */
    async function loadOlder(until?: number): Promise<boolean> {
        const first = state.current.messages[0]?.id;
        if (!first || !hasOlderRef.current || olderRequest.current) return false;
        const controller = new AbortController();
        olderRequest.current = controller;
        setOlderBusy(true);
        try {
            const result = await mediaApi<ChatPage>(endpoint + '?before=' + first + (until ? '&until=' + until : ''), 'GET', undefined, controller.signal);
            if (controller.signal.aborted || !mounted.current) return false;
            const target = scroller.current;
            const height = target?.scrollHeight ?? 0;
            const top = target?.scrollTop ?? 0;
            const known = new Set(state.current.messages.map(message => message.id));
            const rows = [...result.messages.filter(message => !known.has(message.id)), ...state.current.messages];
            state.current = { ...state.current, messages: rows };
            hasOlderRef.current = result.has_older;
            setMessages(rows);
            setHasOlder(result.has_older);
            setError('');
            await new Promise<void>(resolve => requestAnimationFrame(() => {
                if (target) target.scrollTop = top + target.scrollHeight - height;
                resolve();
            }));
            return true;
        } catch (reason) {
            if (!controller.signal.aborted && mounted.current) setError(describeError(reason, 'failed'));
            return false;
        } finally {
            if (olderRequest.current === controller) {
                olderRequest.current = null;
                if (mounted.current) setOlderBusy(false);
            }
        }
    }

    // Messenger-style history: fetch the previous page as the reader nears the top,
    // and keep filling while the loaded messages are shorter than the viewport.
    function maybeLoadOlder() {
        const element = scroller.current;
        if (element && hasOlderRef.current && !olderRequest.current && element.scrollTop < 140) void loadOlder();
    }

    useEffect(() => {
        if (!open || loading) return;
        const frame = requestAnimationFrame(maybeLoadOlder);
        return () => cancelAnimationFrame(frame);
    });

    /** Scrolls to a quoted message, loading older history first when needed. */
    async function jumpTo(id: number) {
        const find = () => scroller.current?.querySelector<HTMLElement>('[data-message-id="' + id + '"]') ?? null;
        let element = find();
        if (!element && hasOlderRef.current && id < (state.current.messages[0]?.id ?? 0)) {
            if (olderRequest.current) { olderRequest.current.abort(); olderRequest.current = null; }
            await loadOlder(id);
            element = find();
        }
        if (!element) {
            setSendError(t('messageUnavailable'));
            return;
        }
        nearBottom.current = false;
        element.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        setHighlight(id);
        clearTimeout(highlightTimer.current);
        highlightTimer.current = setTimeout(() => setHighlight(null), 1800);
    }

    function begin(kind: ComposerAction['kind'], message: Message) {
        setMenu(null);
        setSendError('');
        if (kind !== 'edit') requestId.current = null;
        setAction({ kind, message });
        if (kind === 'edit') setEditBody(message.body);
        if (kind !== 'delete') requestAnimationFrame(() => input.current?.focus({ preventScroll: true }));
        else requestAnimationFrame(() => panel.current?.querySelector<HTMLButtonElement>('.cs-delete-cancel')?.focus({ preventScroll: true }));
    }

    function cancelAction() {
        if (action?.kind === 'reply') requestId.current = null;
        setAction(null);
        setSendError('');
        requestAnimationFrame(() => input.current?.focus({ preventScroll: true }));
    }

    function addFiles(selected: FileList | null) {
        if (!selected) return;
        const next = [...files, ...Array.from(selected)];
        if (next.length > 5 || next.some(file => !file.size || file.size > 10 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type) || !/\.(jpe?g|png|webp|pdf)$/i.test(file.name))) {
            setSendError(t('invalidFiles'));
            return;
        }
        setFiles(next);
        requestId.current = null;
        setSendError('');
    }

    async function submit(event?: FormEvent) {
        event?.preventDefault();
        if (busy.current || (!deleting && !canSubmit)) return;
        if (action && activeMessage?.deleted) {
            setSendError(t('messageUnavailable'));
            return;
        }
        busy.current = true;
        setWorking(true);
        setSendError('');
        try {
            if (action && (editing || deleting)) {
                await mediaApi(endpoint + '/messages/' + action.message.id, deleting ? 'DELETE' : 'PATCH', deleting ? undefined : { body: editBody.trim() });
                if (!mounted.current) return;
                mutation.current++;
                const rows = state.current.messages.map(message => {
                    if (message.id === action.message.id) return deleting
                        ? { ...message, body: '', deleted: true, can_edit: false, can_delete: false, reply: null, attachments: [] }
                        : { ...message, body: editBody.trim(), edited: true };
                    if (message.reply?.id === action.message.id) return {
                        ...message, reply: { ...message.reply, body: deleting ? '' : Array.from(editBody.trim()).slice(0, 180).join(''), deleted: deleting },
                    };
                    return message;
                });
                state.current = { ...state.current, messages: rows };
                setMessages(rows);
            } else {
                const payload = new FormData();
                payload.append('body', body.trim());
                payload.append('request_id', requestId.current ??= crypto.randomUUID());
                if (action?.kind === 'reply') payload.append('reply_to', String(action.message.id));
                for (const file of files) payload.append('files[]', file);
                await mediaApi(endpoint + '/messages', 'POST', payload);
                if (!mounted.current) return;
                setBody('');
                setFiles([]);
                requestId.current = null;
                scrollBottom();
            }
            setAction(null);
            refresh.current();
        } catch (reason) {
            if (mounted.current) setSendError(describeError(reason, editing || deleting ? 'changeFailed' : 'sendFailed'));
        } finally {
            busy.current = false;
            if (mounted.current) {
                setWorking(false);
                if (window.matchMedia('(min-width:641px)').matches) requestAnimationFrame(() => input.current?.focus({ preventScroll: true }));
            }
        }
    }

    const date = new Intl.DateTimeFormat(language, { month: 'short', day: 'numeric', year: 'numeric' });
    const clock = new Intl.DateTimeFormat(language, { hour: 'numeric', minute: '2-digit', hour12: true });

    return <>
        <ActionTooltip label={t('support') + (unread ? ' · ' + unread : '')}>
            <button ref={trigger} type="button" className="icon-button customer-support-trigger"
                aria-label={t('support') + (unread ? ' · ' + unread + ' ' + t('unread') : '')}
                aria-expanded={open} aria-haspopup="dialog" aria-controls="customer-support-chat"
                onClick={() => { if (open) dismiss(); else setOpen(true); }}>
                <StudioIcon name="headphones" />
                {unread > 0 && <span className="customer-support-badge">{unread > 99 ? '99+' : unread}</span>}
            </button>
        </ActionTooltip>
        {open && createPortal(
            <div ref={panel} id="customer-support-chat" role="dialog" aria-modal="false" aria-labelledby="customer-support-heading" tabIndex={-1}
                data-fixed-theme="dark" className={'customer-support-chat' + (closing ? ' is-closing' : '')}
                style={{ '--chat-height': viewport.height ? viewport.height + 'px' : '100dvh', '--chat-bottom': viewport.bottom + 'px' } as CSSProperties}>
                <header className="customer-support-header">
                    <span className="customer-support-avatar"><StudioIcon name="headphones" /><i><StudioIcon name="shield" /></i></span>
                    <div><strong id="customer-support-heading">{t('support')}<span>{t('administrator')}</span></strong><small>{t('subtitle')}</small></div>
                    <ActionTooltip label={t('close')}><button type="button" className="cs-icon" aria-label={t('close')} onClick={dismiss}><StudioIcon name="close" /></button></ActionTooltip>
                </header>
                {error && <div className="customer-support-error" role="alert"><StudioIcon name="alert" /><span>{error}</span><button type="button" onClick={() => refresh.current()}>{t('retry')}</button></div>}
                <div className="customer-support-thread-wrap">
                <div ref={scroller} className="customer-support-messages" role="log" aria-label={t('support')} aria-live="polite" aria-relevant="additions" aria-busy={loading || olderBusy}
                    onScroll={() => {
                        if (!scroller.current) return;
                        setMenu(null);
                        const { scrollHeight, scrollTop, clientHeight } = scroller.current;
                        const distance = scrollHeight - scrollTop - clientHeight;
                        nearBottom.current = distance < 55;
                        setAwayFromBottom(distance > 240);
                        if (nearBottom.current) { setNewCount(0); acknowledge(); }
                        maybeLoadOlder();
                    }}
                    onLoadCapture={() => { if (nearBottom.current && scroller.current) scroller.current.scrollTop = scroller.current.scrollHeight; }}>
                    <div ref={thread} className="customer-support-thread">
                    {loading ? <div className="customer-support-loading" role="status"><StudioIcon name="loader" className="spin" />{t('loading')}</div>
                        : !messages.length ? !error && <div className="customer-support-welcome"><span><StudioIcon name="chat" /></span><h3>{t('welcome')}</h3><p>{t('intro')}</p></div>
                            : <>
                                {olderBusy ? <div className="cs-history-loading" role="status"><span className="cs-typing" aria-hidden="true"><i /><i /><i /></span>{t('loadingOlder')}</div>
                                    : hasOlder ? <button type="button" className="cs-text-button" onClick={() => void loadOlder()}>{t('older')}</button>
                                        : <div className="cs-history-start"><span>{t('historyStart')}</span></div>}
                                {messages.map((message, index) => {
                                    const incoming = message.sender_kind === 'admin';
                                    const day = date.format(new Date(message.at));
                                    const time = clock.format(new Date(message.at));
                                    const dateBreak = index === 0 || date.format(new Date(messages[index - 1].at)) !== day;
                                    const previousMessage = messages[index - 1];
                                    const grouped = !dateBreak && previousMessage?.sender_kind === message.sender_kind;
                                    return <div key={message.id} data-message-id={message.id}>
                                        {dateBreak && <div className="customer-support-date"><span>{day}</span></div>}
                                        <article className={'customer-support-message ' + (incoming ? 'is-admin' : 'is-customer') + (message.deleted ? ' is-deleted' : '') + (highlight === message.id ? ' is-highlighted' : '')}>
                                            {incoming && <span className="customer-support-message-avatar" aria-hidden="true"><StudioIcon name="shield" /></span>}
                                            <div className="customer-support-message-content">
                                                {incoming && !grouped && <small className="customer-support-sender">{t('admin')}</small>}
                                                <div className="customer-support-bubble">
                                                    {message.deleted ? <p><StudioIcon name="ban" />{t('deleted')}</p> : <>
                                                        {message.reply && <button type="button" className="cs-quote" disabled={message.reply.deleted} title={t('jumpToOriginal')} onClick={() => void jumpTo(message.reply!.id)}>
                                                            <span dir="auto">{message.reply.deleted ? t('deleted') : message.reply.body || t('attachment')}</span>
                                                        </button>}
                                                        {message.body && <p dir="auto">{message.body}</p>}
                                                        {message.attachments.map(file => <a className="customer-support-attachment" key={file.id} href={file.url} target="_blank" rel="noopener noreferrer" aria-label={file.name}>
                                                            {file.mime.startsWith('image/') ? <img src={file.url} alt={file.name} loading="lazy" /> : <StudioIcon name="file" />}
                                                            <span><strong>{file.name}</strong><small>{formatBytes(file.bytes)}</small></span><StudioIcon name="download" />
                                                        </a>)}
                                                    </>}
                                                </div>
                                                <time dateTime={message.at} title={day + ' · ' + time + (message.edited && message.edited_at ? ' · ' + t('edited') + ' ' + clock.format(new Date(message.edited_at)) : '')}>
                                                    {time}{message.edited && <span className="cs-edited"> · {t('edited')}</span>}
                                                </time>
                                            </div>
                                            {!message.deleted && <button type="button" className="cs-message-actions" aria-label={t('actions')}
                                                aria-expanded={menu?.id === message.id} aria-controls={menu?.id === message.id ? 'cs-message-menu' : undefined}
                                                onClick={event => setMenu(menu?.id === message.id ? null : { id: message.id, button: event.currentTarget })}>
                                                <StudioIcon name="more" />
                                            </button>}
                                        </article>
                                    </div>;
                                })}
                            </>}
                    </div>
                </div>
                {(awayFromBottom || newCount > 0) && !loading && <button type="button" className="cs-jump-bottom" aria-label={t('jumpLatest') + (newCount ? ' · ' + newCount + ' ' + t('newMessages') : '')}
                    title={t('jumpLatest')} onClick={() => { scrollBottom(true); setTimeout(acknowledge, 350); }}>
                    <StudioIcon name="chevron" />
                    {newCount > 0 && <span className="cs-jump-count">{newCount > 99 ? '99+' : newCount}</span>}
                </button>}
                </div>
                <form className="customer-support-composer" onSubmit={event => void submit(event)}>
                    {sendError && <div className="customer-support-error cs-composer-error" role="alert">
                        <StudioIcon name="alert" /><span>{sendError}</span><button type="button" aria-label={t('dismiss')} onClick={() => setSendError('')}><StudioIcon name="close" /></button>
                    </div>}
                    {action && <div className={'cs-composer-action' + (deleting ? ' is-danger' : '')}>
                        <StudioIcon name={action.kind === 'reply' ? 'reply' : editing ? 'edit' : 'trash'} />
                        <div><strong>{t(action.kind === 'reply' ? 'replying' : editing ? 'editing' : 'deleteTitle')}</strong>
                            <small dir="auto">{deleting ? t('deleteBody') : activeMessage?.deleted ? t('deleted') : (activeMessage ?? action.message).body || t('attachment')}</small></div>
                        {deleting ? <button className="cs-delete-cancel" type="button" disabled={working} onClick={cancelAction}>{t('cancel')}</button>
                            : <button type="button" className="cs-icon" disabled={working} aria-label={t('cancel')} onClick={cancelAction}><StudioIcon name="close" /></button>}
                        {deleting && <button className="cs-delete-confirm" type="submit" disabled={working}>{working && <StudioIcon name="loader" className="spin" />}{t('delete')}</button>}
                    </div>}
                    {!deleting && <>
                        {!editing && files.length > 0 && <div className="customer-support-pending">{files.map((file, index) => <span key={index}>
                            <StudioIcon name={file.type === 'application/pdf' ? 'file' : 'image'} /><span>{file.name}</span>
                            <button type="button" disabled={working} aria-label={t('removeFile') + ' ' + file.name} onClick={() => {
                                setFiles(previous => previous.filter((_, position) => position !== index));
                                requestId.current = null;
                            }}><StudioIcon name="close" /></button>
                        </span>)}</div>}
                        <div className="customer-support-compose-row">
                            {!editing && <ActionTooltip label={t('attach')}>
                                <button type="button" className="cs-icon" disabled={working || files.length >= 5} aria-label={t('attach')} onClick={() => picker.current?.click()}><StudioIcon name="paperclip" /></button>
                            </ActionTooltip>}
                            <input ref={picker} type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple hidden onChange={event => { addFiles(event.target.files); event.target.value = ''; }} />
                            <textarea ref={input} aria-label={t(editing ? 'edit' : 'placeholder')} placeholder={t('placeholder')} value={value} maxLength={4000} rows={1} disabled={working}
                                onChange={event => {
                                    if (editing) setEditBody(event.target.value);
                                    else { setBody(event.target.value); requestId.current = null; }
                                }}
                                onKeyDown={event => {
                                    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing && event.keyCode !== 229) {
                                        event.preventDefault();
                                        void submit();
                                    }
                                }} />
                            <ActionTooltip label={t(editing ? 'save' : 'send')}>
                                <button type="submit" className="customer-support-send" disabled={working || !canSubmit} aria-label={t(editing ? 'save' : 'send')}>
                                    <StudioIcon name={working ? 'loader' : editing ? 'check' : 'send'} className={working ? 'spin' : ''} />
                                </button>
                            </ActionTooltip>
                        </div>
                        <div className="customer-support-composer-hint"><span>{!editing && t('fileHint')}</span><span>{value.length > 3500 && value.length + '/4000'}</span></div>
                    </>}
                </form>
                {selectedMessage && !selectedMessage.deleted && <div ref={menuElement} id="cs-message-menu" role="group" aria-label={t('actions')}
                    className="cs-message-menu" style={menuPosition} onBlur={event => {
                        if (!event.currentTarget.contains(event.relatedTarget) && event.relatedTarget !== menu?.button) setMenu(null);
                    }}>
                    <button type="button" disabled={working} onClick={() => begin('reply', selectedMessage)}><StudioIcon name="reply" />{t('reply')}</button>
                    {selectedMessage.can_edit && <button type="button" disabled={working} onClick={() => begin('edit', selectedMessage)}><StudioIcon name="edit" />{t('edit')}</button>}
                    {selectedMessage.can_delete && <button type="button" className="is-danger" disabled={working} onClick={() => begin('delete', selectedMessage)}><StudioIcon name="trash" />{t('delete')}</button>}
                </div>}
            </div>, document.body,
        )}
    </>;
}