import { Link, usePage } from '@inertiajs/react';
import { StudioIcon } from '@/components/studio-icon';
import { useTranslations } from '@/lib/i18n';
import { isLibrary, isWorkflow, navigation } from '@/lib/navigation';
import type { FrameKey, SharedData } from '@/types';
import type { MediaProject } from '@/types/media';

export function StudioBreadcrumb({ frame, currentLabel, groupLabel }: { frame: FrameKey; currentLabel?: string; groupLabel?: string }) {
    const { t } = useTranslations();
    const { frameLinks, initialProject } = usePage<SharedData & { initialProject?: MediaProject | null }>().props;
    const menuFrame: FrameKey = isWorkflow(frame) ? 'newProject' : isLibrary(frame) ? 'allProjects' : frame;
    const group = navigation.find(section => section.items.some(item => item.key === menuFrame));
    if (!group) return null;
    const workflowStep = isWorkflow(frame) && frame !== 'newProject';
    const label = currentLabel ?? t(isLibrary(frame) ? 'allProjects' : frame);
    const parentHref = initialProject?.links.uploadPage ?? frameLinks.newProject;

    return <nav className="studio-breadcrumb" aria-label={t('breadcrumb')}>
        <ol>
            <li className="studio-breadcrumb-group"><span>{groupLabel ?? t(group.label)}</span></li>
            {workflowStep && <li className="studio-breadcrumb-segment"><StudioIcon name="chevron" aria-hidden="true" />{parentHref ? <Link href={parentHref}>{t('newProject')}</Link> : <span>{t('newProject')}</span>}</li>}
            <li className="studio-breadcrumb-segment is-current"><StudioIcon name="chevron" aria-hidden="true" /><span aria-current="page">{label}</span></li>
        </ol>
    </nav>;
}
