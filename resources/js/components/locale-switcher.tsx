import { router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { useTranslations } from '@/lib/i18n';
import type { Locale, SharedData } from '@/types';
import { StudioIcon } from './studio-icon';

const languages: { value: Locale; label: string; flag: string }[] = [
    { value: 'my', label: 'မြန်မာ', flag: '/images/flags/mm.svg' },
    { value: 'en', label: 'English', flag: '/images/flags/gb.svg' },
    { value: 'th', label: 'ไทย', flag: '/images/flags/th.svg' },
];

export function LocaleSwitcher({iconOnly=false}:{iconOnly?:boolean}) {
    const { locale, links } = usePage<SharedData>().props;
    const { t } = useTranslations();
    const [busy, setBusy] = useState(false);
    const [open, setOpen] = useState(false);
    const [focused, setFocused] = useState(0);
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const options = useRef<(HTMLButtonElement | null)[]>([]);
    const restoreAfterChange = useRef(false);
    const id = useId();
    const selected = languages.findIndex((language) => language.value === locale);
    const current = languages[selected] ?? languages[0];

    useEffect(() => { document.documentElement.lang = locale; }, [locale]);
    useEffect(() => {
        if (busy || !restoreAfterChange.current) return;
        restoreAfterChange.current = false;
        if (document.activeElement === document.body || root.current?.contains(document.activeElement)) {
            trigger.current?.focus({ preventScroll: true });
        }
    }, [busy]);
    useEffect(() => {
        if (!open) return;
        const focus = requestAnimationFrame(() => options.current[focused]?.focus({ preventScroll: true }));
        const outside = (event: PointerEvent) => {
            if (!root.current?.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('pointerdown', outside);
        return () => {
            cancelAnimationFrame(focus);
            document.removeEventListener('pointerdown', outside);
        };
    }, [open, focused]);

    function show(index = selected) {
        setFocused(Math.max(0, index));
        setOpen(true);
    }
    function close(restoreFocus = false) {
        setOpen(false);
        if (restoreFocus) trigger.current?.focus({ preventScroll: true });
    }
    function change(next: Locale) {
        close(true);
        if (next === locale || busy) return;
        restoreAfterChange.current = true;
        setBusy(true);
        router.post(links.locale, { locale: next, return_to: window.location.pathname + window.location.search }, {
            preserveState: true,
            preserveScroll: true,
            only: ['locale'],
            onFinish: () => setBusy(false),
        });
    }
    function onOptionKey(event: KeyboardEvent<HTMLDivElement>) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            change(languages[focused].value);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setFocused((value) => (value + (event.key === 'ArrowDown' ? 1 : -1) + languages.length) % languages.length);
        } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            setFocused(event.key === 'Home' ? 0 : languages.length - 1);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        } else if (event.key === 'Tab') {
            close();
        } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && event.key !== ' ') {
            const index = languages.findIndex((language) => language.label.toLocaleLowerCase().startsWith(event.key.toLocaleLowerCase()) || language.value.startsWith(event.key.toLowerCase()));
            if (index !== -1) {
                event.preventDefault();
                setFocused(index);
            }
        }
    }

    return <div ref={root} className={'locale-switcher'+(iconOnly?' locale-icon-only':'')} data-open={open} aria-busy={busy} onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) close();
    }}>
        <button ref={trigger} id={id + '-trigger'} type="button" className="locale-trigger" aria-haspopup="listbox" aria-expanded={open} aria-controls={id + '-options'} aria-label={t('language') + ': ' + current.label} disabled={busy} onClick={() => open ? close() : show()} onKeyDown={(event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                show(selected);
            } else if (event.key === 'Escape') {
                event.stopPropagation();
                close(true);
            }
        }}>
            <img className="locale-flag" src={current.flag} width={24} height={18} alt="" aria-hidden="true" draggable={false} /><span className="locale-name" lang={current.value}>{current.label}</span>
            {busy ? <span className="locale-spinner" aria-hidden="true" /> : <StudioIcon className="locale-chevron" name="chevron" />}
        </button>
        {open && <div className="locale-menu" id={id + '-options'} role="listbox" aria-label={t('language')} onKeyDown={onOptionKey}>
            <div className="locale-menu-heading" aria-hidden="true">{t('language')}</div>
            {languages.map((language, index) => <button key={language.value} ref={(element) => { options.current[index] = element; }} type="button" className="locale-option" role="option" aria-selected={locale === language.value} tabIndex={focused === index ? 0 : -1} onFocus={() => setFocused(index)} onClick={() => change(language.value)}>
                <img className="locale-flag" src={language.flag} width={28} height={21} alt="" aria-hidden="true" draggable={false} />
                <span className="locale-name" lang={language.value}>{language.label}</span>
                {locale === language.value && <StudioIcon className="locale-selected" name="check" />}
            </button>)}
        </div>}
    </div>;
}