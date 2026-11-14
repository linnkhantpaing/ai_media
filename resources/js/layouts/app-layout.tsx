import {AdminNotificationBell,AdminNotificationsProvider} from '@/components/admin-notifications';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type PropsWithChildren } from 'react';
import { AdminSidebarNavigation } from '@/components/admin-sidebar-navigation';
import { adminNavigation } from '@/lib/admin-navigation';
import { useAdminTranslations } from '@/lib/admin-i18n';
import { LocaleSwitcher } from '@/components/locale-switcher';
import {PlatformNotice,PlatformSupport} from '@/components/platform-notice';
import { BrandLogo } from '@/components/brand-logo';
import { StudioIcon } from '@/components/studio-icon';
import { StudioBreadcrumb } from '@/components/studio-breadcrumb';
import { ThemeToggle } from '@/components/theme-toggle';
import { navigation, isWorkflow, isLibrary, workflow } from '@/lib/navigation';
import { useTranslations } from '@/lib/i18n';
import type { FrameKey, SharedData } from '@/types';

const desktopQuery = '(min-width: 1024px)';

function useDialog(open: boolean) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) dialog.showModal();
        if (!open && dialog.open) dialog.close();
        if (!open) return;
        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => { document.body.style.overflow = previous; };
    }, [open]);
    return ref;
}

function Sidebar({ frame, mobile = false, adminArea = false, adminPage = 'dashboard', onNavigate }: {
    frame: FrameKey;
    mobile?: boolean;
    adminArea?: boolean;
    adminPage?: string;
    onNavigate: () => void;
}) {
    const { auth, links, frameLinks } = usePage<SharedData>().props;
    const { t } = useTranslations();
    const { t: adminText } = useAdminTranslations();
    const footerTitle = adminArea ? adminText('phase') : t('phaseOne');
    const selected = (key: FrameKey) => key === frame || key === 'newProject' && isWorkflow(frame) || key === 'allProjects' && isLibrary(frame);
    const pageUrl = usePage<SharedData>().url;
    function navigationHref(key: FrameKey): string {
        const destination = frameLinks[key]!;
        if (!pageUrl.split('?')[0].endsWith('/projects') || !destination.split('?')[0].endsWith('/projects')) return destination;
        const query = new URLSearchParams(pageUrl.split('?')[1]);
        const target = new URLSearchParams(destination.split('?')[1]);
        query.delete('page');
        query.delete('status');
        if (target.has('status')) query.set('status', target.get('status')!);
        return destination.split('?')[0] + (query.size ? '?' + query.toString() : '');
    }

    return <>
        <div className="studio-sidebar-glow" aria-hidden="true" />
        <div className="studio-brand-row">
            <Link href={links.workspace} className="studio-brand" aria-label="ZATLAN Movie Media" onClick={onNavigate}>
                <span className="studio-brand-logo"><BrandLogo /></span>
            </Link>
            {mobile && <button type="button" className="icon-button drawer-close" aria-label={t('closeNavigation')} onClick={onNavigate}><StudioIcon name="close" /></button>}
        </div>
        {adminArea ? <AdminSidebarNavigation activePage={adminPage} mobile={mobile} onNavigate={onNavigate} /> : <nav className="studio-navigation" aria-label={t('mainNavigation')}>
            <div className="studio-nav-groups">
                {navigation.map((group) => {
                    const items = group.items.filter((item) => frameLinks[item.key]);
                    if (!items.length) return null;
                    const id = (mobile ? 'mobile-' : 'desktop-') + group.label;
                    return <section className="studio-nav-group" key={group.label} aria-labelledby={id}>
                        <h2 id={id} className="studio-group-title">
                            <StudioIcon name={group.icon} /><span>{t(group.label)}</span><i className="studio-group-accent" aria-hidden="true" />
                        </h2>
                        <div className="studio-submenu">
                            {items.map((item) => <Link key={item.key} href={navigationHref(item.key)} className={'studio-nav-link' + (selected(item.key) ? ' is-active' : '')} aria-current={selected(item.key) ? 'page' : undefined} aria-label={t(item.key)} title={t(item.key)} onClick={onNavigate}>
                                <StudioIcon name={item.icon} /><span className="studio-nav-text">{t(item.key)}</span>
                            </Link>)}
                        </div>
                    </section>;
                })}
            </div>
        </nav>}
        <div className="studio-sidebar-footer">
            <div className="studio-footer-row" title={footerTitle}>
                <span className="studio-footer-icon"><StudioIcon name="shield" /></span>
                <div><strong>{footerTitle}</strong><small>{t(auth.user?.is_admin ? 'adminAccess' : 'memberAccess')}</small></div>
                <span className="studio-version">{adminArea ? 'V2' : 'V1'}</span>
            </div>
        </div>
    </>;
}


export default function AppLayout({ children, frame, breadcrumbLabel, area = 'user', adminPage = 'dashboard', onPrimaryAction, primaryActionLabel }: PropsWithChildren<{ frame: FrameKey; breadcrumbLabel?: string; area?: 'user' | 'admin'; adminPage?: string; onPrimaryAction?: () => void; primaryActionLabel?: string }>) {
    const { auth, links, frameLinks, locale, adminLinks } = usePage<SharedData>().props;
    const { t } = useTranslations();
    const { t: adminText } = useAdminTranslations();
    const adminArea = area === 'admin';
    const sidebarStorage = adminArea ? 'ai-media.admin-sidebar' : 'ai-media.sidebar';
    const [collapsed, setCollapsed] = useState(() => {
        try { return localStorage.getItem(sidebarStorage) === 'collapsed'; } catch { return false; }
    });
    const [drawer, setDrawer] = useState(false);
    const [search, setSearch] = useState(false);
    const [query, setQuery] = useState('');
    const drawerRef = useDialog(drawer);
    const searchRef = useDialog(search);
    const headerRef = useRef<HTMLElement>(null);

    useEffect(() => {
        try { localStorage.setItem(sidebarStorage, collapsed ? 'collapsed' : 'expanded'); } catch { /* Storage is optional. */ }
    }, [collapsed, sidebarStorage]);
    useEffect(() => {
        setDrawer(false);
        setSearch(false);
        setQuery('');
        headerRef.current?.querySelectorAll('details[open]').forEach((item) => item.removeAttribute('open'));
    }, [frame, adminPage]);
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setDrawer(false);
                setSearch((value) => !value);
            }
            if (event.key === 'Escape') {
                setDrawer(false);
                setSearch(false);
                headerRef.current?.querySelectorAll('details[open]').forEach((item) => item.removeAttribute('open'));
            }
        };
        const closeDetails = (event: PointerEvent) => {
            headerRef.current?.querySelectorAll('details[open]').forEach((item) => {
                if (!item.contains(event.target as Node)) item.removeAttribute('open');
            });
        };
        const resize = () => { if (window.matchMedia(desktopQuery).matches) setDrawer(false); };
        window.addEventListener('keydown', onKey);
        window.addEventListener('pointerdown', closeDetails);
        window.addEventListener('resize', resize);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('pointerdown', closeDetails);
            window.removeEventListener('resize', resize);
        };
    }, []);

    function toggleNavigation() {
        if (window.matchMedia(desktopQuery).matches) setCollapsed((value) => !value);
        else setDrawer(true);
    }
    const destinations = adminArea
        ? adminNavigation.flatMap(group => group.items).map(item => ({ ...item, label: item.label[locale], href: adminLinks?.[item.key] }))
        : [...navigation.flatMap(group => group.items), ...workflow].filter(item => frameLinks[item.key]).map(item => ({ ...item, label: t(item.key), href: frameLinks[item.key] }));
    const results = destinations.filter(item => item.label.toLocaleLowerCase().includes(query.toLocaleLowerCase().trim()) || item.key.toLowerCase().includes(query.toLowerCase().replaceAll(' ', '')));
    const adminGroupLabel = adminNavigation.find(group => group.items.some(item => item.key === adminPage))?.label[locale];
    const sidebarProps = { frame, adminArea, adminPage, onNavigate: () => setDrawer(false) };

    return <AdminNotificationsProvider enabled={adminArea}><div data-area={area} className={'studio-shell' + (collapsed ? ' sidebar-is-collapsed' : '')}>
        <a className="skip-link" href="#main-content">{t('skipToContent')}</a>
        <aside className="studio-sidebar" aria-label={t('navigation')}><Sidebar {...sidebarProps} /></aside>
        <dialog ref={drawerRef} className="studio-drawer" aria-label={t('navigation')} onCancel={() => setDrawer(false)} onClose={() => setDrawer(false)} onClick={(event) => { if (event.target === event.currentTarget) setDrawer(false); }}>
            <div className="studio-drawer-inner"><Sidebar mobile {...sidebarProps} /></div>
        </dialog>
        <div className="studio-main">
            <header className="studio-topbar" ref={headerRef}>
                <button type="button" className="icon-button studio-menu-toggle" aria-label={t(collapsed ? 'expandNavigation' : 'collapseNavigation')} title={t(collapsed ? 'expandNavigation' : 'collapseNavigation')} onClick={toggleNavigation}><StudioIcon name="menu" /></button>
                <button type="button" className="icon-button studio-mobile-menu" aria-label={t('openNavigation')} aria-haspopup="dialog" aria-expanded={drawer} onClick={toggleNavigation}><StudioIcon name="menu" /></button>
                <button type="button" className="studio-search-trigger" aria-label={t('searchPages')} aria-haspopup="dialog" onClick={() => setSearch(true)}><StudioIcon name="search" /><span>{t('searchPages')}</span><kbd>Ctrl K</kbd></button>
                <div className="studio-header-controls">
                    <LocaleSwitcher /><ThemeToggle compact />
                    {adminArea ? <AdminNotificationBell/> : <details className="studio-header-menu notifications-menu">
                        <summary className="icon-button" aria-label={t('notifications')} title={t('notifications')}><StudioIcon name="bell" /></summary>
                        <div className="studio-popover notifications-popover"><p className="popover-heading">{t('notifications')}</p><div className="notification-empty"><StudioIcon name="bell" /><strong>{t('noNotifications')}</strong><p>{t('notificationsDetail')}</p></div></div>
                    </details>}
                    {adminArea ? (onPrimaryAction ? <button type="button" className="studio-header-action" onClick={onPrimaryAction}><StudioIcon name="plus" /><span>{primaryActionLabel ?? adminText('users')}</span></button> : <Link href={adminLinks?.users ?? links.workspace} className="studio-header-action"><StudioIcon name="users" /><span>{adminText('users')}</span></Link>) : <Link href={frameLinks.newProject!} className="studio-header-action" title={t('newProject')}><StudioIcon name="plus" /><span>{t('newProject')}</span></Link>}
                    <details className="studio-header-menu profile-menu">
                        <summary className="studio-profile" aria-label={t('accountMenu')}>
                            <span className="studio-avatar">{auth.user?.name.trim().slice(0, 1).toUpperCase() || 'A'}</span>
                            <span className="studio-profile-label"><strong>{auth.user?.name}</strong><small>{t(auth.user?.is_admin ? 'adminAccess' : 'memberAccess')}</small></span>
                            <StudioIcon name="chevron" />
                        </summary>
                        <div className="studio-popover profile-popover"><div className="profile-identity"><strong>{auth.user?.name}</strong><span>{auth.user?.email}</span><small>{t(auth.user?.is_admin ? 'adminAccess' : 'memberAccess')}</small></div>
                            {adminArea ? <button type="button" aria-disabled="true" title={adminText('menuPlanned')}><StudioIcon name="sliders" />{t('preferences')}</button> : <Link href={frameLinks.preferences!}><StudioIcon name="sliders" />{t('preferences')}</Link>}
                            <PlatformSupport/><button type="button" onClick={() => router.post(links.logout)}><StudioIcon name="logout" />{t('signOut')}</button>
                        </div>
                    </details>
                </div>
            </header>
            <main className="studio-content" id="main-content" tabIndex={-1}><PlatformNotice adminArea={adminArea}/><StudioBreadcrumb frame={frame} currentLabel={breadcrumbLabel} groupLabel={adminArea ? adminGroupLabel : undefined} />{children}</main>
        </div>
        <dialog ref={searchRef} className="studio-search-dialog" aria-labelledby="search-heading" onCancel={() => setSearch(false)} onClose={() => setSearch(false)} onClick={(event) => { if (event.target === event.currentTarget) setSearch(false); }}>
            <div className="search-dialog-inner">
                <div className="search-input-row"><StudioIcon name="search" /><label className="sr-only" htmlFor="page-search" id="search-heading">{t('searchPages')}</label><input id="page-search" autoFocus placeholder={t('searchPages')} value={query} onChange={(event) => setQuery(event.target.value)} /><button type="button" className="icon-button" aria-label={t('close')} onClick={() => setSearch(false)}><StudioIcon name="close" /></button></div>
                <p className="search-hint">{t('searchHint')}</p>
                <div className="search-results">{results.length ? results.map(item => item.href ? <Link key={item.key} href={item.href} onClick={() => { setSearch(false); setQuery(''); }}><StudioIcon name={item.icon} /><span>{item.label}</span><span aria-hidden="true">↗</span></Link> : <button type="button" key={item.key} aria-disabled="true" title={adminText('menuPlanned')}><StudioIcon name={item.icon} /><span>{item.label}</span><small>{adminText('planned')}</small></button>) : <p className="search-empty" role="status">{t('noSearchResults')}</p>}</div>
            </div>
        </dialog>
    </div></AdminNotificationsProvider>;
}