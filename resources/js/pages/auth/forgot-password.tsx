import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AuthIcon } from '@/components/auth-icon';
import { EmailField } from '@/components/auth-fields';
import AuthLayout from '@/layouts/auth-layout';
import { useTranslations } from '@/lib/i18n';
import type { SharedData } from '@/types';

export default function ForgotPassword({ status }: { status?: 'reset_link_sent' | null }) {
    const { links } = usePage<SharedData>().props;
    const { t } = useTranslations();
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post(links.passwordEmail);
    }

    return (
        <AuthLayout title={t('resetTitle')}>
            <Head title={t('resetTitle')} />
            <p className="auth-description">{t('resetDescription')}</p>
            {status === 'reset_link_sent' && <p className="auth-notice" role="status">{t('resetSent')}</p>}
            <form className="auth-form" onSubmit={submit} noValidate>
                <EmailField label={t('email')} placeholder={t('emailPlaceholder')} value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} maxLength={254} autoFocus required />
                <button className="auth-submit" disabled={processing} type="submit">
                    {processing ? <span className="auth-spinner" aria-hidden="true" /> : null}
                    {t(processing ? 'sendingLink' : 'sendResetLink')}
                    {!processing && <AuthIcon name="arrow-right" />}
                </button>
            </form>
            <Link className="auth-back-link" href={links.login}><AuthIcon name="arrow-left" />{t('backToLogin')}</Link>
        </AuthLayout>
    );
}
