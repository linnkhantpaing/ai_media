export type Locale = 'my' | 'en' | 'th';
export type FrameKey = 'notifications' | 'billing' | 'myPlan' | 'dashboard' | 'newProject' | 'allProjects' | 'drafts' | 'awaitingReview' | 'processing' | 'completed' | 'needsAttention' | 'jobMonitor' | 'workerHealth' | 'preferences' | 'apiKeysModels' | 'projectDefaults' | 'storageRetention' | 'projectSetup' | 'scriptReview' | 'previewStudio' | 'projectProcessing' | 'projectResult';

export interface SharedData {
    [key: string]: unknown;
    app: { name: string; tagline?: string; logo?: string; lightLogo?: string | null; favicon?: string; language?: Locale; timezone?: string; date_format?: string; time_format?: string; support_email?: string; support_phone?: string; support_url?: string; maintenance?: {enabled:boolean; message:string} };
    locale: Locale;
    auth: { user: { name: string; email: string; is_admin: boolean; avatar_url?: string | null } | null };
    queueFeed?: string | null;
    supportFeed?: string | null;
    notificationLinks?: {index:string;feed:string;action:string}|null;
    customerNotifications?: {index:string;feed:string;action:string}|null;
    adminLinks?: Record<string, string>;
    frameLinks: Partial<Record<FrameKey, string>>;
    links: {
        workspace: string;
        login: string;
        logout: string;
        locale: string;
        forgotPassword: string;
        passwordEmail: string;
        passwordStore: string;
    };
}