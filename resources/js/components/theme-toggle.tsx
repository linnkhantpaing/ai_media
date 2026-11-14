import { useTheme } from '@/lib/theme';
import { useTranslations } from '@/lib/i18n';
import { StudioIcon } from './studio-icon';

export function ThemeToggle({ compact = false }: { compact?: boolean }) {
    const { theme, toggle } = useTheme();
    const { t } = useTranslations();
    const label = t(theme === 'dark' ? 'switchToLight' : 'switchToDark');
    return <button type="button" className={'theme-toggle' + (compact ? ' is-compact' : '')} role="switch" aria-checked={theme === 'dark'} aria-label={t('darkMode')} title={label} onClick={toggle}>
        <StudioIcon name={compact && theme === 'light' ? 'moon' : 'sun'} />
        {!compact && <span className="theme-track"><span><StudioIcon name={theme === 'dark' ? 'moon' : 'sun'} /></span></span>}
    </button>;
}