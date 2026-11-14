import { Link, usePage } from '@inertiajs/react';
import { useTranslations } from '@/lib/i18n';
import { isWorkflow, navigation, workflow } from '@/lib/navigation';
import type { FrameKey, SharedData } from '@/types';

export function FramePanel({ frame }: { frame: FrameKey }) {
    const { t } = useTranslations();
    const { frameLinks } = usePage<SharedData>().props;
    const category = frame === 'dashboard' ? 'welcomeBack' : navigation.find((group) =>
        group.items.some((item) => item.key === frame || item.key === 'newProject' && isWorkflow(frame)),
    )?.label ?? 'workspace';

    return <section className="studio-panel" aria-labelledby="frame-heading">
        <div className="studio-panel-heading"><p className="studio-page-category">{t(category)}</p><h1 id="frame-heading">{t(frame)}</h1></div>
        {isWorkflow(frame) && <nav className="workflow-tabs" aria-label={t('workflow')}>{workflow.map((step, index) =>
            <Link key={step.key} href={frameLinks[step.key]!} aria-current={(frame === step.key || frame === 'newProject' && index === 0) ? 'page' : undefined}><span>{String(index + 1).padStart(2, '0')}</span>{t(step.key)}</Link>
        )}</nav>}
    </section>;
}