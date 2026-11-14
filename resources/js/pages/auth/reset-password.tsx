import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AuthIcon } from '@/components/auth-icon';
import { EmailField, PasswordField } from '@/components/auth-fields';
import AuthLayout from '@/layouts/auth-layout';
import { useTranslations } from '@/lib/i18n';
import type { SharedData } from '@/types';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const { links } = usePage<SharedData>().props;
    const { t } = useTranslations();
    const { data, setData, post, processing, errors, reset } = useForm({ token, email, password: '', password_confirmation: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post(links.passwordStore, { onFinish: () => reset('password', 'password_confirmation') });
    }

    return (
        <AuthLayout title={t('newPasswordTitle')}>
            <Head title={t('newPasswordTitle')} />
            <p className="auth-description">{t('passwordPolicy')}</p>
            <form className="auth-form" onSubmit={submit} noValidate>
                <EmailField label={t('email')} value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} maxLength={254} required />
                <PasswordField label={t('newPassword')} placeholder={t('passwordPlaceholder')} value={data.password} onChange={(event) => setData('password', event.target.value)} error={errors.password} autoComplete="new-password" required autoFocus />
                <PasswordField id="password_confirmation" label={t('confirmPassword')} placeholder={t('confirmPlaceholder')} value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} error={errors.password_confirmation} autoComplete="new-password" required />
                {errors.token && <p className="auth-field-error" role="alert">{errors.token}</p>}
                <button className="auth-submit" disabled={processing} type="submit">
                    {processing ? <span className="auth-spinner" aria-hidden="true" /> : null}
                    {t(processing ? 'updatingPassword' : 'updatePassword')}
                    {!processing && <AuthIcon name="arrow-right" />}
                </button>
            </form>
            <Link className="auth-back-link" href={links.login}><AuthIcon name="arrow-left" />{t('backToLogin')}</Link>
        </AuthLayout>
    );
}
