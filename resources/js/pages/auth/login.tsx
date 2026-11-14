import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { AuthIcon } from '@/components/auth-icon';
import { EmailField, PasswordField } from '@/components/auth-fields';
import CinematicLoginLayout from '@/layouts/cinematic-login-layout';
import { useTranslations } from '@/lib/i18n';
import type { SharedData } from '@/types';

export default function Login({ status }: { status?: 'password_updated' | null }) {
    const { links } = usePage<SharedData>().props;
    const { t } = useTranslations();
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

    return <CinematicLoginLayout area="user" title={t('signInTitle')} badge={t('userAccess')}>
        <Head title={t('signIn')} />
        {status === 'password_updated' && <p className="auth-notice" role="status">{t('passwordUpdated')}</p>}
        <form ref={form} className="auth-form admin-login-form" onSubmit={submit} noValidate aria-busy={processing}>
            <EmailField label={t('email')} placeholder={t('emailPlaceholder')} value={data.email} onChange={event => setData('email', event.target.value)} error={errors.email} maxLength={254} required autoFocus disabled={processing} />
            <PasswordField label={t('password')} placeholder={t('passwordPlaceholder')} value={data.password} onChange={event => setData('password', event.target.value)} error={errors.password} autoComplete="current-password" maxLength={256} required disabled={processing} />
            <div className="admin-login-options">
                <label className="remember-option"><input type="checkbox" name="remember" checked={data.remember} onChange={event => setData('remember', event.target.checked)} disabled={processing} /><span>{t('rememberMe')}</span></label>
                <Link className="auth-text-link" href={links.forgotPassword}>{t('forgotPassword')}</Link>
            </div>
            <button className="auth-submit admin-submit" type="submit" disabled={processing}>
                {processing ? <><span className="auth-spinner" aria-hidden="true" />{t('signingIn')}</> : <>{t('signIn')}<AuthIcon name="arrow-right" /></>}
            </button>
        </form>
    </CinematicLoginLayout>;
}