import { useState } from 'react';
import type { InputHTMLAttributes } from 'react';
import { useTranslations } from '@/lib/i18n';
import { AuthIcon } from './auth-icon';

type FieldProps = InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string };

export function EmailField({ label, error, id = 'email', ...props }: FieldProps) {
    return (
        <div className="auth-field">
            <label htmlFor={id}>{label}</label>
            <div className={'auth-input-wrap' + (error ? ' has-error' : '')}>
                <AuthIcon name="mail" />
                <input id={id} type="email" name="email" autoComplete="username" autoCapitalize="none" spellCheck={false} aria-invalid={Boolean(error)} aria-describedby={error ? id + '-error' : undefined} {...props} />
            </div>
            {error && <p className="auth-field-error" id={id + '-error'} role="alert">{error}</p>}
        </div>
    );
}

export function PasswordField({ label, error, id = 'password', ...props }: FieldProps) {
    const [visible, setVisible] = useState(false);
    const { t } = useTranslations();
    return (
        <div className="auth-field">
            <label htmlFor={id}>{label}</label>
            <div className={'auth-input-wrap' + (error ? ' has-error' : '')}>
                <AuthIcon name="lock" />
                <input id={id} name={id} type={visible ? 'text' : 'password'} aria-invalid={Boolean(error)} aria-describedby={error ? id + '-error' : undefined} {...props} />
                <button className="password-visibility" type="button" aria-label={t(visible ? 'hidePassword' : 'showPassword')} aria-pressed={visible} onClick={() => setVisible(!visible)}>
                    <AuthIcon name={visible ? 'eye-off' : 'eye'} />
                </button>
            </div>
            {error && <p className="auth-field-error" id={id + '-error'} role="alert">{error}</p>}
        </div>
    );
}
