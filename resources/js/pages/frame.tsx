import { Head } from '@inertiajs/react';
import { FramePanel } from '@/components/frame-panel';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import type { FrameKey } from '@/types';

export default function Frame({ frame }: { frame: FrameKey }) {
    const { t } = useTranslations();
    return <AppLayout frame={frame}><Head title={t(frame)} /><FramePanel frame={frame} /></AppLayout>;
}