import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type PropsWithChildren } from 'react';
import { createPortal } from 'react-dom';
import { StudioIcon } from '@/components/studio-icon';

type ToastTone = 'success' | 'error';
type ToastEntry = { id: number; tone: ToastTone; message: string; title: string; closeLabel: string };
type ToastContextValue = { success: (message: string) => void; error: (message: string) => void };
const ToastContext = createContext<ToastContextValue | null>(null);
const labels = {
 en: { success: 'Success', error: 'Something went wrong', close: 'Dismiss notification' },
 my: { success: 'အောင်မြင်ပါပြီ', error: 'အဆင်မပြေပါ', close: 'အသိပေးချက် ပိတ်ရန်' },
 th: { success: 'สำเร็จ', error: 'เกิดข้อผิดพลาด', close: 'ปิดการแจ้งเตือน' },
};

function ToastItem({ entry, onRemove }: { entry: ToastEntry; onRemove: (id: number) => void }) {
 const [leaving, setLeaving] = useState(false);
 useEffect(() => { const timer = setTimeout(() => setLeaving(true), 3000); return () => clearTimeout(timer); }, []);
 useEffect(() => { if (!leaving) return; const timer = setTimeout(() => onRemove(entry.id), 220); return () => clearTimeout(timer); }, [leaving, entry.id, onRemove]);
 return <div className={'app-toast is-' + entry.tone + (leaving ? ' is-leaving' : '')} role={entry.tone === 'error' ? 'alert' : 'status'} aria-atomic="true">
  <span className="app-toast-icon"><StudioIcon name={entry.tone === 'success' ? 'check' : 'alert'}/></span>
  <div className="app-toast-content"><strong>{entry.title}</strong><p>{entry.message}</p></div>
  <button type="button" className="app-toast-close" aria-label={entry.closeLabel} onClick={() => setLeaving(true)}><StudioIcon name="close"/></button>
  <span className="app-toast-progress" aria-hidden="true"/>
 </div>;
}

export function ToastProvider({ children }: PropsWithChildren) {
 const [entries, setEntries] = useState<ToastEntry[]>([]);
 const [portalHost, setPortalHost] = useState<HTMLElement>(document.body);
 const nextId = useRef(0), viewport = useRef<HTMLDivElement>(null);
 useEffect(() => {
  const updateHost = () => setPortalHost(Array.from(document.querySelectorAll<HTMLDialogElement>('.media-dialog[open]')).at(-1) ?? document.body);
  const observer = new MutationObserver(updateHost);
  observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['open'] });
  updateHost();
  return () => observer.disconnect();
 }, []);
 const notify = useCallback((tone: ToastTone, message: string) => {
  const language = document.documentElement.lang.split('-')[0];
  const text = labels[language as keyof typeof labels] ?? labels.en;
  const entry = { id: ++nextId.current, tone, message, title: text[tone], closeLabel: text.close };
  setEntries(current => [...current.slice(-2), entry]);
 }, []);
 const remove = useCallback((id: number) => setEntries(current => current.filter(entry => entry.id !== id)), []);
 const value = useMemo(() => ({ success: (message: string) => notify('success', message), error: (message: string) => notify('error', message) }), [notify]);
 useEffect(() => {
  const element = viewport.current;
  if (entries.length && element?.isConnected && element.showPopover && !element.matches(':popover-open')) element.showPopover();
 }, [entries.length, portalHost]);
 return <ToastContext value={value}>{children}{entries.length > 0 && createPortal(<div ref={viewport} className="app-toast-viewport" popover="manual">{entries.map(entry => <ToastItem key={entry.id} entry={entry} onRemove={remove}/>)}</div>, portalHost.isConnected ? portalHost : document.body)}</ToastContext>;
}

export function useToast() {
 const toast = useContext(ToastContext);
 if (!toast) throw new Error('useToast requires ToastProvider');
 return toast;
}
