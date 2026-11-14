import {usePage} from '@inertiajs/react';
import type {SharedData} from '@/types';
const words:Record<string,readonly [string,string,string]>={
 "estimate": [
  "Estimated charge per successful export",
  "အောင်မြင်စွာ ထုတ်ယူမှုတစ်ကြိမ်အတွက် ခန့်မှန်းနုတ်ယူမှု",
  "ยอดหักโดยประมาณต่อการส่งออกสำเร็จ"
 ],
 "eachExport": [
  "A new export uses source-video time; the same project counts as one video per period. Failed work releases its reservation.",
  "အသစ်ထုတ်ယူတိုင်း မူရင်းဗီဒီယိုကြာချိန်ကို နုတ်ပါမည်။ ကာလတစ်ခုအတွင်း ပရောဂျက်တူကို ဗီဒီယိုတစ်ခုသာ ရေတွက်သည်။ မအောင်မြင်လျှင် reserve ကို ပြန်လွှတ်သည်။",
  "การส่งออกใหม่ใช้เวลาต้นฉบับ โปรเจกต์เดิมนับหนึ่งวิดีโอต่อช่วงเวลา งานล้มเหลวคืนการสำรอง"
 ],
 "title": [
  "Usage Ledger",
  "အသုံးပြုမှု စာရင်း",
  "บัญชีการใช้งาน"
 ],
 "description": [
  "Track processing usage, stored files and customer allowances.",
  "လုပ်ဆောင်မှု၊ သိမ်းထားသောဖိုင်နှင့် သုံးစွဲခွင့်များကို စောင့်ကြည့်ပါ။",
  "ติดตามการประมวลผล ไฟล์ที่จัดเก็บ และโควตาลูกค้า"
 ],
 "export": [
  "Export CSV",
  "CSV ထုတ်ယူရန်",
  "ส่งออก CSV"
 ],
 "refresh": [
  "Refresh",
  "ပြန်ဖတ်ရန်",
  "รีเฟรช"
 ],
 "refreshed": [
  "Usage records refreshed.",
  "အသုံးပြုမှု စာရင်း ပြန်ဖတ်ပြီးပါပြီ။",
  "รีเฟรชรายการแล้ว"
 ],
 "processingUsed": [
  "Processing Used",
  "သုံးပြီး လုပ်ဆောင်ချိန်",
  "ประมวลผลที่ใช้"
 ],
 "videosCounted": [
  "Videos Counted",
  "နုတ်ယူပြီး ဗီဒီယို",
  "วิดีโอที่นับ"
 ],
 "processingReserved": [
  "Processing Reserved",
  "ကြိုတင်ဖယ်ထားချိန်",
  "เวลาที่สำรอง"
 ],
 "storageInUse": [
  "Storage In Use",
  "လက်ရှိ သိမ်းဆည်းမှု",
  "พื้นที่ที่ใช้อยู่"
 ],
 "selected": [
  "Net charged usage · selected filters",
  "အမှန်နုတ်ယူမှု · ရွေးထားသည့်စာရင်း",
  "ยอดใช้สุทธิ · ตามตัวกรอง"
 ],
 "current": [
  "Current",
  "လက်ရှိ",
  "ปัจจุบัน"
 ],
 "search": [
  "Search customer, email, project or usage ID…",
  "အမည်၊ အီးမေးလ်၊ ပရောဂျက်၊ Usage ID ရှာရန်…",
  "ค้นหาลูกค้า อีเมล โปรเจกต์ หรือรหัสการใช้งาน…"
 ],
 "allPackages": [
  "Package: All",
  "ပက်ကေ့ချ် အားလုံး",
  "แพ็กเกจ: ทั้งหมด"
 ],
 "allTypes": [
  "Usage Type: All",
  "အသုံးပြုမှု အားလုံး",
  "ประเภท: ทั้งหมด"
 ],
 "allStatuses": [
  "Status: All",
  "အခြေအနေ အားလုံး",
  "สถานะ: ทั้งหมด"
 ],
 "processing": [
  "Processing",
  "လုပ်ဆောင်မှု",
  "ประมวลผล"
 ],
 "storage": [
  "Storage",
  "သိမ်းဆည်းမှု",
  "พื้นที่จัดเก็บ"
 ],
 "allDates": [
  "All Dates",
  "ရက်စွဲ အားလုံး",
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
  "ตั้งแต่"
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
 "clear": [
  "Clear Filters",
  "စစ်ထုတ်မှု ရှင်းရန်",
  "ล้างตัวกรอง"
 ],
 "customer": [
  "Customer",
  "အသုံးပြုသူ",
  "ลูกค้า"
 ],
 "project": [
  "Project / Reference",
  "ပရောဂျက် / မှတ်တမ်း",
  "โปรเจกต์ / อ้างอิง"
 ],
 "package": [
  "Package / Period",
  "ပက်ကေ့ချ် / ကာလ",
  "แพ็กเกจ / ช่วงเวลา"
 ],
 "usage": [
  "Usage",
  "အသုံးပြုမှု",
  "การใช้งาน"
 ],
 "status": [
  "Status",
  "အခြေအနေ",
  "สถานะ"
 ],
 "recorded": [
  "Recorded",
  "မှတ်တမ်းတင်ချိန်",
  "บันทึกเมื่อ"
 ],
 "actions": [
  "Actions",
  "လုပ်ဆောင်ရန်",
  "การดำเนินการ"
 ],
 "reserved": [
  "Reserved",
  "ကြိုတင်ဖယ်ထား",
  "สำรองแล้ว"
 ],
 "charged": [
  "Charged",
  "နုတ်ယူပြီး",
  "หักแล้ว"
 ],
 "released": [
  "Released",
  "ပြန်လွှတ်ပြီး",
  "คืนการสำรอง"
 ],
 "adjusted": [
  "Adjusted",
  "ပြင်ဆင်ပြီး",
  "ปรับปรุงแล้ว"
 ],
 "stored": [
  "Stored",
  "သိမ်းထားပြီး",
  "จัดเก็บแล้ว"
 ],
 "minutes": [
  "min",
  "မိနစ်",
  "นาที"
 ],
 "seconds": [
  "seconds",
  "စက္ကန့်",
  "วินาที"
 ],
 "videos": [
  "videos",
  "ဗီဒီယို",
  "วิดีโอ"
 ],
 "unmetered": [
  "Legacy · no package",
  "မူလအကောင့် · ပက်ကေ့ချ်မရှိ",
  "บัญชีเดิม · ไม่มีแพ็กเกจ"
 ],
 "empty": [
  "No usage records match these filters.",
  "ဤစစ်ထုတ်မှုနှင့် ကိုက်ညီသည့် မှတ်တမ်းမရှိပါ။",
  "ไม่พบรายการตามตัวกรอง"
 ],
 "retry": [
  "Try Again",
  "ပြန်စမ်းရန်",
  "ลองอีกครั้ง"
 ],
 "error": [
  "Unable to load usage. Please try again.",
  "စာရင်းမဖတ်နိုင်ပါ။ ပြန်စမ်းပါ။",
  "โหลดรายการไม่ได้ กรุณาลองอีกครั้ง"
 ],
 "details": [
  "Usage Details",
  "အသုံးပြုမှု အသေးစိတ်",
  "รายละเอียดการใช้งาน"
 ],
 "view": [
  "View Details",
  "အသေးစိတ် ကြည့်ရန်",
  "ดูรายละเอียด"
 ],
 "adjust": [
  "Correct Charge",
  "နုတ်ယူမှု ပြင်ရန်",
  "แก้ไขยอดใช้"
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
 "save": [
  "Save Correction",
  "ပြင်ဆင်မှု သိမ်းရန်",
  "บันทึกการแก้ไข"
 ],
 "saved": [
  "Usage correction saved.",
  "အသုံးပြုမှု ပြင်ဆင်ပြီးပါပြီ။",
  "บันทึกการแก้ไขแล้ว"
 ],
 "history": [
  "Activity History",
  "လုပ်ဆောင်မှု မှတ်တမ်း",
  "ประวัติกิจกรรม"
 ],
 "original": [
  "Original Charge",
  "မူလနုတ်ယူမှု",
  "ยอดหักเดิม"
 ],
 "returned": [
  "Already Returned",
  "ပြန်ပေးပြီး",
  "คืนแล้ว"
 ],
 "remaining": [
  "Net Charge",
  "ကျန် နုတ်ယူမှု",
  "ยอดหักสุทธิ"
 ],
 "returnSeconds": [
  "Processing Seconds to Return",
  "ပြန်ပေးမည့် လုပ်ဆောင်ချိန် (စက္ကန့်)",
  "วินาทีประมวลผลที่จะคืน"
 ],
 "returnVideos": [
  "Videos to Return",
  "ပြန်ပေးမည့် ဗီဒီယိုအရေအတွက်",
  "จำนวนวิดีโอที่จะคืน"
 ],
 "reason": [
  "Reason",
  "အကြောင်းပြချက်",
  "เหตุผล"
 ],
 "reasonRequired": [
  "Enter a reason for this correction.",
  "ပြင်ဆင်ရသည့် အကြောင်းပြချက် ဖြည့်ပါ။",
  "กรุณาระบุเหตุผล"
 ],
 "amountRequired": [
  "Enter a positive amount within the remaining original charge.",
  "မူလနုတ်ယူမှုထက် မကျော်သော ပမာဏ ဖြည့်ပါ။",
  "ระบุจำนวนที่มากกว่า 0 และไม่เกินยอดหักคงเหลือ"
 ],
 "correctionHint": [
  "Returns allowance to the original period. This is an accounting correction, not a payment refund.",
  "မူလကာလသို့ သုံးစွဲခွင့် ပြန်ထည့်ပေးသည်။ ငွေပြန်အမ်းခြင်း မဟုတ်ပါ။",
  "คืนโควตาให้ช่วงเวลาเดิม เป็นการแก้บัญชีการใช้ ไม่ใช่คืนเงิน"
 ],
 "before": [
  "Before",
  "မပြင်မီ",
  "ก่อน"
 ],
 "after": [
  "After",
  "ပြင်ပြီး",
  "หลัง"
 ],
 "periodBalances": [
  "Original Period Balances",
  "မူလကာလ စာရင်း",
  "ยอดของช่วงเวลาเดิม"
 ],
 "allowance": [
  "Included",
  "ပါဝင်သည့် ခွင့်ပြုမှု",
  "โควตารวม"
 ],
 "used": [
  "Used",
  "အသုံးပြုပြီး",
  "ใช้แล้ว"
 ],
 "available": [
  "Available",
  "သုံးနိုင်သေး",
  "คงเหลือ"
 ],
 "noLimit": [
  "Unlimited",
  "အကန့်အသတ်မရှိ",
  "ไม่จำกัด"
 ],
 "files": [
  "Verified Files",
  "စစ်ဆေးပြီး ဖိုင်များ",
  "ไฟล์ที่ตรวจสอบแล้ว"
 ],
 "noFiles": [
  "No retained files for this project.",
  "ဤပရောဂျက်၏ ဖိုင် မကျန်ရှိပါ။",
  "ไม่มีไฟล์ที่เก็บไว้สำหรับโปรเจกต์นี้"
 ],
 "source": [
  "Source",
  "မူရင်းဖိုင်",
  "ต้นฉบับ"
 ],
 "deleted": [
  "Deleted",
  "ဖျက်ပြီး",
  "ลบแล้ว"
 ],
 "storage_updated": [
  "Storage Updated",
  "ဖိုင်အရွယ်အစား ပြောင်းပြီး",
  "อัปเดตพื้นที่"
 ],
 "currentPeriod": [
  "Current Customer Period",
  "အသုံးပြုသူ၏ လက်ရှိကာလ",
  "ช่วงเวลาปัจจุบันของลูกค้า"
 ],
 "runtime": [
  "Elapsed Run Time",
  "အလုပ်လုပ်ချိန်",
  "เวลาทำงานที่ผ่านไป"
 ],
 "runtimeHint": [
  "Elapsed worker time is not the billed source-video duration.",
  "အလုပ်လုပ်ချိန်သည် နုတ်ယူသော မူရင်းဗီဒီယိုကြာချိန်နှင့် မတူပါ။",
  "เวลาทำงานไม่ใช่ความยาวต้นฉบับที่ใช้หักโควตา"
 ],
 "system": [
  "System",
  "စနစ်",
  "ระบบ"
 ],
 "period": [
  "Period",
  "ကာလ",
  "ช่วงเวลา"
 ],
 "showing": [
  "Showing",
  "ပြသနေသည်",
  "แสดง"
 ],
 "of": [
  "of",
  "/",
  "จาก"
 ],
 "records": [
  "usage records",
  "အသုံးပြုမှု မှတ်တမ်း",
  "รายการใช้งาน"
 ],
 "previous": [
  "Previous Page",
  "ရှေ့စာမျက်နှာ",
  "หน้าก่อน"
 ],
 "next": [
  "Next Page",
  "နောက်စာမျက်နှာ",
  "หน้าถัดไป"
 ],
 "note": [
  "Processing is tracked from this ledger’s activation. Older completed videos are not retroactively charged. Downloads do not consume processing allowance.",
  "ဤစာရင်း စတင်ချိန်မှ အသုံးပြုမှုကို မှတ်တမ်းတင်ပါသည်။ အရင်ထုတ်ပြီးသော ဗီဒီယိုများကို နောက်ကြောင်းပြန် မနုတ်ပါ။ Download လုပ်ခြင်းအတွက် မနုတ်ပါ။",
  "เริ่มนับการประมวลผลเมื่อเปิดใช้บัญชีนี้ ไม่หักย้อนหลังสำหรับวิดีโอเดิม และไม่หักสำหรับดาวน์โหลด"
 ],
 "exportLimit": [
  "Narrow the filters to export up to 10,000 records.",
  "CSV ထုတ်ရန် မှတ်တမ်း ၁၀,၀၀၀ အောက်အထိ စစ်ထုတ်ပါ။",
  "กรองให้เหลือไม่เกิน 10,000 รายการเพื่อส่งออก"
 ],
 "exported": [
  "CSV export downloaded.",
  "CSV ဖိုင် ထုတ်ယူပြီးပါပြီ။",
  "ดาวน์โหลด CSV แล้ว"
 ],
 "loading": [
  "Refreshing…",
  "ပြန်ဖတ်နေသည်…",
  "กำลังรีเฟรช…"
 ],
 "adjustment": [
  "Correction",
  "ပြင်ဆင်မှု",
  "การแก้ไข"
 ],
 "active": [
  "Active",
  "အသုံးပြုနိုင်",
  "ใช้งานอยู่"
 ],
 "expired": [
  "Expired",
  "သက်တမ်းကုန်",
  "หมดอายุ"
 ],
 "ended": [
  "Ended",
  "ရပ်ဆိုင်းပြီး",
  "สิ้นสุด"
 ],
 "upcoming": [
  "Upcoming",
  "စတင်ရန်ရှိ",
  "กำลังจะเริ่ม"
 ],
 "replaced": [
  "Replaced",
  "အစားထိုးပြီး",
  "ถูกแทนที่"
 ],
 "cancelled": [
  "Cancelled",
  "ပယ်ဖျက်ပြီး",
  "ยกเลิกแล้ว"
 ],
 "reconcile": [
  "Imported Storage Balance",
  "လက်ရှိဖိုင် စာရင်းဖွင့်လက်ကျန်",
  "ยอดพื้นที่เริ่มต้น"
 ],
 "allowanceTotalUsed": [
  "Allowance (Total / Used)",
  "သုံးခွင့် (စုစုပေါင်း / သုံးပြီး)",
  "โควตา (ทั้งหมด / ใช้แล้ว)"
 ],
 "recentUsage": [
  "Recent Usage",
  "နောက်ဆုံး အသုံးပြုမှု",
  "การใช้งานล่าสุด"
 ],
 "customers": [
  "customers",
  "အသုံးပြုသူ",
  "ลูกค้า"
 ],
 "customerDetails": [
  "Customer Usage Details",
  "အသုံးပြုသူ၏ Usage အသေးစိတ်",
  "รายละเอียดการใช้งานลูกค้า"
 ],
 "usageHistory": [
  "Usage History",
  "အသုံးပြုမှု မှတ်တမ်း",
  "ประวัติการใช้งาน"
 ],
 "overview": [
  "Overview",
  "ခြုံငုံသုံးသပ်ချက်",
  "ภาพรวม"
 ],
 "successfulOnly": [
  "Successful videos only",
  "အောင်မြင်ပြီးသော ဗီဒီယိုများသာ",
  "เฉพาะวิดีโอที่สำเร็จ"
 ],
 "noSuccessYet": [
  "No successful videos yet",
  "အောင်မြင်ပြီးသော ဗီဒီယို မရှိသေးပါ",
  "ยังไม่มีวิดีโอที่สำเร็จ"
 ],
 "playVideo": [
  "Play video",
  "ဗီဒီယို ကြည့်ရန်",
  "เล่นวิดีโอ"
 ],
 "allSuccessful": [
  "View all successful videos",
  "အောင်မြင်ပြီးသော ဗီဒီယိုအားလုံး ကြည့်ရန်",
  "ดูวิดีโอที่สำเร็จทั้งหมด"
 ],
 "periodCurrent": [
  "Period: Current",
  "ကာလ — လက်ရှိ",
  "รอบ: ปัจจุบัน"
 ],
 "periodAll": [
  "Period: All history",
  "ကာလ — မှတ်တမ်းအားလုံး",
  "รอบ: ประวัติทั้งหมด"
 ],
 "remainingHint": [
  "Remaining = allowance − used − reserved. Stored files still use space across periods.",
  "လက်ကျန် = သုံးခွင့် − သုံးပြီး − Reserved။ ယခင်ကာလကဖိုင်များလည်း storage နေရာ ယူပါသည်။",
  "คงเหลือ = โควตา − ใช้แล้ว − สำรอง ไฟล์จากรอบก่อนยังใช้พื้นที่จัดเก็บ"
 ],
 "summaryHint": [
  "One row per customer. Allowances and remaining use the current package period. Dates filter charged totals, recent videos and history; remaining includes reservations.",
  "အသုံးပြုသူတစ်ဦးလျှင် တစ်ကြောင်းသာ ပြသည်။ သုံးခွင့်နှင့်လက်ကျန်က လက်ရှိ package ကာလအတွက် ဖြစ်သည်။ ရက်စွဲက နုတ်ယူမှု၊ နောက်ဆုံးဗီဒီယိုနှင့် မှတ်တမ်းကို စစ်ထုတ်သည်။ Reserved ကိုလည်း လက်ကျန်မှ နုတ်ထားသည်။",
  "หนึ่งแถวต่อลูกค้า โควตาและคงเหลืออิงรอบปัจจุบัน วันที่กรองยอดหัก วิดีโอล่าสุด และประวัติ โดยหักยอดสำรองจากคงเหลือแล้ว"
 ],
 "historyHint": [
  "All operations, including reservations, failures and corrections, remain here.",
  "လုပ်ဆောင်ဆဲ၊ မအောင်မြင်မှုနှင့် ပြင်ဆင်မှုများ အပါအဝင် မူရင်းမှတ်တမ်းများကို ဒီနေရာတွင် ကြည့်နိုင်သည်။",
  "ดูรายการทั้งหมด รวมงานสำรอง งานไม่สำเร็จ และการแก้ไขได้ที่นี่"
 ],
 "emptyCustomers": [
  "No customers match these filters.",
  "ကိုက်ညီသော အသုံးပြုသူ မရှိပါ။",
  "ไม่พบลูกค้าที่ตรงกับตัวกรอง"
 ],
 "videoUnavailable": [
  "This video is no longer available. Usage history is preserved.",
  "ဒီဗီဒီယိုဖိုင်ကို လက်ရှိမရနိုင်ပါ။ အသုံးပြုမှုမှတ်တမ်းကို ထိန်းသိမ်းထားပါသည်။",
  "วิดีโอไม่พร้อมใช้งานแล้ว แต่ยังคงประวัติการใช้งาน"
 ],
 "remainingAllowance": [
  "Remaining",
  "လက်ကျန်",
  "คงเหลือ"
 ],
 "suspended": [
  "Suspended",
  "ယာယီပိတ်ထား",
  "ระงับ"
 ],
 "archived": [
  "Archived",
  "သိမ်းဆည်းထား",
  "เก็บถาวร"
 ]
};
export function useUsageText(){const {locale}=usePage<SharedData>().props;return {locale,t:(key:string)=>words[key]?.[locale==='my'?1:locale==='th'?2:0]??key};}
