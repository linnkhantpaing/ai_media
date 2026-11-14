import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useTranslations } from '@/lib/i18n';
import { AuthIcon } from '@/components/auth-icon';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { ThemeToggle } from '@/components/theme-toggle';
import { BrandLogo } from '@/components/brand-logo';

export default function AuthLayout({ children, title }: PropsWithChildren<{ title: string }>) {
    const { t } = useTranslations();

    return (
        <main className="auth-scene" id="auth-main">
            <div className="auth-ribbon auth-ribbon-top" aria-hidden="true" />
            <div className="auth-ribbon auth-ribbon-bottom" aria-hidden="true" />
            <div className="auth-topbar"><LocaleSwitcher /><ThemeToggle /></div>
            <div className="auth-container">
                <section className="auth-card" aria-labelledby="auth-heading">
                    <Link href="/login" className="auth-brand" aria-label="ZATLAN Movie Media">
                        <BrandLogo />
                    </Link>
                    <h1 id="auth-heading">{title}</h1>
                    <span className="auth-heading-accent" aria-hidden="true" />
                    {children}
                </section>
                <p className="auth-security-note"><AuthIcon name="shield" />{t('privateWorkspace')}</p>
            </div>
        </main>
    );
}
