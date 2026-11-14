import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { StudioIcon } from '@/components/studio-icon';

const messages = {
    en: { title: 'Please wait a moment', detail: 'Getting everything ready for you' },
    my: { title: 'ခဏစောင့်ပေးပါ', detail: 'သင့်အတွက် ပြင်ဆင်ပေးနေပါသည်' },
    th: { title: 'กรุณารอสักครู่', detail: 'กำลังเตรียมทุกอย่างให้คุณ' },
};

export function LoadingOverlay({ active, message }: { active: boolean; message?: string }) {
    const [visible, setVisible] = useState(false);
    const [locale, setLocale] = useState(document.documentElement.lang);
    const dialog = useRef<HTMLDialogElement>(null);
    const text = messages[locale as keyof typeof messages] ?? messages.en;
    useEffect(() => {
        const observer = new MutationObserver(() => setLocale(document.documentElement.lang));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });
        return () => observer.disconnect();
    }, []);
    useEffect(() => {
        if (!active) { setVisible(false); return; }
        const timer = window.setTimeout(() => setVisible(true), 120);
        return () => window.clearTimeout(timer);
    }, [active]);
    useLayoutEffect(() => {
        if (!active || !visible || !dialog.current) return;
        const element = dialog.current;
        const previousOverflow = document.body.style.overflow;
        const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        document.body.style.overflow = 'hidden';
        element.showModal();
        element.focus({ preventScroll: true });
        return () => {
            if (element.open) element.close();
            document.body.style.overflow = previousOverflow;
            requestAnimationFrame(() => {
                if (document.querySelector('.app-loading-dialog[open]')) return;
                const modal = [...document.querySelectorAll<HTMLDialogElement>('dialog[open]')].at(-1);
                if (previousFocus?.isConnected && (!modal || modal.contains(previousFocus))) {
                    previousFocus.focus({ preventScroll: true });
                } else {
                    modal?.querySelector<HTMLElement>('button:not(:disabled),a[href],input:not(:disabled),[tabindex="0"]')?.focus({ preventScroll: true });
                }
            });
        };
    }, [active, visible]);
    if (!active || !visible) return null;
    return <dialog ref={dialog} className="app-loading-dialog" tabIndex={-1} aria-label={text.title} onCancel={event => event.preventDefault()} onKeyDown={event => { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); } }}>
        <div className="app-loading-indicator" role="status" aria-live="polite" aria-atomic="true">
            <div className="app-loading-art" aria-hidden="true"><span className="app-loading-halo" /><span className="app-loading-orbit" /><span className="app-loading-orbit is-inner" /><span className="app-loading-symbol"><StudioIcon name="play" /></span><span className="app-loading-spark" /></div>
            <span className="sr-only">{message || text.title}</span>
        </div>
    </dialog>;
}