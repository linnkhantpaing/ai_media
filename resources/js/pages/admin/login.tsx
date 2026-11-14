import { Head, useForm, usePage } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { EmailField, PasswordField } from '@/components/auth-fields';
import { StudioIcon } from '@/components/studio-icon';
import CinematicLoginLayout from '@/layouts/cinematic-login-layout';
import { useAdminTranslations } from '@/lib/admin-i18n';
import { useTranslations } from '@/lib/i18n';
import type { SharedData } from '@/types';

export default function AdminLogin() {
    const { links } = usePage<SharedData>().props;
    const { t } = useAdminTranslations();
    const { t: common } = useTranslations();
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', password: '', remember: false });
    const form = useRef<HTMLFormElement>(null);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (processing) return;
        post(links.login, {
            onFinish: () => reset('password'),
            onError: validation => requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>(validation.email ? '[name="email"]' : '[name="password"]')?.focus()),
        });
    }

    return <CinematicLoginLayout area="admin" title={t('signIn')} badge={t('access')}>
        <Head title={t('signIn')}><meta name="robots" content="noindex, nofollow" /></Head>
            <form ref={form} className="auth-form admin-login-form" onSubmit={submit} noValidate aria-busy={processing}>
                <EmailField label={t('email')} placeholder={t('emailPlaceholder')} value={data.email} onChange={event => setData('email', event.target.value)} error={errors.email} maxLength={254} required autoFocus disabled={processing} />
                <PasswordField label={t('password')} placeholder={t('passwordPlaceholder')} value={data.password} onChange={event => setData('password', event.target.value)} error={errors.password} autoComplete="current-password" maxLength={256} required disabled={processing} />
                <div className="admin-login-options">
                    <label className="remember-option"><input type="checkbox" name="remember" checked={data.remember} onChange={event => setData('remember', event.target.checked)} disabled={processing} /><span>{common('rememberMe')}</span></label>
                    <span className="admin-login-access-note"><StudioIcon name="shield" />{t('accessOnly')}</span>
                </div>
                <button className="auth-submit admin-submit" type="submit" disabled={processing}>{processing ? <><span className="auth-spinner" aria-hidden="true" />{t('signingIn')}</> : <>{t('submit')}<StudioIcon name="arrow" /></>}</button>
            </form>
    </CinematicLoginLayout>;
}