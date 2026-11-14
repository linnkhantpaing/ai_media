import { Link, usePage } from '@inertiajs/react';
import { StudioIcon } from '@/components/studio-icon';
import { adminNavigation } from '@/lib/admin-navigation';
import { useAdminTranslations } from '@/lib/admin-i18n';
import type { SharedData } from '@/types';

export function AdminSidebarNavigation({ onNavigate, mobile = false, activePage = 'dashboard' }: { onNavigate: () => void; mobile?: boolean; activePage?: string }) {
    const { adminLinks, locale } = usePage<SharedData>().props;
    const { t } = useAdminTranslations();

    return <nav className="studio-navigation" aria-label={t('navigation')} data-admin-navigation>
        <div className="studio-nav-groups">{adminNavigation.map(group => {
            const headingId = (mobile ? 'mobile-admin-' : 'desktop-admin-') + group.key;
            return <section className="studio-nav-group" key={group.key} aria-labelledby={headingId}>
                <h2 id={headingId} className="studio-group-title"><StudioIcon name={group.icon} /><span title={group.label[locale]}>{group.label[locale]}</span><i className="studio-group-accent" aria-hidden="true" /></h2>
                <div className="studio-submenu">{group.items.map(item => adminLinks?.[item.key]
                    ? <Link key={item.key} href={adminLinks![item.key]} aria-current={item.key === activePage ? 'page' : undefined} className={'studio-nav-link' + (item.key === activePage ? ' is-active' : '')} aria-label={item.label[locale]} title={item.label[locale]} onClick={onNavigate}><StudioIcon name={item.icon} /><span className="studio-nav-text">{item.label[locale]}</span></Link>
                    : <button key={item.key} type="button" className="studio-nav-link" aria-label={item.label[locale]} aria-disabled="true" title={item.label[locale] + ' — ' + t('menuPlanned')} data-menu={item.key}><StudioIcon name={item.icon} /><span className="studio-nav-text">{item.label[locale]}</span></button>
                )}</div>
            </section>;
        })}</div>
    </nav>;
}