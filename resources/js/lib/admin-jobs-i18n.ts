import {usePage} from '@inertiajs/react';
import type {SharedData} from '@/types';
const words:Record<string,readonly [string,string,string]>={
"invalidRange": ["Choose a valid start and end date.","စတင်ရက်နှင့် ပြီးဆုံးရက်ကို မှန်ကန်စွာ ရွေးပါ။","เลือกวันที่เริ่มต้นและสิ้นสุดให้ถูกต้อง"],
"workerIssue": ["The automatic worker could not start. Check Worker Health before retrying.","အလိုအလျောက် Worker စတင်၍မရပါ။ Worker အခြေအနေကို စစ်ဆေးပါ။","เริ่มตัวประมวลผลอัตโนมัติไม่ได้ โปรดตรวจสอบสถานะตัวประมวลผล"],
 "fileExpired": ["Result files have expired. Job and usage history are preserved.","ရလဒ်ဖိုင်များ သက်တမ်းကုန်သွားပါပြီ။ လုပ်ငန်းနှင့် အသုံးပြုမှုမှတ်တမ်းများ ကျန်ရှိပါသည်။","ไฟล์ผลลัพธ์หมดอายุแล้ว ประวัติงานและการใช้งานยังคงอยู่"],
 "title": [
  "Job Monitor",
  "လုပ်ဆောင်မှု စောင့်ကြည့်ရန်",
  "ติดตามงาน"
 ],
 "description": [
  "Monitor customer video jobs, check progress, and resolve processing issues.",
  "အသုံးပြုသူများ၏ ဗီဒီယိုလုပ်ငန်းစဉ်၊ တိုးတက်မှုနှင့် အမှားများကို စောင့်ကြည့်စီမံပါ။",
  "ติดตามงานวิดีโอของลูกค้า ตรวจสอบความคืบหน้าและแก้ไขปัญหา"
 ],
 "all": [
  "All Jobs",
  "လုပ်ငန်းအားလုံး",
  "งานทั้งหมด"
 ],
 "queued": [
  "Queued",
  "စောင့်ဆိုင်းနေသည်",
  "อยู่ในคิว"
 ],
 "running": [
  "Running",
  "လုပ်ဆောင်နေသည်",
  "กำลังทำงาน"
 ],
 "completed": [
  "Completed",
  "ပြီးစီးပြီး",
  "เสร็จสมบูรณ์"
 ],
 "failed": [
  "Failed",
  "မအောင်မြင်ပါ",
  "ไม่สำเร็จ"
 ],
 "cancelled": [
  "Cancelled",
  "ပယ်ဖျက်ပြီး",
  "ยกเลิกแล้ว"
 ],
 "toReview": [
  "To Review",
  "Admin စစ်ဆေးရန်",
  "ต้องตรวจสอบ"
 ],
 "toReviewHint": [
  "Failed jobs needing administrator review",
  "Admin စစ်ဆေးရန်လိုသော မအောင်မြင်သည့်လုပ်ငန်းများ",
  "งานไม่สำเร็จที่ต้องให้แอดมินตรวจสอบ"
 ],
 "retry_waiting": [
  "Retry Scheduled",
  "ပြန်လုပ်ရန် စောင့်နေသည်",
  "กำหนดลองใหม่"
 ],
 "awaiting_review": [
  "Awaiting Review",
  "စာမူစစ်ဆေးရန် စောင့်နေသည်",
  "รอตรวจทานบท"
 ],
 "stopping": [
  "Cancellation requested",
  "ရပ်တန့်ရန် တောင်းဆိုပြီး",
  "ร้องขอการยกเลิกแล้ว"
 ],
 "search": [
  "Search customer, email, project or job ID…",
  "အမည်၊ အီးမေးလ်၊ ပရောဂျက် သို့မဟုတ် Job ID ရှာရန်…",
  "ค้นหาลูกค้า อีเมล โปรเจกต์ หรือรหัสงาน…"
 ],
 "workerHealth": [
  "Worker Health",
  "Worker အခြေအနေ",
  "สถานะเวิร์กเกอร์"
 ],
 "refresh": [
  "Refresh",
  "ပြန်ဖတ်ရန်",
  "รีเฟรช"
 ],
 "clear": [
  "Clear Filters",
  "စစ်ထုတ်မှု ရှင်းရန်",
  "ล้างตัวกรอง"
 ],
 "refreshed": [
  "Jobs refreshed.",
  "လုပ်ငန်းစာရင်း ပြန်ဖတ်ပြီးပါပြီ။",
  "รีเฟรชรายการงานแล้ว"
 ],
 "allStatuses": [
  "Status: All",
  "အခြေအနေအားလုံး",
  "สถานะ: ทั้งหมด"
 ],
 "allTypes": [
  "Type: All",
  "အမျိုးအစားအားလုံး",
  "ประเภท: ทั้งหมด"
 ],
 "status": [
  "Status",
  "အခြေအနေ",
  "สถานะ"
 ],
 "type": [
  "Type",
  "အမျိုးအစား",
  "ประเภท"
 ],
 "allDates": [
  "All Dates",
  "ရက်စွဲအားလုံး",
  "ทุกวันที่"
 ],
 "today": [
  "Today",
  "ယနေ့",
  "วันนี้"
 ],
 "last7": [
  "Last 7 Days",
  "နောက်ဆုံး ၇ ရက်",
  "7 วันล่าสุด"
 ],
 "last30": [
  "Last 30 Days",
  "နောက်ဆုံး ၃၀ ရက်",
  "30 วันล่าสุด"
 ],
 "custom": [
  "Custom Dates",
  "ရက်စွဲ သတ်မှတ်ရန်",
  "กำหนดวันที่"
 ],
 "from": [
  "From",
  "မှ",
  "จาก"
 ],
 "to": [
  "To",
  "အထိ",
  "ถึง"
 ],
 "newest": [
  "Newest",
  "အသစ်ဆုံး",
  "ใหม่สุด"
 ],
 "oldest": [
  "Oldest",
  "အဟောင်းဆုံး",
  "เก่าสุด"
 ],
 "longest": [
  "Longest running",
  "အချိန်အကြာဆုံး",
  "ใช้เวลานานสุด"
 ],
 "customer": [
  "Customer",
  "အသုံးပြုသူ",
  "ลูกค้า"
 ],
 "project": [
  "Project / Job",
  "ပရောဂျက် / လုပ်ငန်း",
  "โปรเจกต์ / งาน"
 ],
 "progress": [
  "Progress",
  "ပြီးစီးမှု",
  "ความคืบหน้า"
 ],
 "startedDuration": [
  "Started / Duration",
  "စတင်ချိန် / ကြာချိန်",
  "เริ่ม / ระยะเวลา"
 ],
 "actions": [
  "Actions",
  "လုပ်ဆောင်ရန်",
  "การดำเนินการ"
 ],
 "import": [
  "Import",
  "ဗီဒီယို ယူရန်",
  "นำเข้า"
 ],
 "validation": [
  "Validate",
  "ဖိုင်စစ်ဆေးရန်",
  "ตรวจสอบไฟล์"
 ],
 "analysis": [
  "Analyze",
  "စိစစ်ရန်",
  "วิเคราะห์"
 ],
 "generate": [
  "Generate",
  "ထုတ်လုပ်ရန်",
  "สร้าง"
 ],
 "preview": [
  "Preview",
  "နမူနာ",
  "ตัวอย่าง"
 ],
 "waiting": [
  "Waiting for worker",
  "Worker စောင့်နေသည်",
  "รอเวิร์กเกอร์"
 ],
 "retryAt": [
  "Retry at",
  "ပြန်စမည့်အချိန်",
  "ลองใหม่เวลา"
 ],
 "reviewWait": [
  "Waiting for customer review",
  "အသုံးပြုသူ စာမူစစ်ဆေးရန် စောင့်နေသည်",
  "รอลูกค้าตรวจทาน"
 ],
 "view": [
  "View Details",
  "အသေးစိတ် ကြည့်ရန်",
  "ดูรายละเอียด"
 ],
 "copy": [
  "Copy Job ID",
  "Job ID ကူးရန်",
  "คัดลอกรหัสงาน"
 ],
 "copied": [
  "Copied",
  "ကူးပြီးပါပြီ",
  "คัดลอกแล้ว"
 ],
 "download": [
  "Download MP4",
  "MP4 ထုတ်ယူရန်",
  "ดาวน์โหลด MP4"
 ],
 "retry": [
  "Retry",
  "ပြန်လုပ်ရန်",
  "ลองใหม่"
 ],
 "cancel": [
  "Cancel",
  "ပယ်ဖျက်ရန်",
  "ยกเลิก"
 ],
 "close": [
  "Close",
  "ပိတ်ရန်",
  "ปิด"
 ],
 "details": [
  "Job Details",
  "လုပ်ငန်း အသေးစိတ်",
  "รายละเอียดงาน"
 ],
 "select": [
  "Select job",
  "လုပ်ငန်း ရွေးရန်",
  "เลือกงาน"
 ],
 "selectPage": [
  "Select this page",
  "ယခုစာမျက်နှာ ရွေးရန်",
  "เลือกหน้านี้"
 ],
 "selected": [
  "jobs selected",
  "ခု ရွေးထားသည်",
  "งานที่เลือก"
 ],
 "copySelected": [
  "Copy IDs",
  "ID များ ကူးရန်",
  "คัดลอกรหัส"
 ],
 "clearSelection": [
  "Clear selection",
  "ရွေးချယ်မှု ပယ်ရန်",
  "ล้างการเลือก"
 ],
 "showing": [
  "Showing",
  "ပြသထားသည်",
  "แสดง"
 ],
 "of": [
  "of",
  "/",
  "จาก"
 ],
 "jobs": [
  "jobs",
  "လုပ်ငန်း",
  "งาน"
 ],
 "previous": [
  "Previous",
  "ရှေ့စာမျက်နှာ",
  "ก่อนหน้า"
 ],
 "next": [
  "Next",
  "နောက်စာမျက်နှာ",
  "ถัดไป"
 ],
 "empty": [
  "No jobs match these filters.",
  "ကိုက်ညီသော လုပ်ငန်း မရှိပါ။",
  "ไม่พบงานที่ตรงกับตัวกรอง"
 ],
 "error": [
  "Unable to load jobs. Please try again.",
  "လုပ်ငန်းစာရင်း မဖတ်နိုင်ပါ။ ပြန်ကြိုးစားပါ။",
  "โหลดงานไม่ได้ กรุณาลองใหม่"
 ],
 "live": [
  "Live updates",
  "လက်ရှိအခြေအနေ ပြန်ဖတ်နေသည်",
  "อัปเดตสด"
 ],
 "disconnected": [
  "Live updates paused. Reconnecting…",
  "အခြေအနေပြန်ဖတ်မှု ယာယီပြတ်နေသည်။ ပြန်ချိတ်နေသည်…",
  "หยุดอัปเดตชั่วคราว กำลังเชื่อมต่อใหม่…"
 ],
 "auto": [
  "Processing starts automatically when jobs are queued.",
  "လုပ်ငန်းအသစ်ရှိလျှင် Worker အလိုအလျောက် စတင်ပါမည်။",
  "เริ่มประมวลผลอัตโนมัติเมื่อมีงานในคิว"
 ],
 "manual": [
  "No worker is reporting. Queued jobs are waiting.",
  "Worker မချိတ်ဆက်ထားပါ။ လုပ်ငန်းများ စောင့်နေသည်။",
  "ไม่มีเวิร์กเกอร์รายงาน งานกำลังรอในคิว"
 ],
 "retryTitle": [
  "Retry Job",
  "လုပ်ငန်း ပြန်စရန်",
  "ลองงานใหม่"
 ],
 "cancelTitle": [
  "Cancel Job",
  "လုပ်ငန်း ပယ်ဖျက်ရန်",
  "ยกเลิกงาน"
 ],
 "retryHint": [
  "Retry the failed step using the current package limits. Completed stages can resume from saved work.",
  "လက်ရှိ package သတ်မှတ်ချက်ဖြင့် ပြန်လုပ်မည်။ သိမ်းထားသော ပြီးစီးမှုမှ ဆက်လုပ်နိုင်သည်။",
  "ลองขั้นตอนที่ล้มเหลวใหม่ตามขีดจำกัดแพ็กเกจปัจจุบัน โดยใช้ความคืบหน้าที่บันทึกไว้"
 ],
 "cancelHint": [
  "Running work stops after the worker acknowledges cancellation. Unused reservations are then released.",
  "လုပ်ဆောင်ဆဲအလုပ်ကို Worker က အတည်ပြုပြီးမှ ရပ်မည်။ မသုံးရသေးသော reserve ကို ပြန်လွှတ်မည်။",
  "งานที่กำลังทำจะหยุดเมื่อเวิร์กเกอร์รับทราบ จากนั้นคืนยอดสำรองที่ไม่ได้ใช้"
 ],
 "reason": [
  "Reason",
  "အကြောင်းပြချက်",
  "เหตุผล"
 ],
 "reasonRequired": [
  "Enter a reason (at least 3 characters).",
  "အကြောင်းပြချက် အနည်းဆုံး စာလုံး ၃ လုံး ထည့်ပါ။",
  "ระบุเหตุผลอย่างน้อย 3 ตัวอักษร"
 ],
 "confirmRetry": [
  "Confirm Retry",
  "ပြန်လုပ်ရန် အတည်ပြုပါ",
  "ยืนยันลองใหม่"
 ],
 "confirmCancel": [
  "Confirm Cancellation",
  "ပယ်ဖျက်ရန် အတည်ပြုပါ",
  "ยืนยันยกเลิก"
 ],
 "retrySaved": [
  "Job queued for retry.",
  "ပြန်လုပ်ရန် စောင့်စာရင်းသို့ ထည့်ပြီးပါပြီ။",
  "นำงานเข้าคิวเพื่อลองใหม่แล้ว"
 ],
 "cancelSaved": [
  "Cancellation recorded.",
  "ရပ်တန့်ရန် တောင်းဆိုပြီးပါပြီ။",
  "บันทึกการยกเลิกแล้ว"
 ],
 "stale": [
  "This job changed. Refresh and review its current status.",
  "လုပ်ငန်းအခြေအနေ ပြောင်းသွားပါပြီ။ ပြန်ဖတ်ပြီး စစ်ဆေးပါ။",
  "งานเปลี่ยนแปลงแล้ว รีเฟรชเพื่อตรวจสอบสถานะล่าสุด"
 ],
 "source": [
  "Source",
  "မူရင်း",
  "ต้นฉบับ"
 ],
 "local": [
  "Local Video",
  "စက်မှ ဗီဒီယို",
  "วิดีโอในเครื่อง"
 ],
 "youtube": [
  "YouTube",
  "YouTube",
  "YouTube"
 ],
 "mode": [
  "Mode",
  "လုပ်ဆောင်ပုံ",
  "โหมด"
 ],
 "output": [
  "Output",
  "ထုတ်ယူမည့်ဖိုင်",
  "ผลลัพธ์"
 ],
 "subs": [
  "Subtitles Only",
  "စာတန်းထိုးသာ",
  "คำบรรยายเท่านั้น"
 ],
 "dub": [
  "Full Burmese Dub",
  "မြန်မာအသံ ပြောင်းရန်",
  "พากย์ภาษาพม่า"
 ],
 "attempt": [
  "Worker attempts",
  "Worker ကြိုးစားမှု",
  "จำนวนครั้งของเวิร์กเกอร์"
 ],
 "started": [
  "Started",
  "စတင်ချိန်",
  "เริ่มเมื่อ"
 ],
 "finished": [
  "Finished",
  "ပြီးဆုံးချိန်",
  "เสร็จเมื่อ"
 ],
 "duration": [
  "Duration",
  "ကြာချိန်",
  "ระยะเวลา"
 ],
 "stages": [
  "Stage Checkpoints",
  "လုပ်ဆောင်မှု အဆင့်များ",
  "จุดตรวจขั้นตอน"
 ],
 "history": [
  "Activity History",
  "လုပ်ဆောင်မှု မှတ်တမ်း",
  "ประวัติกิจกรรม"
 ],
 "noHistory": [
  "Earlier event timestamps were not recorded.",
  "ယခင်လုပ်ဆောင်မှု အချိန်မှတ်တမ်း မရရှိပါ။",
  "ไม่มีบันทึกเวลาของเหตุการณ์ก่อนหน้า"
 ],
 "usage": [
  "Allowance & Usage",
  "သုံးခွင့်နှင့် အသုံးပြုမှု",
  "โควตาและการใช้งาน"
 ],
 "reserved": [
  "Reserved",
  "ကြိုတင်ဖယ်ထားမှု",
  "สำรอง"
 ],
 "used": [
  "Charged",
  "သုံးပြီး",
  "หักแล้ว"
 ],
 "videos": [
  "videos",
  "ဗီဒီယို",
  "วิดีโอ"
 ],
 "minutes": [
  "min",
  "မိနစ်",
  "นาที"
 ],
 "period": [
  "Period",
  "ကာလ",
  "รอบ"
 ],
 "ledger": [
  "View Usage Ledger",
  "Usage စာရင်း ကြည့်ရန်",
  "ดูบัญชีการใช้งาน"
 ],
 "noCharge": [
  "No export allowance was recorded for this step.",
  "ဒီအဆင့်အတွက် export နုတ်ယူမှု မရှိပါ။",
  "ไม่มีการบันทึกโควตาส่งออกสำหรับขั้นตอนนี้"
 ],
 "results": [
  "Result Files",
  "ရလဒ်ဖိုင်များ",
  "ไฟล์ผลลัพธ์"
 ],
 "noResult": [
  "No final export is available for this job.",
  "ဒီလုပ်ငန်းအတွက် နောက်ဆုံးရလဒ်ဖိုင် မရရှိသေးပါ။",
  "ยังไม่มีไฟล์ส่งออกสุดท้ายสำหรับงานนี้"
 ],
 "customerLink": [
  "View Customer",
  "အသုံးပြုသူ ကြည့်ရန်",
  "ดูลูกค้า"
 ],
 "historical": [
  "Historical job — actions are unavailable.",
  "ယခင်လုပ်ငန်းဖြစ်၍ ပြန်စီမံ၍ မရပါ။",
  "งานในอดีต ไม่สามารถดำเนินการได้"
 ],
 "accountBlocked": [
  "This customer needs active account and subscription access before retrying.",
  "ပြန်လုပ်ရန် အကောင့်နှင့် subscription အသက်ဝင်နေရပါမည်။",
  "ต้องมีบัญชีและการสมัครที่ใช้งานอยู่ก่อนลองใหม่"
 ],
 "system": [
  "System",
  "စနစ်",
  "ระบบ"
 ],
 "errorDetails": [
  "Processing Issue",
  "လုပ်ဆောင်မှု အမှား",
  "ปัญหาการประมวลผล"
 ],
 "on": [
  "On",
  "ဖွင့်",
  "เปิด"
 ],
 "off": [
  "Off",
  "ပိတ်",
  "ปิด"
 ]
};
export function useAdminJobsText(){const {locale}=usePage<SharedData>().props;return {locale,t:(key:string)=>words[key]?.[locale==='my'?1:locale==='th'?2:0]??key};}
