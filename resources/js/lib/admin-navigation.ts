import type { IconName } from '@/components/studio-icon';
import type { Locale } from '@/types';

type Label = Record<Locale, string>;
type AdminMenuItem = { key: string; icon: IconName; label: Label };
type AdminMenuGroup = { key: string; icon: IconName; label: Label; items: AdminMenuItem[] };

export const adminNavigation: AdminMenuGroup[] = [
    {
        key: 'overview', icon: 'grid', label: { en: 'Overview', my: 'ခြုံငုံသုံးသပ်ချက်', th: 'ภาพรวม' },
        items: [{ key: 'dashboard', icon: 'home', label: { en: 'Dashboard', my: 'ခြုံငုံသုံးသပ်ချက်', th: 'แดชบอร์ด' } }],
    },
    {
        key: 'customers', icon: 'users', label: { en: 'Customers', my: 'အသုံးပြုသူများ', th: 'ลูกค้า' },
        items: [
            { key: 'users', icon: 'users', label: { en: 'User Accounts', my: 'အသုံးပြုသူ အကောင့်များ', th: 'บัญชีผู้ใช้' } },
            { key: 'subscriptions', icon: 'calendar', label: { en: 'Subscriptions', my: 'အဖွဲ့ဝင်စာရင်းများ', th: 'การสมัครสมาชิก' } },
            { key: 'support', icon: 'headphones', label: { en: 'Support', my: 'အကူအညီတောင်းဆိုမှုများ', th: 'การช่วยเหลือ' } },
        ],
    },
    {
        key: 'monetization', icon: 'credit-card', label: { en: 'Monetization', my: 'ရောင်းချမှု စီမံခန့်ခွဲရေး', th: 'การสร้างรายได้' },
        items: [
            { key: 'packages', icon: 'layers', label: { en: 'Packages', my: 'ပက်ကေ့ချ်များ', th: 'แพ็กเกจ' } },
            { key: 'payments', icon: 'credit-card', label: { en: 'Payments & Refunds', my: 'ငွေပေးချေမှုနှင့် ပြန်အမ်းမှု', th: 'การชำระเงินและคืนเงิน' } },
            { key: 'usageLedger', icon: 'receipt', label: { en: 'Usage Ledger', my: 'အသုံးပြုမှု စာရင်း', th: 'บัญชีการใช้งาน' } },
        ],
    },
    {
        key: 'operations', icon: 'monitor', label: { en: 'Operations', my: 'လုပ်ငန်းလည်ပတ်မှု', th: 'การดำเนินงาน' },
        items: [
            { key: 'jobMonitor', icon: 'jobs', label: { en: 'Job Monitor', my: 'လုပ်ဆောင်မှု စောင့်ကြည့်ရန်', th: 'ติดตามงาน' } },
            { key: 'workerHealth', icon: 'activity', label: { en: 'Worker Health', my: 'Worker အခြေအနေ', th: 'สถานะเวิร์กเกอร์' } },
            { key: 'scheduledTasks', icon: 'clock', label: { en: 'Scheduled Tasks', my: 'အချိန်ဇယားအလုပ်များ', th: 'งานตามกำหนดเวลา' } },
        ],
    },
    {
        key: 'reports', icon: 'chart', label: { en: 'Reports', my: 'အစီရင်ခံစာများ', th: 'รายงาน' },
        items: [
            { key: 'revenue', icon: 'chart', label: { en: 'Revenue', my: 'ဝင်ငွေ အစီရင်ခံစာ', th: 'รายได้' } },
            { key: 'usageReports', icon: 'activity', label: { en: 'Usage & Processing', my: 'အသုံးပြုမှုနှင့် လုပ်ဆောင်မှု', th: 'การใช้งานและประมวลผล' } },
            { key: 'aiCosts', icon: 'wallet', label: { en: 'AI & Storage Costs', my: 'AI နှင့် Storage ကုန်ကျစရိတ်', th: 'ต้นทุน AI และพื้นที่จัดเก็บ' } },
        ],
    },
    {
        key: 'settings', icon: 'settings', label: { en: 'Settings', my: 'သတ်မှတ်ချက်များ', th: 'การตั้งค่า' },
        items: [
            { key: 'providers', icon: 'key', label: { en: 'API Keys & Models', my: 'API Key နှင့် Model များ', th: 'คีย์ API และโมเดล' } },
            { key: 'defaults', icon: 'layers', label: { en: 'System Project Defaults', my: 'စနစ်၏ ပရောဂျက် မူလသတ်မှတ်ချက်', th: 'ค่าเริ่มต้นโปรเจกต์ของระบบ' } },
            { key: 'storage', icon: 'database', label: { en: 'Storage & Retention', my: 'သိမ်းဆည်းမှုနှင့် ထိန်းသိမ်းကာလ', th: 'พื้นที่เก็บและระยะเวลา' } },
            { key: 'paymentSettings', icon: 'credit-card', label: { en: 'Payment Configuration', my: 'ငွေပေးချေမှု သတ်မှတ်ချက်', th: 'ตั้งค่าการชำระเงิน' } },
            { key: 'generalSettings', icon: 'sliders', label: { en: 'General Settings', my: 'အထွေထွေ သတ်မှတ်ချက်များ', th: 'การตั้งค่าทั่วไป' } },
        ],
    },
    {
        key: 'administration', icon: 'shield', label: { en: 'Administration', my: 'စီမံခန့်ခွဲရေး', th: 'การดูแลระบบ' },
        items: [
            { key: 'audit', icon: 'shield', label: { en: 'Audit & Security', my: 'စစ်ဆေးမှတ်တမ်းနှင့် လုံခြုံရေး', th: 'บันทึกและความปลอดภัย' } },
            { key: 'notifications', icon: 'bell', label: { en: 'Notifications', my: 'အသိပေးချက်များ', th: 'การแจ้งเตือน' } },
        ],
    },
];