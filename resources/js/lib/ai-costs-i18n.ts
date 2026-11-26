import { usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';

const words = {
    title: ['AI & Storage Costs', 'AI နှင့် Storage ကုန်ကျစရိတ်', 'ต้นทุน AI และพื้นที่จัดเก็บ'],
    subtitle: ['Gemini tokens, R2 storage and estimated cost in MMK — per day, customer and video.', 'Gemini token၊ R2 storage နှင့် ခန့်မှန်း ကုန်ကျငွေ (MMK) — နေ့၊ Customer နှင့် ဗီဒီယို အလိုက်။', 'โทเค็น Gemini พื้นที่ R2 และต้นทุนโดยประมาณ (MMK) แยกตามวัน ลูกค้า และวิดีโอ'],
    prices: ['Prices & Rates', 'စျေးနှုန်းနှင့် ငွေလဲနှုန်း', 'ราคาและอัตราแลกเปลี่ยน'],
    refresh: ['Refresh', 'Refresh', 'รีเฟรช'],
    today: ['Today', 'ယနေ့', 'วันนี้'], last7: ['Last 7 days', 'နောက်ဆုံး ၇ ရက်', '7 วันล่าสุด'], last30: ['Last 30 days', 'နောက်ဆုံး ၃၀ ရက်', '30 วันล่าสุด'], last90: ['Last 90 days', 'နောက်ဆုံး ၉၀ ရက်', '90 วันล่าสุด'], custom: ['Custom', 'စိတ်ကြိုက်', 'กำหนดเอง'],
    from: ['From', 'မှ', 'จาก'], to: ['To', 'အထိ', 'ถึง'], range: ['Date range', 'ရက်အပိုင်းအခြား', 'ช่วงวันที่'],
    tokens: ['AI tokens', 'AI token', 'โทเค็น AI'], inOut: ['Input {input} · Output {output}', 'Input {input} · Output {output}', 'อินพุต {input} · เอาต์พุต {output}'],
    aiCost: ['AI cost (est.)', 'AI ကုန်ကျငွေ (ခန့်မှန်း)', 'ต้นทุน AI (ประมาณ)'], calls: ['{count} AI calls', 'AI ခေါ်ဆိုမှု {count} ကြိမ်', 'เรียก AI {count} ครั้ง'],
    storage: ['Storage now', 'လက်ရှိ Storage', 'พื้นที่ใช้ตอนนี้'], storageChange: ['+{added} · −{removed} in range', 'ဤကာလ +{added} · −{removed}', 'ในช่วงนี้ +{added} · −{removed}'],
    storageCost: ['Storage cost (est.)', 'Storage ကုန်ကျငွေ (ခန့်မှန်း)', 'ต้นทุนพื้นที่ (ประมาณ)'], perGb: ['{price} USD per GB-month', 'GB-month လျှင် {price} USD', '{price} USD ต่อ GB-เดือน'],
    wasted: ['Unused AI cost', 'အလကား ကုန်သွားသော AI', 'ต้นทุน AI ที่ไม่ได้ใช้'], wastedHint: ['Failed, cancelled or never-exported videos: {share} of AI cost', 'Fail / Cancel / Export မလုပ်ခဲ့သော ဗီဒီယိုများ — AI ကုန်ကျငွေ၏ {share}', 'วิดีโอที่ล้มเหลว ยกเลิก หรือไม่ได้ส่งออก: {share} ของต้นทุน AI'],
    profit: ['Revenue − cost', 'ဝင်ငွေ − ကုန်ကျငွေ', 'รายได้ − ต้นทุน'], revenueHint: ['Revenue {revenue}', 'ဝင်ငွေ {revenue}', 'รายได้ {revenue}'],
    daily: ['Daily cost', 'နေ့စဉ် ကုန်ကျငွေ', 'ต้นทุนรายวัน'], ai: ['AI', 'AI', 'AI'], storageShort: ['Storage', 'Storage', 'พื้นที่'],
    byStage: ['Where the AI cost goes', 'AI ကုန်ကျငွေ ဘယ်အဆင့်မှာ ကုန်လဲ', 'ต้นทุน AI ไปที่ขั้นตอนใด'], noUsage: ['No AI usage in this range yet.', 'ဤကာလတွင် AI အသုံးပြုမှု မရှိသေးပါ။', 'ยังไม่มีการใช้ AI ในช่วงนี้'],
    customers: ['Customers', 'Customer များ', 'ลูกค้า'], videos: ['Videos', 'ဗီဒီယိုများ', 'วิดีโอ'], search: ['Search customer or video…', 'Customer သို့မဟုတ် ဗီဒီယို ရှာရန်…', 'ค้นหาลูกค้าหรือวิดีโอ…'],
    customer: ['Customer', 'Customer', 'ลูกค้า'], video: ['Video', 'ဗီဒီယို', 'วิดีโอ'], workflow: ['Workflow', 'Workflow', 'เวิร์กโฟลว์'], source: ['Source', 'Source', 'ต้นฉบับ'],
    revenue: ['Revenue', 'ဝင်ငွေ', 'รายได้'], status: ['Status', 'အခြေအနေ', 'สถานะ'], cost: ['Cost', 'ကုန်ကျငွေ', 'ต้นทุน'], videosCount: ['Videos', 'ဗီဒီယို', 'วิดีโอ'],
    completed: ['Exported', 'Export ပြီး', 'ส่งออกแล้ว'], in_progress: ['In progress', 'လုပ်ဆောင်နေ', 'กำลังทำ'], failed: ['Failed', 'မအောင်မြင်', 'ล้มเหลว'], cancelled: ['Cancelled', 'ရပ်ထား', 'ยกเลิก'], not_exported: ['Not exported', 'Export မလုပ်ခဲ့', 'ไม่ได้ส่งออก'], deleted: ['Deleted', 'ဖျက်ပြီး', 'ลบแล้ว'],
    movie: ['Long Movie', 'ရုပ်ရှင်ရှည်', 'ภาพยนตร์ยาว'], translate: ['Translate', 'ဘာသာပြန်', 'แปล'], subtitles: ['Subtitles', 'စာတန်း', 'คำบรรยาย'],
    movie_watch: ['Watch movie (video AI)', 'ရုပ်ရှင် ကြည့်ခြင်း (Video AI)', 'ดูภาพยนตร์ (AI วิดีโอ)'], movie_plan: ['Write recap plan', 'Recap plan ရေးခြင်း', 'เขียนแผนสรุป'], transcribe: ['Transcribe audio', 'အသံကို စာပြောင်းခြင်း', 'ถอดเสียง'], translate_stage: ['Translate', 'ဘာသာပြန်ခြင်း', 'แปล'],
    narration_voice: ['Narration voice (TTS)', 'Narration အသံ (TTS)', 'เสียงบรรยาย (TTS)'], narration_fit: ['Fit narration timing', 'Narration အချိန်ညှိခြင်း', 'ปรับเวลาบรรยาย'], rewrite: ['Rewrite scene', 'Scene ပြန်ရေးခြင်း', 'เขียนฉากใหม่'],
    voice_preview: ['Voice preview', 'Voice preview', 'ตัวอย่างเสียง'], voice_sample: ['Voice samples', 'အသံ နမူနာ', 'ตัวอย่างเสียงพากย์'], check: ['Admin API tests', 'Admin API စမ်းသပ်မှု', 'การทดสอบ API ของแอดมิน'], other: ['Other', 'အခြား', 'อื่นๆ'],
    details: ['AI calls for this video', 'ဤဗီဒီယို၏ AI ခေါ်ဆိုမှုများ', 'การเรียก AI ของวิดีโอนี้'], step: ['Step', 'အဆင့်', 'ขั้นตอน'], model: ['Model', 'Model', 'โมเดล'], time: ['Time', 'အချိန်', 'เวลา'],
    input: ['Input', 'Input', 'อินพุต'], output: ['Output', 'Output', 'เอาต์พุต'], total: ['Total', 'စုစုပေါင်း', 'รวม'], detailKept: ['Individual calls are kept for {days} days; older days show daily totals only.', 'AI ခေါ်ဆိုမှု တစ်ခုချင်းကို {days} ရက် သိမ်းပြီး ကျော်လွန်သောရက်များကို နေ့စဉ် စုစုပေါင်းသာ ပြပါသည်။', 'เก็บรายการเรียกแต่ละครั้งไว้ {days} วัน วันที่เก่ากว่าจะแสดงเฉพาะยอดรวมรายวัน'],
    noCalls: ['No individual calls are kept for this video (older than the retention window).', 'ဤဗီဒီယိုအတွက် AI ခေါ်ဆိုမှု အသေးစိတ် မရှိတော့ပါ (သိမ်းထားချိန် ကျော်ပြီ)။', 'ไม่มีรายการเรียกแต่ละครั้งของวิดีโอนี้ (เกินระยะเวลาเก็บ)'],
    setPrices: ['Enter model prices and the USD → MMK rate to see costs.', 'ကုန်ကျငွေ မြင်ရန် Model စျေးနှုန်းနှင့် USD → MMK ငွေလဲနှုန်း ထည့်ပါ။', 'ใส่ราคาโมเดลและอัตรา USD → MMK เพื่อดูต้นทุน'],
    missingModels: ['No price yet for: {models}. Their tokens are counted but cost 0 Ks.', 'စျေးနှုန်း မထည့်ရသေးသော model: {models}။ Token ကို ရေတွက်ပြီး ကုန်ကျငွေ 0 Ks ဟု ပြပါသည်။', 'ยังไม่มีราคาสำหรับ: {models} นับโทเค็นแล้วแต่ต้นทุนเป็น 0 Ks'],
    pricesTitle: ['Prices & exchange rates', 'စျေးနှုန်းနှင့် ငွေလဲနှုန်း', 'ราคาและอัตราแลกเปลี่ยน'], pricesHint: ['Gemini prices are USD per 1 million tokens. Costs are estimates and always use the current prices, so updating a price also updates past days.', 'Gemini စျေးနှုန်းသည် token ၁ သန်းလျှင် USD ဖြစ်သည်။ ကုန်ကျငွေသည် ခန့်မှန်းချက်ဖြစ်ပြီး လက်ရှိ စျေးနှုန်းဖြင့် အမြဲ တွက်သောကြောင့် စျေးပြင်လျှင် ယခင်ရက်များပါ ပြောင်းပါမည်။', 'ราคา Gemini เป็น USD ต่อ 1 ล้านโทเค็น ต้นทุนเป็นค่าประมาณและใช้ราคาปัจจุบันเสมอ การแก้ราคาจะอัปเดตวันที่ผ่านมาด้วย'],
    inputPrice: ['Text / video input', 'Text / Video input', 'อินพุตข้อความ/วิดีโอ'], audioPrice: ['Audio input', 'Audio input', 'อินพุตเสียง'], cachedPrice: ['Cached input', 'Cached input', 'อินพุตแคช'], outputPrice: ['Output (incl. thinking)', 'Output (thinking ပါ)', 'เอาต์พุต (รวมการคิด)'],
    sameAsInput: ['Same as input', 'Input နှင့်တူ', 'เท่ากับอินพุต'], speechModel: ['Speech model — output is audio', 'Speech model — output သည် အသံ', 'โมเดลเสียง — เอาต์พุตเป็นเสียง'],
    storagePrice: ['R2 storage (USD per GB-month)', 'R2 storage (GB-month လျှင် USD)', 'พื้นที่ R2 (USD ต่อ GB-เดือน)'], usdRate: ['MMK for 1 USD', '1 USD လျှင် MMK', 'MMK ต่อ 1 USD'], thbRate: ['MMK for 1 THB', '1 THB လျှင် MMK', 'MMK ต่อ 1 THB'],
    rateHint: ['Used for costs and for revenue paid in USD or THB.', 'ကုန်ကျငွေနှင့် USD / THB ဖြင့် ပေးထားသော ဝင်ငွေကို MMK ပြောင်းရန် သုံးပါသည်။', 'ใช้คำนวณต้นทุนและรายได้ที่ชำระเป็น USD หรือ THB'],
    save: ['Save prices', 'စျေးနှုန်း သိမ်းရန်', 'บันทึกราคา'], saving: ['Saving…', 'သိမ်းနေသည်…', 'กำลังบันทึก…'], cancel: ['Cancel', 'မလုပ်တော့ပါ', 'ยกเลิก'], saved: ['Prices saved. Costs updated.', 'စျေးနှုန်း သိမ်းပြီး ကုန်ကျငွေ ပြန်တွက်ပြီးပါပြီ။', 'บันทึกราคาแล้ว อัปเดตต้นทุนแล้ว'],
    failedLoad: ['Could not load the report. Try again.', 'Report ကို မဖွင့်နိုင်ပါ။ ထပ်စမ်းပါ။', 'โหลดรายงานไม่ได้ ลองอีกครั้ง'], previous: ['Previous', 'ရှေ့', 'ก่อนหน้า'], next: ['Next', 'နောက်', 'ถัดไป'],
    estimate: ['Estimates from token counts and your prices — not a Google invoice.', 'Token အရေအတွက်နှင့် သင်ထည့်ထားသော စျေးနှုန်းဖြင့် ခန့်မှန်းထားခြင်းဖြစ်သည် — Google ငွေတောင်းခံလွှာ မဟုတ်ပါ။', 'ประมาณจากจำนวนโทเค็นและราคาที่คุณกำหนด ไม่ใช่ใบแจ้งหนี้ของ Google'],
    checkCost: ['incl. {amount} admin tests', 'Admin စမ်းသပ်မှု {amount} ပါ', 'รวมการทดสอบแอดมิน {amount}'], close: ['Close', 'ပိတ်ရန်', 'ปิด'], open: ['Open calls', 'ခေါ်ဆိုမှုများ ကြည့်ရန်', 'ดูการเรียก'],
} as const;

export type AiCostWord = keyof typeof words;

export function useAiCostText() {
    const { locale } = usePage<SharedData>().props;
    const index = locale === 'my' ? 1 : locale === 'th' ? 2 : 0;

    return {
        locale,
        t: (key: AiCostWord, values: Record<string, string | number> = {}) => (words[key][index] as string).replace(/\{(\w+)\}/g, (_, name: string) => String(values[name] ?? '')),
    };
}
