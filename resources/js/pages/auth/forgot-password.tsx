import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { AuthIcon } from '@/components/auth-icon';
import { EmailField } from '@/components/auth-fields';
import CinematicLoginLayout from '@/layouts/cinematic-login-layout';
import { useTranslations } from '@/lib/i18n';
import type { SharedData } from '@/types';

export default function ForgotPassword({ status }: { status?: 'reset_link_sent' | null }) {
    const { links } = usePage<SharedData>().props;
    const { t } = useTranslations();
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    const form = useRef<HTMLFormElement>(null);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (processing) return;
        post(links.passwordEmail, { onError: () => requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>('[name="email"]')?.focus()) });
    }

    return <CinematicLoginLayout area="user" icon="key" title={t('resetTitle')} badge={t('forgotBadge')}>
        <Head title={t('resetTitle')} />
        {status === 'reset_link_sent' && <p className="auth-notice" role="status">{t('resetSent')}</p>}
        <form ref={form} className="auth-form admin-login-form" onSubmit={submit} noValidate aria-busy={processing}>
            <EmailField label={t('email')} placeholder={t('emailPlaceholder')} value={data.email} onChange={event => setData('email', event.target.value)} error={errors.email} maxLength={254} required autoFocus disabled={processing} />
            <button className="auth-submit admin-submit" type="submit" disabled={processing}>
                {processing ? <><span className="auth-spinner" aria-hidden="true" />{t('sendingLink')}</> : <>{t('sendResetLink')}<AuthIcon name="arrow-right" /></>}
            </button>
        </form>
        <Link className="auth-back-link" href={links.login}><AuthIcon name="arrow-left" />{t('backToLogin')}</Link>
    </CinematicLoginLayout>;
}
