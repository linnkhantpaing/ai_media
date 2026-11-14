import { cloneElement, useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type ReactElement } from 'react';
import { createPortal } from 'react-dom';

export function ActionTooltip({ label, children, visible = false }: { label: string; children: ReactElement<{ 'aria-describedby'?: string }>; visible?: boolean }) {
    const id = useId();
    const anchor = useRef<HTMLSpanElement>(null);
    const bubble = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const shown = open || visible;
    const [position, setPosition] = useState({ left: 0, top: 0, arrow: 0, placement: 'top' });

    useLayoutEffect(() => {
        if (!shown || !anchor.current || !bubble.current?.isConnected) return;
        const element = bubble.current;
        if (element.showPopover && !element.matches(':popover-open')) element.showPopover();
        const trigger = anchor.current.getBoundingClientRect();
        const tooltip = element.getBoundingClientRect();
        const margin = 10;
        const gap = 10;
        const left = Math.max(margin, Math.min(trigger.left + trigger.width / 2 - tooltip.width / 2, window.innerWidth - tooltip.width - margin));
        const above = trigger.top >= tooltip.height + gap + margin;
        const top = above ? trigger.top - tooltip.height - gap : Math.min(trigger.bottom + gap, window.innerHeight - tooltip.height - margin);
        setPosition({ left, top, arrow: Math.max(10, Math.min(trigger.left + trigger.width / 2 - left, tooltip.width - 10)), placement: above ? 'top' : 'bottom' });
    }, [shown, label]);

    useEffect(() => {
        if (!shown) return;
        const close = () => setOpen(false);
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);
        return () => {
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
        };
    }, [shown]);

    return <span ref={anchor} className="action-tooltip-anchor"
        onPointerEnter={event => { if (event.pointerType !== 'touch') setOpen(true); }}
        onPointerLeave={() => { if (!anchor.current?.contains(document.activeElement)) setOpen(false); }}
        onFocusCapture={() => setOpen(true)}
        onBlurCapture={event => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false); }}
        onClick={() => setOpen(false)}
        onKeyDown={event => { if (event.key === 'Escape' && open) { event.stopPropagation(); setOpen(false); } }}>
        {cloneElement(children, { 'aria-describedby': shown ? id : undefined })}
        {shown && createPortal(<div ref={bubble} id={id} role="tooltip" popover="manual" className="action-tooltip-bubble" data-placement={position.placement}
            style={{ left: position.left, top: position.top, '--tooltip-arrow': position.arrow + 'px' } as CSSProperties}>
            {label}<i className="action-tooltip-arrow" aria-hidden="true" />
        </div>, document.body)}
    </span>;
}