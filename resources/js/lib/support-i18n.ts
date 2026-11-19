import {usePage} from '@inertiajs/react';
import type {SharedData} from '@/types';
const words:Record<string,readonly string[]>={
 "title": [
  "Support",
  "အကူအညီတောင်းဆိုမှုများ",
  "การช่วยเหลือ"
 ],
 "description": [
  "Help customers with payments, access and video processing.",
  "ငွေပေးချေမှု၊ အကောင့်နှင့် ဗီဒီယို ပြဿနာများကို ကူညီဖြေရှင်းပါ။",
  "ช่วยลูกค้าเรื่องการชำระเงิน การเข้าถึง และการประมวลผลวิดีโอ"
 ],
 "newConversation": [
  "New Conversation",
  "စာစကားဝိုင်းအသစ်",
  "เริ่มการสนทนา"
 ],
 "all": [
  "All",
  "အားလုံး",
  "ทั้งหมด"
 ],
 "unread": [
  "Unread",
  "မဖတ်ရသေး",
  "ยังไม่อ่าน"
 ],
 "needs_reply": [
  "Needs Reply",
  "ပြန်ဖြေရန်",
  "รอตอบ"
 ],
 "in_progress": [
  "In Progress",
  "ဖြေရှင်းနေဆဲ",
  "กำลังดำเนินการ"
 ],
 "resolved": [
  "Resolved",
  "ဖြေရှင်းပြီး",
  "แก้ไขแล้ว"
 ],
 "open": [
  "Open",
  "ဖွင့်ထား",
  "เปิด"
 ],
 "waiting_customer": [
  "Waiting for Customer",
  "Customer အဖြေစောင့်နေ",
  "รอลูกค้าตอบ"
 ],
 "normal": [
  "Normal",
  "ပုံမှန်",
  "ปกติ"
 ],
 "high": [
  "High",
  "အရေးကြီး",
  "สูง"
 ],
 "urgent": [
  "Urgent",
  "အရေးပေါ်",
  "เร่งด่วน"
 ],
 "video": [
  "Video Processing",
  "ဗီဒီယို လုပ်ဆောင်မှု",
  "ประมวลผลวิดีโอ"
 ],
 "payment": [
  "Payment & Refund",
  "ငွေပေးချေ / ပြန်အမ်း",
  "ชำระเงินและคืนเงิน"
 ],
 "subscription": [
  "Package & Subscription",
  "ပက်ကေ့ချ်နှင့် သက်တမ်း",
  "แพ็กเกจและสมาชิก"
 ],
 "account": [
  "Account & Login",
  "အကောင့်နှင့် ဝင်ရောက်မှု",
  "บัญชีและเข้าสู่ระบบ"
 ],
 "general": [
  "General Question",
  "အထွေထွေ မေးခွန်း",
  "คำถามทั่วไป"
 ],
 "search": [
  "Search customer, email or conversation ID…",
  "Customer၊ Email၊ Conversation ID ရှာရန်…",
  "ค้นหาลูกค้า อีเมล หรือรหัสการสนทนา…"
 ],
 "categories": [
  "All Categories",
  "အမျိုးအစားအားလုံး",
  "ทุกหมวดหมู่"
 ],
 "latest": [
  "Latest Activity",
  "နောက်ဆုံး စာများ",
  "กิจกรรมล่าสุด"
 ],
 "waiting": [
  "Oldest Waiting",
  "အကြာဆုံး စောင့်နေသူ",
  "รอนานที่สุด"
 ],
 "customerContext": [
  "Customer Context",
  "Customer အချက်အလက်",
  "ข้อมูลลูกค้า"
 ],
 "customer": [
  "Customer",
  "Customer",
  "ลูกค้า"
 ],
 "since": [
  "Customer Since",
  "အကောင့် စတင်ရက်",
  "เป็นลูกค้าตั้งแต่"
 ],
 "lastLogin": [
  "Last Login",
  "နောက်ဆုံး ဝင်ရောက်ချိန်",
  "เข้าสู่ระบบล่าสุด"
 ],
 "never": [
  "Never signed in",
  "မဝင်ရောက်ရသေး",
  "ยังไม่เคยเข้าสู่ระบบ"
 ],
 "status": [
  "Status",
  "အခြေအနေ",
  "สถานะ"
 ],
 "priority": [
  "Priority",
  "အရေးကြီးမှု",
  "ความสำคัญ"
 ],
 "category": [
  "Category",
  "အမျိုးအစား",
  "หมวดหมู่"
 ],
 "subscriptionTitle": [
  "Subscription",
  "လက်ရှိ ပက်ကေ့ချ်",
  "สมาชิกปัจจุบัน"
 ],
 "recentPayments": [
  "Recent Payments",
  "နောက်ဆုံး ငွေပေးချေမှုများ",
  "การชำระเงินล่าสุด"
 ],
 "recentJobs": [
  "Recent Jobs",
  "နောက်ဆုံး လုပ်ဆောင်မှုများ",
  "งานล่าสุด"
 ],
 "storage": [
  "Storage Usage",
  "သိုလှောင်မှု",
  "พื้นที่จัดเก็บ"
 ],
 "links": [
  "Quick Links",
  "အမြန်သွားရန်",
  "ลิงก์ด่วน"
 ],
 "usage": [
  "Usage Ledger",
  "အသုံးပြုမှု စာရင်း",
  "บัญชีการใช้งาน"
 ],
 "user": [
  "Customer Account",
  "Customer အကောင့်",
  "บัญชีลูกค้า"
 ],
 "noSubscription": [
  "No subscription",
  "ပက်ကေ့ချ် မရှိသေး",
  "ยังไม่มีสมาชิก"
 ],
 "emptyRecords": [
  "No records yet",
  "မှတ်တမ်း မရှိသေး",
  "ยังไม่มีรายการ"
 ],
 "view": [
  "View record",
  "မှတ်တမ်း ကြည့်ရန်",
  "ดูรายการ"
 ],
 "reply": [
  "Reply to customer",
  "Customer ကို ပြန်ဖြေရန်",
  "ตอบลูกค้า"
 ],
 "note": [
  "Internal note",
  "Admin သီးသန့် မှတ်ချက်",
  "บันทึกภายใน"
 ],
 "private": [
  "Only visible to administrators",
  "Admin များသာ မြင်နိုင်သည်",
  "ผู้ดูแลระบบเท่านั้นที่มองเห็น"
 ],
 "placeholder": [
  "Type your message…",
  "စာ ရေးပါ…",
  "พิมพ์ข้อความ…"
 ],
 "send": [
  "Send",
  "ပို့ရန်",
  "ส่ง"
 ],
 "sending": [
  "Sending…",
  "ပို့နေသည်…",
  "กำลังส่ง…"
 ],
 "sent": [
  "Saved to conversation",
  "စကားဝိုင်းထဲ သိမ်းပြီး",
  "บันทึกในการสนทนาแล้ว"
 ],
 "read": [
  "Read by customer",
  "Customer ဖတ်ပြီး",
  "ลูกค้าอ่านแล้ว"
 ],
 "quick": [
  "Quick Reply",
  "အမြန် စာပြန်ရန်",
  "คำตอบด่วน"
 ],
 "quickScreenshot": [
  "Please send a screenshot of the issue.",
  "ဖြစ်နေတဲ့ ပြဿနာ screenshot လေး ပို့ပေးပါ။",
  "กรุณาส่งภาพหน้าจอของปัญหา"
 ],
 "quickPayment": [
  "Please send the transfer reference and receipt.",
  "ငွေလွှဲ Reference နဲ့ ပြေစာလေး ပို့ပေးပါ။",
  "กรุณาส่งเลขอ้างอิงและหลักฐานการโอน"
 ],
 "quickJob": [
  "Please share the Video Job ID.",
  "Video Job ID လေး ပို့ပေးပါ။",
  "กรุณาส่งรหัสงานวิดีโอ"
 ],
 "attachment": [
  "Attach files",
  "ဖိုင် ပူးတွဲရန်",
  "แนบไฟล์"
 ],
 "attachmentHint": [
  "PNG, JPG, WEBP or PDF · Up to 5 files, 10 MiB each",
  "PNG၊ JPG၊ WEBP၊ PDF · ၅ ဖိုင်အထိ၊ တစ်ဖိုင် 10 MiB",
  "PNG, JPG, WEBP หรือ PDF · 5 ไฟล์ สูงสุดไฟล์ละ 10 MiB"
 ],
 "remove": [
  "Remove",
  "ဖယ်ရှားရန်",
  "นำออก"
 ],
 "download": [
  "Download",
  "ဒေါင်းလုပ်",
  "ดาวน์โหลด"
 ],
 "preview": [
  "Preview attachment",
  "ပူးတွဲဖိုင် ကြည့်ရန်",
  "ดูไฟล์แนบ"
 ],
 "close": [
  "Close",
  "ပိတ်ရန်",
  "ปิด"
 ],
 "cancel": [
  "Cancel",
  "မလုပ်တော့ပါ",
  "ยกเลิก"
 ],
 "back": [
  "Back to inbox",
  "စာရင်း ပြန်သွားရန်",
  "กลับกล่องข้อความ"
 ],
 "refresh": [
  "Refresh",
  "ပြန်ဖတ်ရန်",
  "รีเฟรช"
 ],
 "empty": [
  "Your support inbox is ready",
  "Support inbox အသင့်ဖြစ်ပါပြီ",
  "กล่องช่วยเหลือพร้อมแล้ว"
 ],
 "emptyHint": [
  "Start a conversation with a customer. Incoming messages will appear here.",
  "Customer နဲ့ စကားဝိုင်း စတင်နိုင်ပါသည်။ စာအသစ်များ ဒီမှာ ပေါ်လာပါမည်။",
  "เริ่มการสนทนากับลูกค้า ข้อความขาเข้าจะแสดงที่นี่"
 ],
 "select": [
  "Select a conversation",
  "စကားဝိုင်း ရွေးပါ",
  "เลือกการสนทนา"
 ],
 "selectHint": [
  "Messages and customer details appear here.",
  "စာများနှင့် Customer အချက်အလက်ကို ဒီမှာ ကြည့်နိုင်သည်။",
  "ข้อความและข้อมูลลูกค้าจะแสดงที่นี่"
 ],
 "noMatch": [
  "No matching conversations",
  "ကိုက်ညီသော စကားဝိုင်း မတွေ့ပါ",
  "ไม่พบการสนทนา"
 ],
 "clear": [
  "Clear filters",
  "Filter များ ရှင်းရန်",
  "ล้างตัวกรอง"
 ],
 "error": [
  "Unable to complete this action. Please try again.",
  "လုပ်ဆောင်မှု မအောင်မြင်ပါ။ ပြန်ကြိုးစားပါ။",
  "ไม่สามารถดำเนินการได้ กรุณาลองอีกครั้ง"
 ],
 "stale": [
  "Connection interrupted. Showing the last loaded messages.",
  "ချိတ်ဆက်မှု ပြတ်တောက်နေသည်။ နောက်ဆုံးဖတ်ထားသော စာများကို ပြထားသည်။",
  "การเชื่อมต่อขัดข้อง กำลังแสดงข้อความที่โหลดล่าสุด"
 ],
 "retry": [
  "Retry",
  "ပြန်ပို့ရန်",
  "ลองอีกครั้ง"
 ],
 "failed": [
  "Failed to send. Your draft is kept.",
  "ပို့မရသေးပါ။ ရေးထားသောစာ သိမ်းထားပါသည်။",
  "ส่งไม่สำเร็จ เก็บฉบับร่างไว้แล้ว"
 ],
 "older": [
  "Load earlier messages",
  "အရင်စာများ ဖတ်ရန်",
  "โหลดข้อความก่อนหน้า"
 ],
 "newMessages": [
  "New Messages",
  "စာအသစ်များ",
  "ข้อความใหม่"
 ],
 "quoted": [
  "Replying to",
  "ပြန်ဖြေမည့်စာ",
  "ตอบกลับ"
 ],
 "replyAction": [
  "Reply to this message",
  "ဒီစာကို ပြန်ဖြေရန်",
  "ตอบข้อความนี้"
 ],
 "updated": [
  "Conversation updated",
  "စကားဝိုင်း ပြင်ဆင်ပြီး",
  "อัปเดตการสนทนาแล้ว"
 ],
 "conflict": [
  "Conversation changed elsewhere. Refreshed; review and try again.",
  "အခြားနေရာမှ ပြင်ထားပါသည်။ ပြန်ဖတ်ပြီး စစ်ဆေးပါ။",
  "การสนทนาถูกเปลี่ยนจากที่อื่น รีเฟรชแล้ว โปรดตรวจสอบอีกครั้ง"
 ],
 "subject": [
  "Subject",
  "အကြောင်းအရာ",
  "หัวข้อ"
 ],
 "message": [
  "Message",
  "စာသား",
  "ข้อความ"
 ],
 "related": [
  "Related Record",
  "ဆက်စပ် မှတ်တမ်း",
  "รายการที่เกี่ยวข้อง"
 ],
 "noRelated": [
  "No related record",
  "ဆက်စပ် မှတ်တမ်းမရွေးပါ",
  "ไม่มีรายการเกี่ยวข้อง"
 ],
 "findCustomer": [
  "Search customer name or email…",
  "Customer အမည် သို့မဟုတ် Email ရှာရန်…",
  "ค้นหาชื่อลูกค้าหรืออีเมล…"
 ],
 "chooseCustomer": [
  "Select a customer",
  "Customer ရွေးပါ",
  "เลือกลูกค้า"
 ],
 "start": [
  "Start Conversation",
  "စကားဝိုင်း စတင်ရန်",
  "เริ่มการสนทนา"
 ],
 "newHint": [
  "Contact a customer and link a payment, job or subscription.",
  "Customer ကို စာပို့ပြီး ငွေပေးချေမှု၊ Job၊ ပက်ကေ့ချ် ချိတ်ထားနိုင်သည်။",
  "ติดต่อลูกค้าและเชื่อมการชำระเงิน งาน หรือสมาชิก"
 ],
 "validation": [
  "Review the highlighted fields.",
  "ဖော်ပြထားသော အချက်များကို ပြန်စစ်ပါ။",
  "ตรวจสอบช่องที่ระบุ"
 ],
 "required": [
  "This field is required.",
  "ဒီအချက် ဖြည့်ရန် လိုအပ်ပါသည်။",
  "กรุณากรอกช่องนี้"
 ],
 "fileError": [
  "Choose up to 5 PNG, JPG, WEBP or PDF files, no larger than 10 MiB each.",
  "PNG၊ JPG၊ WEBP၊ PDF ၅ ဖိုင်အထိသာ၊ တစ်ဖိုင် 10 MiB ထက်မကျော်ရပါ။",
  "เลือก PNG, JPG, WEBP หรือ PDF ไม่เกิน 5 ไฟล์ ขนาดไม่เกินไฟล์ละ 10 MiB"
 ],
 "created": [
  "Conversation created",
  "စကားဝိုင်း ဖန်တီးပြီး",
  "สร้างการสนทนาแล้ว"
 ],
 "integration": [
  "Messages are saved here. The customer Support screen will be connected in the customer-side phase.",
  "စာများကို ဒီမှာ သိမ်းထားပါသည်။ Customer Support မျက်နှာပြင်ကို Customer Side အဆင့်မှာ ဆက်ချိတ်ပါမည်။",
  "ข้อความถูกบันทึกที่นี่ หน้าช่วยเหลือลูกค้าจะเชื่อมต่อในขั้นฝั่งลูกค้า"
 ],
 "resolve": [
  "Resolve conversation",
  "ဖြေရှင်းပြီး သတ်မှတ်ရန်",
  "ทำเครื่องหมายว่าแก้ไขแล้ว"
 ],
 "reopen": [
  "Reopen conversation",
  "ပြန်ဖွင့်ရန်",
  "เปิดการสนทนาอีกครั้ง"
 ],
 "resolveHint": [
  "Mark this conversation as resolved? The customer can reopen it by replying.",
  "ဒီစကားဝိုင်းကို ဖြေရှင်းပြီးအဖြစ် သတ်မှတ်မလား။ Customer ပြန်စာပို့ရင် ပြန်ဖွင့်ပါမည်။",
  "ทำเครื่องหมายว่าแก้ไขแล้วหรือไม่ ลูกค้าสามารถเปิดอีกครั้งโดยตอบกลับ"
 ],
 "confirm": [
  "Confirm",
  "အတည်ပြုရန်",
  "ยืนยัน"
 ],
 "events": [
  "Conversation updated by",
  "ပြင်ဆင်သူ",
  "อัปเดตการสนทนาโดย"
 ],
 "copied": [
  "Copied",
  "ကူးပြီး",
  "คัดลอกแล้ว"
 ],
 "copy": [
  "Copy",
  "ကူးရန်",
  "คัดลอก"
 ],
 "more": [
  "Conversation options",
  "စကားဝိုင်း ရွေးချယ်စရာများ",
  "ตัวเลือกการสนทนา"
 ],
 "pin": [
  "Toggle customer context",
  "Customer အချက်အလက် ဖွင့်/ပိတ်",
  "เปิด/ปิดข้อมูลลูกค้า"
 ],
 "bold": [
  "Bold",
  "စာလုံးထူ",
  "ตัวหนา"
 ],
 "italic": [
  "Italic",
  "စာလုံးစောင်း",
  "ตัวเอียง"
 ],
 "bullet": [
  "Bullet list",
  "စာရင်း",
  "รายการ"
 ],
 "emoji": [
  "Emoji",
  "အီမိုဂျီ",
  "อีโมจิ"
 ],
 "noteAttachment": [
  "Internal attachment",
  "Admin သီးသန့်ဖိုင်",
  "ไฟล์แนบภายใน"
 ],
 "active": [
  "Active",
  "အသုံးပြုနိုင်",
  "ใช้งานอยู่"
 ],
 "suspended": [
  "Suspended",
  "ခေတ္တရပ်ထား",
  "ถูกระงับ"
 ],
 "archived": [
  "Archived",
  "သိမ်းထား",
  "เก็บถาวร"
 ],
 "expired": [
  "Expired",
  "သက်တမ်းကုန်",
  "หมดอายุ"
 ],
 "upcoming": [
  "Upcoming",
  "စတင်ရန်ရှိ",
  "กำลังจะเริ่ม"
 ],
 "ended": [
  "Ended",
  "ပြီးဆုံး",
  "สิ้นสุด"
 ],
 "cancelled": [
  "Cancelled",
  "ပယ်ဖျက်ပြီး",
  "ยกเลิกแล้ว"
 ],
 "approved": [
  "Approved",
  "အတည်ပြုပြီး",
  "อนุมัติแล้ว"
 ],
 "pending_review": [
  "Pending Review",
  "စစ်ဆေးရန် စောင့်နေ",
  "รอตรวจสอบ"
 ],
 "under_review": [
  "Under Review",
  "စစ်ဆေးနေဆဲ",
  "กำลังตรวจสอบ"
 ],
 "needs_information": [
  "Needs Information",
  "အချက်အလက် လိုအပ်",
  "ต้องการข้อมูล"
 ],
 "awaiting_payment": [
  "Awaiting Payment",
  "ငွေပေးချေရန် စောင့်နေ",
  "รอชำระเงิน"
 ],
 "rejected": [
  "Rejected",
  "ငြင်းပယ်ပြီး",
  "ปฏิเสธ"
 ],
 "completed": [
  "Completed",
  "ပြီးဆုံး",
  "เสร็จแล้ว"
 ],
 "running": [
  "Running",
  "လုပ်ဆောင်နေ",
  "กำลังทำงาน"
 ],
 "queued": [
  "Queued",
  "အလှည့် စောင့်နေ",
  "อยู่ในคิว"
 ],
 "failedStatus": [
  "Failed",
  "မအောင်မြင်",
  "ล้มเหลว"
 ],
 "showing": [
  "Showing",
  "ပြသထားမှု",
  "กำลังแสดง"
 ],
 "of": [
  "of",
  "/",
  "จาก"
 ],
 "conversations": [
  "conversations",
  "စကားဝိုင်း",
  "การสนทนา"
 ],
 "previous": [
  "Previous page",
  "ရှေ့စာမျက်နှာ",
  "หน้าก่อนหน้า"
 ],
 "next": [
  "Next page",
  "နောက်စာမျက်နှာ",
  "หน้าถัดไป"
 ],
 "clearReply": [
  "Cancel reply",
  "ပြန်ဖြေမှု ပယ်ဖျက်ရန်",
  "ยกเลิกการตอบ"
 ],
 "draft": [
  "Draft",
  "မူကြမ်း",
  "ฉบับร่าง"
 ],
 "relatedHint": [
  "Only this customer’s records are available.",
  "ဒီ Customer ပိုင် မှတ်တမ်းများသာ ရွေးနိုင်သည်။",
  "เลือกได้เฉพาะรายการของลูกค้ารายนี้"
 ]
};
Object.assign(words,{loadingMore:['Loading more conversations…','နောက်ထပ် စကားဝိုင်းများ ဖတ်နေသည်…','กำลังโหลดการสนทนาเพิ่มเติม…'],scrollMore:['Scroll for more','နောက်ထပ်ကြည့်ရန် အောက်ကိုဆွဲပါ','เลื่อนลงเพื่อดูเพิ่มเติม'],allLoaded:['All conversations loaded','စကားဝိုင်းအားလုံး ပြထားပါသည်','โหลดการสนทนาครบแล้ว']});
Object.assign(words,{previewSent:['Sent:','ပို့ထား:','ส่ง:'],previewReceived:['Received:','ရရှိ:','รับ:'],noMessages:['No messages yet','စာမရှိသေးပါ','ยังไม่มีข้อความ']});
Object.assign(words,{messageDeleted:['This message was deleted.','ဤစာကို ဖျက်လိုက်ပါပြီ။','ข้อความนี้ถูกลบแล้ว'],deleteMessage:['Delete message','စာဖျက်ရန်','ลบข้อความ'],deleteMessageHint:['Delete this message for everyone? A deleted-message placeholder will remain.','ဤစာကို နှစ်ဖက်စလုံးမှ ဖျက်မည်လား။ ဖျက်ထားကြောင်း စာသားသာ ကျန်ရှိမည်။','ลบข้อความนี้สำหรับทุกคน? จะแสดงว่าข้อความถูกลปแล้ว']});
Object.assign(words,{"loadingOlder":["Loading earlier messages","ယခင်စာများ ဖွင့်နေသည်","กำลังโหลดข้อความก่อนหน้า"],"historyStart":["Start of this conversation","စကားဝိုင်း၏ အစ","จุดเริ่มต้นของการสนทนา"],"jumpLatest":["Jump to latest","နောက်ဆုံးစာသို့","ไปยังข้อความล่าสุด"],"jumpToOriginal":["Go to original message","မူရင်းစာသို့ သွားရန်","ไปยังข้อความต้นฉบับ"],"edited":["Edited","ပြင်ထားသည်","แก้ไขแล้ว"],"editMessage":["Edit message","စာပြင်ရန်","แก้ไขข้อความ"],"saveEdit":["Save","သိမ်းရန်","บันทึก"],"cancelEdit":["Cancel","မလုပ်တော့ပါ","ยกเลิก"],"editHint":["Enter to save · Esc to cancel","Enter ဖြင့် သိမ်း · Esc ဖြင့် ပယ်ဖျက်","Enter เพื่อบันทึก · Esc เพื่อยกเลิก"],"editEmpty":["Enter a message before saving.","မသိမ်းမီ စာရိုက်ထည့်ပါ။","กรอกข้อความก่อนบันทึก"],"messageEdited":["Message updated","စာ ပြင်ပြီးပါပြီ","แก้ไขข้อความแล้ว"],"messageUnavailable":["This message is no longer available.","ဤစာကို အသုံးပြု၍မရတော့ပါ။","ข้อความนี้ไม่พร้อมใช้งานแล้ว"]});
export function useSupportText(){const {locale}=usePage<SharedData>().props;const index=locale==='my'?1:locale==='th'?2:0;const language=locale==='my'?'my-MM':locale==='th'?'th-TH':'en';const t=(key:string)=>words[key]?.[index]??key.replaceAll('_',' ');const number=(n:number)=>new Intl.NumberFormat(language,{maximumFractionDigits:2}).format(n);const bytes=(n:number)=>{const i=n>=1024**3?3:n>=1024**2?2:n>=1024?1:0;return number(n/1024**i)+' '+['B','KiB','MiB','GiB'][i];};return{t,locale,language,number,bytes};}
