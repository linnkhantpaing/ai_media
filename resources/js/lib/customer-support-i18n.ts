import {usePage} from '@inertiajs/react';
import type {SharedData} from '@/types';
const words = {
 support: ['Support','အကူအညီ','ฝ่ายช่วยเหลือ'],
 administrator: ['Administrator','စီမံခန့်ခွဲသူ','ผู้ดูแลระบบ'],
 subtitle: ['Chat with your administrator','Admin ထံ တိုက်ရိုက် စာပို့နိုင်ပါသည်','แชตกับผู้ดูแลระบบของคุณ'],
 close: ['Close chat','စာစကားဝိုင်း ပိတ်ရန်','ปิดแชต'],
 welcome: ['How can we help?','ဘာအကူအညီလိုပါသလဲ။','ให้เราช่วยอะไรได้บ้าง?'],
 intro: ['Ask about your videos, account or payments. Your administrator will reply here.','ဗီဒီယို၊ အကောင့်၊ ငွေပေးချေမှုအတွက် ဒီနေရာမှ စာပို့နိုင်ပါသည်။ Admin ၏ ပြန်စာကိုလည်း ဒီမှာပင် ကြည့်နိုင်ပါသည်။','สอบถามเรื่องวิดีโอ บัญชี หรือการชำระเงิน ผู้ดูแลระบบจะตอบกลับที่นี่'],
 placeholder: ['Message your administrator…','Admin ထံ စာရေးရန်…','ส่งข้อความถึงผู้ดูแลระบบ…'],
 send: ['Send message','စာပို့ရန်','ส่งข้อความ'],
 attach: ['Attach image or PDF','ပုံ သို့မဟုတ် PDF ထည့်ရန်','แนบรูปภาพหรือ PDF'],
 fileHint: ['Up to 5 images / PDFs · 10 MB each','ပုံ / PDF ၅ ဖိုင်အထိ · တစ်ဖိုင် 10 MB','รูปภาพ / PDF ไม่เกิน 5 ไฟล์ · ไฟล์ละ 10 MB'],
 invalidFiles: ['Choose up to 5 JPG, PNG, WEBP or PDF files, each no larger than 10 MB.','JPG၊ PNG၊ WEBP သို့မဟုတ် PDF ကို တစ်ဖိုင် 10 MB ထက်မကျော်ဘဲ ၅ ဖိုင်အထိ ထည့်နိုင်ပါသည်။','เลือก JPG, PNG, WEBP หรือ PDF ไม่เกิน 5 ไฟล์ ไฟล์ละไม่เกิน 10 MB'],
 removeFile: ['Remove attachment','ဖိုင်ဖယ်ရန်','นำไฟล์แนบออก'],
 older: ['Earlier messages','ယခင်စာများ ကြည့်ရန်','ข้อความก่อนหน้า'],
 deleted: ['This message was deleted.','ဤစာကို ဖျက်ထားပါသည်။','ข้อความนี้ถูกลบแล้ว'],
 newMessages: ['New messages','စာအသစ်များ','ข้อความใหม่'],
 loading: ['Loading conversation…','စာစကားဝိုင်း ဖတ်နေသည်…','กำลังโหลดการสนทนา…'],
 failed: ['Unable to load chat. Please retry.','စာစကားဝိုင်း မဖတ်နိုင်ပါ။ ပြန်စမ်းပါ။','ไม่สามารถโหลดแชตได้ โปรดลองอีกครั้ง'],
 sendFailed: ['Message not sent. Your draft is still here. Please retry.','စာမပို့ရသေးပါ။ ရေးထားသောစာကို ထိန်းထားပါသည်။ ပြန်ပို့ပါ။','ยังส่งข้อความไม่สำเร็จ ข้อความร่างยังอยู่ โปรดลองอีกครั้ง'],
 sessionExpired: ['Your session expired. Sign in again to continue.','ဆက်လက်အသုံးပြုရန် အကောင့်ပြန်ဝင်ပါ။','เซสชันหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง'],
 rateLimited: ['Please wait a moment before trying again.','ခဏစောင့်ပြီး ပြန်စမ်းပါ။','โปรดรอสักครู่แล้วลองอีกครั้ง'],
 retry: ['Retry','ပြန်စမ်းရန်','ลองอีกครั้ง'],
 unread: ['Unread messages','မဖတ်ရသေးသော စာများ','ข้อความที่ยังไม่ได้อ่าน'],
 attachment: ['Attachment','ပူးတွဲဖိုင်','ไฟล์แนบ'],
 actions: ['Message actions', 'စာအတွက် လုပ်ဆောင်ချက်များ', 'ตัวเลือกข้อความ'],
 edited: ['Edited', 'ပြင်ထားသည်', 'แก้ไขแล้ว'],
 dismiss: ['Dismiss', 'ပိတ်ရန်', 'ปิด'],
 deleteTitle: ['Delete message?', 'ဒီစာကို ဖျက်မလား။', 'ลบข้อความหรือไม่?'],
 deleteBody: ['This replaces the message with a deleted notice for both sides.', 'ဖျက်ပြီးကြောင်း နှစ်ဖက်စလုံးမှာ ပြပါမည်။', 'ทั้งสองฝ่ายจะเห็นว่าข้อความถูกลบ'],
 replying: ['Replying to', 'ပြန်ဖြေမည့်စာ', 'กำลังตอบกลับ'],
 editing: ['Editing message', 'စာပြင်နေသည်', 'กำลังแก้ไขข้อความ'],
 cancel: ['Cancel', 'မလုပ်တော့ပါ', 'ยกเลิก'],
 delete: ['Delete', 'ဖျက်ရန်', 'ลบ'],
 edit: ['Edit message', 'စာပြင်ရန်', 'แก้ไขข้อความ'],
 save: ['Save edit', 'သိမ်းရန်', 'บันทึกการแก้ไข'],
 reply: ['Reply', 'ပြန်ဖြေရန်', 'ตอบกลับ'],
 changeFailed: ['Could not update the message. Please retry.', 'စာကို ပြင်ဆင်၍မရပါ။ ပြန်စမ်းပါ။', 'แก้ไขข้อความไม่สำเร็จ โปรดลองอีกครั้ง'],
 messageUnavailable: ['This message is no longer available.', 'ဤစာကို အသုံးပြု၍မရတော့ပါ။', 'ข้อความนี้ไม่พร้อมใช้งานแล้ว'],
} as const;
export function useCustomerSupportText() {
 const {locale}=usePage<SharedData>().props;
 const index=locale==='my'?1:locale==='th'?2:0;
 return {t:(key:keyof typeof words)=>words[key][index],language:locale==='my'?'my-MM':locale==='th'?'th-TH':'en-GB'};
}
