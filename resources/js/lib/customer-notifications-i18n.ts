import {usePage} from '@inertiajs/react';
import type {SharedData} from '@/types';
import type {CustomerNotification} from '@/types/customer-notifications';

const words={
 title:['Notifications','အသိပေးချက်များ','การแจ้งเตือน'],
 description:['Updates about your videos, payments, plan and account.','သင့် ဗီဒီယို၊ ငွေပေးချေမှု၊ Plan နှင့် အကောင့်ဆိုင်ရာ အသိပေးချက်များ။','อัปเดตเกี่ยวกับวิดีโอ การชำระเงิน แพ็กเกจ และบัญชีของคุณ'],
 markAll:['Mark all as read','အားလုံး ဖတ်ပြီးဟု မှတ်ရန်','ทำเครื่องหมายว่าอ่านทั้งหมด'],
 allRead:['All notifications marked as read','အားလုံး ဖတ်ပြီးဟု မှတ်ပြီးပါပြီ','ทำเครื่องหมายว่าอ่านทั้งหมดแล้ว'],
 unreadCount:['unread','မဖတ်ရသေး','ยังไม่อ่าน'],
 all:['All','အားလုံး','ทั้งหมด'],
 unread:['Unread','မဖတ်ရသေး','ยังไม่อ่าน'],
 video:['Videos','ဗီဒီယို','วิดีโอ'],
 payment:['Payments','ငွေပေးချေမှု','การชำระเงิน'],
 plan:['Plan','Plan','แพ็กเกจ'],
 account:['Account','အကောင့်','บัญชี'],
 chipVideo:['Video','ဗီဒီယို','วิดีโอ'],
 chipPayment:['Payment','ငွေပေးချေမှု','ชำระเงิน'],
 chipPlan:['Plan','Plan','แพ็กเกจ'],
 chipAccount:['Account','အကောင့်','บัญชี'],
 today:['Today','ယနေ့','วันนี้'],
 yesterday:['Yesterday','မနေ့က','เมื่อวาน'],
 thisWeek:['This week','ဤအပတ်','สัปดาห์นี้'],
 earlier:['Earlier','ယခင်','ก่อนหน้า'],
 justNow:['Just now','ယခုလေးတင်','เมื่อสักครู่'],
 viewAll:['View all notifications','အသိပေးချက်အားလုံး ကြည့်ရန်','ดูการแจ้งเตือนทั้งหมด'],
 caughtUp:["You're all caught up",'အကုန် ဖတ်ပြီးပါပြီ','คุณอ่านครบแล้ว'],
 caughtUpUnread:['No unread notifications right now.','မဖတ်ရသေးသော အသိပေးချက် မရှိပါ။','ไม่มีการแจ้งเตือนที่ยังไม่อ่าน'],
 caughtUpHint:["We'll let you know when your videos, payments or plan change.",'ဗီဒီယို၊ ငွေပေးချေမှု သို့ Plan ပြောင်းလဲလျှင် အသိပေးပါမည်။','เราจะแจ้งเมื่อวิดีโอ การชำระเงิน หรือแพ็กเกจของคุณมีการเปลี่ยนแปลง'],
 search:['Search notifications…','အသိပေးချက် ရှာရန်…','ค้นหาการแจ้งเตือน…'],
 statusAll:['Status: All','အခြေအနေ: အားလုံး','สถานะ: ทั้งหมด'],
 statusUnread:['Status: Unread','အခြေအနေ: မဖတ်ရသေး','สถานะ: ยังไม่อ่าน'],
 statusRead:['Status: Read','အခြေအနေ: ဖတ်ပြီး','สถานะ: อ่านแล้ว'],
 period7:['Last 7 days','နောက်ဆုံး ၇ ရက်','7 วันล่าสุด'],
 period30:['Last 30 days','နောက်ဆုံး ၃၀ ရက်','30 วันล่าสุด'],
 periodAll:['All time','အချိန်အားလုံး','ทั้งหมด'],
 showArchived:['Show archived','သိမ်းထားသည်များ ပြရန်','แสดงที่เก็บถาวร'],
 refresh:['Refresh','ပြန်လည်ရယူရန်','รีเฟรช'],
 clearFilters:['Clear filters','စစ်ထုတ်မှု ရှင်းရန်','ล้างตัวกรอง'],
 filters:['Filters','စစ်ထုတ်ရန်','ตัวกรอง'],
 apply:['Apply','အသုံးပြုရန်','ใช้'],
 noMatch:['No notifications match','ကိုက်ညီသော အသိပေးချက် မရှိပါ','ไม่พบการแจ้งเตือนที่ตรงกัน'],
 selected:['selected','ရွေးထားသည်','รายการที่เลือก'],
 selectAll:['Select all on this page','ဤစာမျက်နှာရှိ အားလုံးရွေးရန်','เลือกทั้งหมดในหน้านี้'],
 select:['Select','ရွေးရန်','เลือก'],
 clearSelection:['Clear selection','ရွေးချယ်မှု ဖျက်ရန်','ล้างการเลือก'],
 markRead:['Mark as read','ဖတ်ပြီးဟု မှတ်ရန်','ทำเครื่องหมายว่าอ่านแล้ว'],
 markUnread:['Mark as unread','မဖတ်ရသေးဟု မှတ်ရန်','ทำเครื่องหมายว่ายังไม่อ่าน'],
 archive:['Archive','သိမ်းရန်','เก็บถาวร'],
 unarchive:['Restore','ပြန်ထုတ်ရန်','กู้คืน'],
 read:['Read','ဖတ်ပြီး','อ่านแล้ว'],
 archived:['Archived','သိမ်းပြီးပါပြီ','เก็บถาวรแล้ว'],
 restored:['Restored','ပြန်ထုတ်ပြီးပါပြီ','กู้คืนแล้ว'],
 updated:['Updated','ပြင်ပြီးပါပြီ','อัปเดตแล้ว'],
 moreActions:['More actions','နောက်ထပ် လုပ်ဆောင်ချက်','การดำเนินการเพิ่มเติม'],
 loadFailed:['Could not load notifications.','အသိပေးချက်များ ဖွင့်မရပါ။','โหลดการแจ้งเตือนไม่ได้'],
 retryLoad:['Retry','ပြန်ကြိုးစားရန်','ลองอีกครั้ง'],
 failed:['Something went wrong. Please retry.','တစ်ခုခု မှားသွားပါသည်။ ပြန်ကြိုးစားပါ။','เกิดข้อผิดพลาด โปรดลองอีกครั้ง'],
 showing:['Showing','ပြသနေသည်','แสดง'],
 of:['of','မှ','จาก'],
 first:['First','ပထမ','แรก'],
 previous:['Previous','ယခင်','ก่อนหน้า'],
 next:['Next','နောက်','ถัดไป'],
 last:['Last','နောက်ဆုံး','สุดท้าย'],
 close:['Close','ပိတ်ရန်','ปิด'],
 removedProject:['This project was deleted.','ဤ project ကို ဖျက်ထားပြီးပါပြီ။','โปรเจกต์นี้ถูกลบแล้ว'],
 download:['Download','ဒေါင်းလုဒ်','ดาวน์โหลด'],
 review:['Review','စစ်ဆေးရန်','ตรวจสอบ'],
 retry:['Retry','ပြန်လုပ်ရန်','ลองใหม่'],
 renew:['Renew','သက်တမ်းတိုးရန်','ต่ออายุ'],
 provide:['Provide info','အချက်အလက် ပေးရန်','ส่งข้อมูล'],
 approved:['Approved','အတည်ပြုပြီး','อนุมัติแล้ว'],
 actionRequired:['Action required','လုပ်ဆောင်ရန်လို','ต้องดำเนินการ'],
 rejected:['Rejected','ငြင်းပယ်','ปฏิเสธ'],
 refunded:['Refunded','ပြန်အမ်းပြီး','คืนเงินแล้ว'],
 ready:['Ready','အဆင်သင့်','พร้อม'],
 failedChip:['Failed','မအောင်မြင်','ล้มเหลว'],
 expiring:['Expiring','ကုန်ခါနီး','ใกล้หมดอายุ'],
 expired:['Expired','ကုန်ပြီ','หมดอายุ'],
 active:['Active','အသုံးပြုနိုင်','ใช้งานอยู่'],
 security:['Security','လုံခြုံရေး','ความปลอดภัย'],
} as const;

/** Title and message per notification type; {placeholders} come from the notification data. */
const templates:Record<string,{title:[string,string,string];body:[string,string,string]}>={
 video_ready:{title:['Video ready','ဗီဒီယို အဆင်သင့်','วิดีโอพร้อมแล้ว'],body:['Your recap “{project}” is ready to download.','“{project}” recap ကို ဒေါင်းလုဒ်ဆွဲနိုင်ပါပြီ။','รีแคป “{project}” พร้อมดาวน์โหลดแล้ว']},
 script_ready:{title:['Script ready for review','Script စစ်ဆေးရန် အဆင်သင့်','สคริปต์พร้อมให้ตรวจ'],body:['Review the script for “{project}” before rendering.','“{project}” ကို render မလုပ်မီ script ကို စစ်ဆေးပါ။','ตรวจสคริปต์ของ “{project}” ก่อนเรนเดอร์']},
 export_failed:{title:['Export failed','Export မအောင်မြင်ပါ','ส่งออกไม่สำเร็จ'],body:['“{project}” could not be exported. You can retry.','“{project}” ကို export လုပ်၍ မရပါ။ ပြန်ကြိုးစားနိုင်ပါသည်။','ไม่สามารถส่งออก “{project}” ได้ คุณสามารถลองใหม่']},
 analysis_failed:{title:['Script could not be written','Script ရေး၍ မရပါ','เขียนสคริปต์ไม่สำเร็จ'],body:['The script for “{project}” could not be created. You can retry.','“{project}” အတွက် script ဖန်တီး၍ မရပါ။ ပြန်ကြိုးစားနိုင်ပါသည်။','ไม่สามารถสร้างสคริปต์ของ “{project}” ได้ คุณสามารถลองใหม่']},
 payment_approved:{title:['Payment approved','ငွေပေးချေမှု အတည်ပြုပြီး','อนุมัติการชำระเงินแล้ว'],body:['Your {package} payment ({amount}) was approved.','သင့် {package} ငွေပေးချေမှု ({amount}) ကို အတည်ပြုပြီးပါပြီ။','การชำระเงิน {package} ({amount}) ได้รับการอนุมัติแล้ว']},
 payment_info_needed:{title:['Information needed','အချက်အလက် ထပ်လိုအပ်','ต้องการข้อมูลเพิ่มเติม'],body:['Admin needs more information for {code}.','{code} အတွက် Admin က အချက်အလက် ထပ်လိုအပ်ပါသည်။','ผู้ดูแลต้องการข้อมูลเพิ่มเติมสำหรับ {code}']},
 payment_rejected:{title:['Payment rejected','ငွေပေးချေမှု ငြင်းပယ်ခံရ','การชำระเงินถูกปฏิเสธ'],body:['{code} was not approved. See the reason.','{code} ကို အတည်မပြုပါ။ အကြောင်းရင်း ကြည့်ပါ။','{code} ไม่ได้รับการอนุมัติ ดูเหตุผล']},
 refund_completed:{title:['Refund completed','ငွေပြန်အမ်းပြီး','คืนเงินเรียบร้อย'],body:['{amount} was refunded for {code}.','{code} အတွက် {amount} ပြန်အမ်းပြီးပါပြီ။','คืนเงิน {amount} สำหรับ {code} แล้ว']},
 plan_expiring:{title:['Plan expiring soon','Plan သက်တမ်းကုန်ခါနီး','แพ็กเกจใกล้หมดอายุ'],body:['Your {package} plan ends in {days} days.','သင့် {package} Plan သက်တမ်း {days} ရက်အတွင်း ကုန်ပါမည်။','แพ็กเกจ {package} จะหมดอายุใน {days} วัน']},
 plan_expired:{title:['Plan expired','Plan သက်တမ်းကုန်ပြီ','แพ็กเกจหมดอายุ'],body:['Your {package} plan has ended. Renew to keep creating.','သင့် {package} Plan ကုန်သွားပါပြီ။ ဆက်ဖန်တီးရန် သက်တမ်းတိုးပါ။','แพ็กเกจ {package} หมดอายุแล้ว ต่ออายุเพื่อสร้างต่อ']},
 plan_started:{title:['New plan started','Plan အသစ် စတင်ပြီ','เริ่มแพ็กเกจใหม่แล้ว'],body:['Your {package} plan is now active until {date}.','သင့် {package} Plan ကို {date} အထိ အသုံးပြုနိုင်ပါပြီ။','แพ็กเกจ {package} ใช้งานได้ถึง {date}']},
 password_changed:{title:['Password changed','စကားဝှက် ပြောင်းထားသည်','เปลี่ยนรหัสผ่านแล้ว'],body:["Your password was changed. If this wasn't you, contact Support.",'သင့်စကားဝှက် ပြောင်းထားပါသည်။ သင်မဟုတ်ပါက Support ကို ဆက်သွယ်ပါ။','รหัสผ่านของคุณถูกเปลี่ยน หากไม่ใช่คุณ โปรดติดต่อฝ่ายช่วยเหลือ']},
 email_changed:{title:['Email changed','အီးမေးလ် ပြောင်းထားသည်','เปลี่ยนอีเมลแล้ว'],body:["Your sign-in email was changed. If this wasn't you, contact Support.",'သင့် ဝင်ရောက်သည့် အီးမေးလ် ပြောင်းထားပါသည်။ သင်မဟုတ်ပါက Support ကို ဆက်သွယ်ပါ။','อีเมลเข้าสู่ระบบของคุณถูกเปลี่ยน หากไม่ใช่คุณ โปรดติดต่อฝ่ายช่วยเหลือ']},
};

export type NotificationWord=keyof typeof words;
const money=(amount:number,currency:string)=>currency+' '+new Intl.NumberFormat('en-US',{maximumFractionDigits:2}).format(amount/100);

export function useNotificationText(){
 const {locale}=usePage<SharedData>().props;
 const index=locale==='my'?1:locale==='th'?2:0;
 const language=locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US';
 const t=(key:NotificationWord)=>words[key][index];
 /** Localized title and message for one notification. */
 function describe(item:CustomerNotification,formatDate:(value:string)=>string){
  const template=templates[item.type];
  const data=item.data;
  const values:Record<string,string>={project:String(data.project??''),package:String(data.package??''),code:String(data.code??''),days:String(data.days??''),
   amount:data.amount_minor!==undefined&&data.currency?money(Number(data.amount_minor),String(data.currency)):'',date:data.ends_at?formatDate(String(data.ends_at)):''};
  const fill=(text:string)=>text.replace(/\{(\w+)\}/g,(_,key:string)=>values[key]??'');
  return template?{title:template.title[index],body:fill(template.body[index])}:{title:item.type,body:''};
 }
 return {t,describe,locale,language};
}
