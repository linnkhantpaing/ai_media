import type { FrameKey } from '@/types';
import type { MessageKey } from './i18n';
import type { IconName } from '@/components/studio-icon';

export type NavItem = { key: FrameKey; icon: IconName };
export const navigation: { label: MessageKey; icon: IconName; items: NavItem[] }[] = [
    { label: 'workspace', icon: 'layers', items: [{ key: 'dashboard', icon: 'home' }, { key: 'newProject', icon: 'plus' }] },
    { label: 'projects', icon: 'folder', items: [{ key: 'allProjects', icon: 'layers' }] },
    { label: 'account', icon: 'wallet', items: [{ key: 'myPlan', icon: 'calendar' }, { key: 'billing', icon: 'wallet' }] },
    { label: 'operations', icon: 'monitor', items: [{ key: 'jobMonitor', icon: 'jobs' }, { key: 'workerHealth', icon: 'activity' }] },
    { label: 'settings', icon: 'settings', items: [{ key: 'preferences', icon: 'sliders' }, { key: 'apiKeysModels', icon: 'key' }, { key: 'projectDefaults', icon: 'layers' }, { key: 'storageRetention', icon: 'database' }] },
];
export const workflow: NavItem[] = [
    { key: 'projectSetup', icon: 'sliders' }, { key: 'scriptReview', icon: 'file' }, { key: 'previewStudio', icon: 'play' }, { key: 'projectProcessing', icon: 'loader' }, { key: 'projectResult', icon: 'download' },
];
export const isWorkflow = (frame: FrameKey) => frame === 'newProject' || workflow.some((item) => item.key === frame);
export const frameIcon = (frame: FrameKey): IconName => frame === 'dashboard' ? 'grid' : [...navigation.flatMap((group) => group.items), ...workflow].find((item) => item.key === frame)?.icon ?? 'grid';
export const isLibrary = (frame: FrameKey): boolean => (['allProjects', 'drafts', 'awaitingReview', 'processing', 'completed', 'needsAttention'] as FrameKey[]).includes(frame);
