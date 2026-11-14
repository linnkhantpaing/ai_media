import { Head } from '@inertiajs/react';
import { FramePanel } from '@/components/frame-panel';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';

export default function Workspace() {
    const { t } = useTranslations();
    return <AppLayout frame="dashboard"><Head title={t('dashboard')} /><FramePanel frame="dashboard" /></AppLayout>;
}