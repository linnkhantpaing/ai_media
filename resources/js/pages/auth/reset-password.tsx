import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { AuthIcon } from '@/components/auth-icon';
import { EmailField, PasswordField } from '@/components/auth-fields';
import { StudioIcon } from '@/components/studio-icon';
import CinematicLoginLayout from '@/layouts/cinematic-login-layout';
import { useTranslations } from '@/lib/i18n';
import type { SharedData } from '@/types';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const { links } = usePage<SharedData>().props;
    const { t } = useTranslations();
    const { data, setData, post, processing, errors, reset } = useForm({ token, email, password: '', password_confirmation: '' });
    const form = useRef<HTMLFormElement>(null);
    const lockedEmail = email !== '' && !errors.email;
    const rules = [
        ['ruleLengthShort', data.password.length >= 12],
        ['ruleCaseShort', /[a-z]/.test(data.password) && /[A-Z]/.test(data.password)],
        ['ruleNumberShort', /\d/.test(data.password)],
        ['ruleSymbolShort', /[^A-Za-z0-9]/.test(data.password)],
        ['ruleMatchShort', data.password !== '' && data.password === data.password_confirmation],
    ] as const;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (processing) return;
        post(links.passwordStore, {
            onFinish: () => reset('password', 'password_confirmation'),
            onError: validation => requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>(validation.email ? '[name="email"]' : validation.password_confirmation && !validation.password ? '[name="password_confirmation"]' : '[name="password"]')?.focus()),
        });
    }

    return <CinematicLoginLayout area="user" icon="lock" title={t('newPasswordTitle')} badge={t('newPasswordBadge')}>
        <Head title={t('newPasswordTitle')} />
        <form ref={form} className="auth-form admin-login-form" onSubmit={submit} noValidate aria-busy={processing}>
            <EmailField label={t('email')} placeholder={t('emailPlaceholder')} value={data.email} onChange={event => setData('email', event.target.value)} error={errors.email} maxLength={254} required readOnly={lockedEmail} disabled={processing} />
            <PasswordField label={t('newPassword')} placeholder={t('passwordPlaceholder')} value={data.password} onChange={event => setData('password', event.target.value)} error={errors.password} autoComplete="new-password" maxLength={256} required autoFocus disabled={processing} />
            <PasswordField id="password_confirmation" label={t('confirmPassword')} placeholder={t('confirmPlaceholder')} value={data.password_confirmation} onChange={event => setData('password_confirmation', event.target.value)} error={errors.password_confirmation} autoComplete="new-password" maxLength={256} required disabled={processing} />
            <ul className="auth-rules" aria-label={t('passwordRules')}>
                {rules.map(([key, ok]) => <li key={key} className={ok ? 'is-ok' : ''}><StudioIcon name={ok ? 'check' : 'close'} />{t(key)}</li>)}
            </ul>
            {errors.token && <p className="auth-field-error auth-form-error" role="alert">{errors.token}</p>}
            {(errors.email || errors.token) && <Link className="auth-text-link auth-new-link" href={links.forgotPassword + (data.email ? '?email=' + encodeURIComponent(data.email) : '')}><AuthIcon name="mail" />{t('requestNewLink')}</Link>}
            <button className="auth-submit admin-submit" type="submit" disabled={processing}>
                {processing ? <><span className="auth-spinner" aria-hidden="true" />{t('updatingPassword')}</> : <>{t('updatePassword')}<AuthIcon name="arrow-right" /></>}
            </button>
        </form>
        <Link className="auth-back-link" href={links.login}><AuthIcon name="arrow-left" />{t('backToLogin')}</Link>
    </CinematicLoginLayout>;
}
