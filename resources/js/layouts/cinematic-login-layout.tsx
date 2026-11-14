import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { BrandLogo } from '@/components/brand-logo';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { StudioIcon } from '@/components/studio-icon';
import type { SharedData } from '@/types';

export default function CinematicLoginLayout({ children, title, badge, area }: PropsWithChildren<{ title: string; badge: string; area: 'admin' | 'user' }>) {
    const { links } = usePage<SharedData>().props;

    return <main className="admin-login-scene admin-cinema-scene admin-compact-login" data-fixed-theme="dark" data-login-area={area}>
        <div className="admin-login-topbar"><div className="admin-toolbar"><LocaleSwitcher /></div></div>
        <section className="admin-login-card admin-cinema-card" aria-labelledby="sign-in-heading">
            <Link href={links.login} className="admin-login-brand" aria-label="ZATLAN"><BrandLogo /></Link>
            <span className="admin-access-badge"><StudioIcon name="shield" />{badge}</span>
            <h1 id="sign-in-heading" className="sr-only">{title}</h1>
            {children}
        </section>
    </main>;
}