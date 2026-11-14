import { usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';

const words = {
    signIn: ['Sign in again', 'အကောင့်ပြန်ဝင်ရန်', 'เข้าสู่ระบบอีกครั้ง'],
    clearSearch: ['Clear search', 'ရှာဖွေမှုရှင်းရန်', 'ล้างการค้นหา'],
    description: ['Manage all your video recap projects in one place.', 'ဗီဒီယို Recap ပရောဂျက်အားလုံးကို တစ်နေရာတည်းတွင် စီမံပါ။', 'จัดการโปรเจกต์วิดีโอสรุปทั้งหมดในที่เดียว'],
    search: ['Search projects…', 'ပရောဂျက်များ ရှာရန်…', 'ค้นหาโปรเจกต์…'],
    mode: ['Mode', 'ပုံစံ', 'โหมด'], source: ['Source', 'ရင်းမြစ်', 'แหล่งที่มา'],
    allModes: ['Mode: All', 'ပုံစံအားလုံး', 'โหมด: ทั้งหมด'], allSources: ['Source: All', 'ရင်းမြစ်အားလုံး', 'แหล่งที่มา: ทั้งหมด'],
    local: ['Local File', 'စက်ထဲမှဖိုင်', 'ไฟล์จากอุปกรณ์'], youtube: ['YouTube', 'YouTube', 'YouTube'],
    subs: ['Subtitles Only', 'စာတန်းထိုးရန်', 'คำบรรยายเท่านั้น'], dub: ['Full Dub', 'အသံပြန်သွင်းရန်', 'พากย์เต็มรูปแบบ'],
    date: ['Updated date', 'နောက်ဆုံးပြင်ဆင်သည့်ရက်', 'วันที่อัปเดต'], anyTime: ['All Time', 'အချိန်အားလုံး', 'ทุกช่วงเวลา'],
    today: ['Today', 'ယနေ့', 'วันนี้'], week: ['Last 7 days', 'နောက်ဆုံး ၇ ရက်', '7 วันที่ผ่านมา'], custom: ['Custom range', 'ကိုယ်တိုင်ရက်ရွေးရန်', 'กำหนดช่วงวันที่'],
    from: ['From', 'စရက်', 'ตั้งแต่'], to: ['To', 'ဆုံးရက်', 'ถึง'], dateHint: ['Updated dates · Asia/Bangkok (UTC+7)', 'ပြင်ဆင်သည့်ရက် · Asia/Bangkok (UTC+7)', 'วันที่อัปเดต · Asia/Bangkok (UTC+7)'],
    sort: ['Sort', 'စီရန်', 'เรียงลำดับ'], newest: ['Sort: Newest', 'နောက်ဆုံးပြင်ထားသည်မှ', 'อัปเดตล่าสุดก่อน'], oldest: ['Updated: Oldest', 'အရင်ပြင်ထားသည်မှ', 'อัปเดตเก่าสุดก่อน'], created: ['Recently created', 'အသစ်ဆောက်ထားသည်မှ', 'สร้างล่าสุดก่อน'], az: ['Title: A–Z', 'အမည် A–Z', 'ชื่อ A–Z'],
    grid: ['Grid view', 'ကတ်ပုံစံ', 'มุมมองการ์ด'], list: ['List view', 'စာရင်းပုံစံ', 'มุมมองรายการ'],
    filters: ['Filters', 'စစ်ထုတ်ရန်', 'ตัวกรอง'], reset: ['Reset filters', 'စစ်ထုတ်ချက်များ ရှင်းရန်', 'ล้างตัวกรอง'], apply: ['Apply', 'အသုံးပြုရန်', 'ใช้ตัวกรอง'],
    showing: ['Showing {from}–{to} of {total} projects', 'ပရောဂျက် {total} ခုမှ {from}–{to} ကို ပြထားသည်', 'แสดง {from}–{to} จาก {total} โปรเจกต์'],
    empty: ['Your next story starts here', 'နောက်ထပ်ဇာတ်လမ်းကို ဒီနေရာမှ စတင်ပါ', 'เริ่มเรื่องราวถัดไปของคุณที่นี่'],
    emptyHint: ['Create your first project. Saved videos, scripts and results will appear here.', 'ပရောဂျက်အသစ် စတင်ပါ။ သိမ်းထားသည့် ဗီဒီယို၊ စာမူနှင့် ရလဒ်များကို ဤနေရာတွင် ပြမည်။', 'สร้างโปรเจกต์แรก วิดีโอ บท และผลลัพธ์ที่บันทึกไว้จะแสดงที่นี่'],
    noMatches: ['No projects match these filters.', 'ဤစစ်ထုတ်ချက်များနှင့် ကိုက်ညီသော ပရောဂျက်မရှိပါ။', 'ไม่พบโปรเจกต์ที่ตรงกับตัวกรอง'],
    noMatchesHint: ['Try a different search or reset the filters.', 'အခြားအမည်ဖြင့် ရှာပါ သို့မဟုတ် စစ်ထုတ်ချက်များ ရှင်းပါ။', 'ลองค้นหาคำอื่นหรือล้างตัวกรอง'],
    more: ['Project actions', 'ပရောဂျက်လုပ်ဆောင်ချက်များ', 'การดำเนินการโปรเจกต์'],
    open: ['Open Project', 'ပရောဂျက်ဖွင့်ရန်', 'เปิดโปรเจกต์'], resume: ['Resume Upload', 'ဖိုင်တင်ခြင်း ဆက်ရန်', 'อัปโหลดต่อ'], setup: ['Continue Setup', 'သတ်မှတ်ချက် ဆက်လုပ်ရန်', 'ตั้งค่าต่อ'],
    review: ['Review Script', 'စာမူစစ်ဆေးရန်', 'ตรวจทานบท'], progress: ['View Progress', 'လုပ်ဆောင်မှုကြည့်ရန်', 'ดูความคืบหน้า'], viewImport: ['View Import', 'ဗီဒီယိုယူမှုကြည့်ရန်', 'ดูการนำเข้า'], result: ['View Result', 'ရလဒ်ကြည့်ရန်', 'ดูผลลัพธ์'],
    rename: ['Rename Project', 'ပရောဂျက်အမည်ပြောင်းရန်', 'เปลี่ยนชื่อโปรเจกต์'], title: ['Project Title', 'ပရောဂျက်အမည်', 'ชื่อโปรเจกต์'], save: ['Save Changes', 'ပြင်ဆင်ချက်သိမ်းရန်', 'บันทึกการเปลี่ยนแปลง'], saved: ['Project renamed.', 'ပရောဂျက်အမည် ပြောင်းပြီးပါပြီ။', 'เปลี่ยนชื่อโปรเจกต์แล้ว'],
    delete: ['Delete Project', 'ပရောဂျက်ဖျက်ရန်', 'ลบโปรเจกต์'], deleteHint: ['Delete this project and its source, saved script, outputs and cached files? This cannot be undone.', 'ဤပရောဂျက်နှင့် မူရင်းဗီဒီယို၊ သိမ်းထားသည့်စာမူ၊ ရလဒ်နှင့် ယာယီဖိုင်များကို ဖျက်မလား။ ပြန်ယူ၍မရပါ။', 'ลบโปรเจกต์นี้พร้อมวิดีโอต้นฉบับ บท ผลลัพธ์ และไฟล์แคชหรือไม่ การดำเนินการนี้ย้อนกลับไม่ได้'],
    deleted: ['Project deleted.', 'ပရောဂျက်ဖျက်ပြီးပါပြီ။', 'ลบโปรเจกต์แล้ว'], busyHint: ['Stop active work before renaming or deleting.', 'အမည်ပြောင်းရန် သို့မဟုတ် ဖျက်ရန် လက်ရှိလုပ်ဆောင်မှုကို အရင်ရပ်ပါ။', 'หยุดงานที่กำลังทำก่อนเปลี่ยนชื่อหรือลบ'],
    cancel: ['Cancel', 'မလုပ်တော့ပါ', 'ยกเลิก'], working: ['Working…', 'လုပ်ဆောင်နေသည်…', 'กำลังดำเนินการ…'],
    preview: ['Quick Preview', 'အမြန်ကြည့်ရန်', 'ดูตัวอย่าง'], sourceVideo: ['Source Video', 'မူရင်းဗီဒီယို', 'วิดีโอต้นฉบับ'], current: ['Current Output', 'လက်ရှိရလဒ်', 'ผลลัพธ์ปัจจุบัน'], previousOutput: ['Previous Output', 'ယခင်ထုတ်ပြီးသားရလဒ်', 'ผลลัพธ์ก่อนหน้า'], output: ['Published Output', 'ထုတ်ပြီးသားရလဒ်', 'ผลลัพธ์ที่สร้างแล้ว'], pending: ['Changes pending render', 'ပြင်ထားသောစာမူဖြင့် ဖိုင်မထုတ်ရသေး', 'มีการแก้ไขที่ยังไม่ได้สร้างผลลัพธ์'],
    pendingHint: ['Your edits are saved. Generate again to update downloadable files.', 'စာမူပြင်ဆင်ချက်များ သိမ်းပြီးပါပြီ။ ဒေါင်းလုဒ်ဖိုင်အသစ်ရရန် ပြန်ထုတ်ပါ။', 'บันทึกการแก้ไขแล้ว สร้างอีกครั้งเพื่ออัปเดตไฟล์ดาวน์โหลด'],
    downloadVideo: ['Download MP4', 'MP4 ဒေါင်းလုဒ်', 'ดาวน์โหลด MP4'], downloadSrt: ['Download SRT', 'SRT ဒေါင်းလုဒ်', 'ดาวน์โหลด SRT'],
    editScript: ['Edit Script & Re-render', 'စာမူပြင်ပြီး ပြန်ထုတ်ရန်', 'แก้ไขบทและสร้างใหม่'], adjust: ['Adjust Setup', 'သတ်မှတ်ချက်ပြင်ရန်', 'ปรับการตั้งค่า'],
    retryImport: ['Retry Import', 'ဗီဒီယိုပြန်ယူရန်', 'ลองนำเข้าอีกครั้ง'], retry: ['Retry Processing', 'ပြန်လည်လုပ်ဆောင်ရန်', 'ลองประมวลผลอีกครั้ง'], cancelImport: ['Cancel Import', 'ဗီဒီယိုယူခြင်း ရပ်ရန်', 'ยกเลิกการนำเข้า'], cancelProcessing: ['Cancel Processing', 'လုပ်ဆောင်မှုရပ်ရန်', 'ยกเลิกการประมวลผล'],
    queued: ['Waiting for worker', 'Worker ကို စောင့်နေသည်', 'กำลังรอ Worker'], retryWaiting: ['Retry scheduled', 'ပြန်ကြိုးစားရန် စောင့်နေသည်', 'รอลองใหม่ตามกำหนด'],
    loading: ['Refreshing projects…', 'ပရောဂျက်များ ပြန်ဖတ်နေသည်…', 'กำลังอัปเดตโปรเจกต์…'], loadFailed: ['Projects could not refresh. Your saved projects are kept.', 'ပရောဂျက်များ ပြန်ဖတ်၍မရပါ။ သိမ်းထားသောပရောဂျက်များ ရှိနေဆဲဖြစ်သည်။', 'อัปเดตโปรเจกต์ไม่ได้ โปรเจกต์ที่บันทึกไว้ยังอยู่'], tryAgain: ['Try again', 'ပြန်ကြိုးစားရန်', 'ลองอีกครั้ง'],
    fileUnavailable: ['This file could not load. Open the project or try again.', 'ဤဖိုင်ကို ဖွင့်မရပါ။ ပရောဂျက်ကို ဖွင့်ပါ သို့မဟုတ် ပြန်ကြိုးစားပါ။', 'โหลดไฟล์นี้ไม่ได้ กรุณาเปิดโปรเจกต์หรือลองอีกครั้ง'],
    duration: ['Source duration', 'မူရင်းဗီဒီယိုကြာချိန်', 'ระยะเวลาต้นฉบับ'], updated: ['Last updated', 'နောက်ဆုံးပြင်ဆင်ချိန်', 'อัปเดตล่าสุด'], project: ['Project', 'ပရောဂျက်', 'โปรเจกต์'], status: ['Status', 'အခြေအနေ', 'สถานะ'], files: ['Files', 'ဖိုင်များ', 'ไฟล์'], actions: ['Actions', 'လုပ်ဆောင်ချက်များ', 'การดำเนินการ'],
    previousPage: ['Previous page', 'ရှေ့စာမျက်နှာ', 'หน้าก่อนหน้า'], nextPage: ['Next page', 'နောက်စာမျက်နှာ', 'หน้าถัดไป'], page: ['Page {page}', 'စာမျက်နှာ {page}', 'หน้า {page}'],
} satisfies Record<string, [string, string, string]>;
export type LibraryWord = keyof typeof words;
export function useLibraryText() {
    const { locale } = usePage<SharedData>().props;
    const index = locale === 'my' ? 1 : locale === 'th' ? 2 : 0;
    return { locale, t: (key: LibraryWord, values: Record<string, string | number> = {}): string => Object.entries(values).reduce((text, [name, value]) => text.replaceAll('{'+name+'}', String(value)), words[key][index]) };
}