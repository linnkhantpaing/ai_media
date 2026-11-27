import {usePage} from '@inertiajs/react';
import type {SharedData} from '@/types';

// Add-to-Home-screen guide (sidebar footer and login page). Words follow the real menu labels of
// Chrome, Samsung Internet, Safari and Edge so customers can match them on their own phone.
const words={
 entry:['Add as an app','App အဖြစ် ထည့်ရန်','เพิ่มเป็นแอป'],
 entryHint:['Home screen shortcut · no install','Home screen တွင် shortcut · Install မလို','ทางลัดบนหน้าจอหลัก · ไม่ต้องติดตั้ง'],
 title:['Use {app} like an app','{app} ကို App ကဲ့သို့ သုံးရန်','ใช้ {app} เหมือนแอป'],
 subtitle:['Add a shortcut to your home screen in a few taps','Home screen တွင် shortcut ကို ခဏလေးဖြင့် ထည့်ပါ','เพิ่มทางลัดบนหน้าจอหลักด้วยไม่กี่แตะ'],
 heroText:['No download from Play Store or App Store. The {app} icon sits on your home screen and opens the website with one tap.','Play Store / App Store မှ download လုပ်စရာ မလိုပါ။ Home screen တွင် {app} icon ပေါ်ပြီး တစ်ချက်နှိပ်ရုံဖြင့် website ကို ဖွင့်ပေးပါသည်။','ไม่ต้องดาวน์โหลดจาก Play Store หรือ App Store ไอคอน {app} จะอยู่บนหน้าจอหลักและเปิดเว็บไซต์ได้ในแตะเดียว'],
 perkFree:['Free','အခမဲ့','ฟรี'],
 perkSpace:['Uses no phone storage','ဖုန်း storage မကုန်','ไม่ใช้พื้นที่เครื่อง'],
 perkUpdate:['Always the latest version','အမြဲ နောက်ဆုံး version','เวอร์ชันล่าสุดเสมอ'],
 iconPreview:['This icon appears on your home screen','Home screen တွင် ဤ icon ပေါ်ပါမည်','ไอคอนนี้จะแสดงบนหน้าจอหลัก'],
 android:['Android','Android','Android'],
 iphone:['iPhone / iPad','iPhone / iPad','iPhone / iPad'],
 computer:['Computer','ကွန်ပျူတာ','คอมพิวเตอร์'],
 detected:['Your device','သင့် device','อุปกรณ์ของคุณ'],
 inAppTitle:['Open this page in {browser} first','ဤစာမျက်နှာကို {browser} ဖြင့် အရင်ဖွင့်ပါ','เปิดหน้านี้ใน {browser} ก่อน'],
 inAppText:['You opened the link inside Messenger, Viber or Facebook. A shortcut cannot be added from there.','Link ကို Messenger / Viber / Facebook ထဲမှ ဖွင့်ထားသောကြောင့် ဤနေရာမှ shortcut ထည့်၍ မရပါ။','คุณเปิดลิงก์ใน Messenger, Viber หรือ Facebook จึงเพิ่มทางลัดจากที่นี่ไม่ได้'],
 inAppHow:['Tap ⋮ or ··· at the top, choose "Open in browser" (or "Open in {browser}"), or copy the link below and paste it into {browser}.','အပေါ်ရှိ ⋮ သို့မဟုတ် ··· ကို နှိပ်ပြီး "Open in browser" (သို့မဟုတ် "Open in {browser}") ကို ရွေးပါ။ သို့မဟုတ် အောက်ပါ link ကို copy ယူပြီး {browser} တွင် paste လုပ်ပါ။','แตะ ⋮ หรือ ··· ด้านบน เลือก "Open in browser" (หรือ "Open in {browser}") หรือคัดลอกลิงก์ด้านล่างไปวางใน {browser}'],
 link:['Website link','Website link','ลิงก์เว็บไซต์'],
 copy:['Copy','Copy','คัดลอก'],
 copied:['Copied','Copy ပြီး','คัดลอกแล้ว'],
 step:['Step','အဆင့်','ขั้นที่'],
 tap:['Tap','နှိပ်ပါ','แตะ'],
 // Android · Chrome
 a1:['Open the website in Chrome','Website ကို Chrome ဖြင့် ဖွင့်ပါ','เปิดเว็บไซต์ใน Chrome'],
 a1t:['Use the Chrome app (the round red, yellow and green icon). Copy the link above if needed.','Chrome app (အနီ၊ အဝါ၊ အစိမ်း အဝိုင်း icon) ကို သုံးပါ။ လိုအပ်ပါက အပေါ်ရှိ link ကို copy ယူပါ။','ใช้แอป Chrome (ไอคอนกลมสีแดง เหลือง เขียว) คัดลอกลิงก์ด้านบนได้หากต้องการ'],
 a2:['Tap the menu ⋮','Menu ⋮ ကို နှိပ်ပါ','แตะเมนู ⋮'],
 a2t:['The three dots at the top right, next to the address bar.','Address bar ဘေး ညာဘက်အပေါ်ထောင့်ရှိ အစက် ၃ စက်။','จุดสามจุดที่มุมขวาบน ข้างแถบที่อยู่'],
 a3:['Choose "Add to Home screen"','"Add to Home screen" ကို ရွေးပါ','เลือก "Add to Home screen"'],
 a3t:['Scroll the menu down a little if you do not see it. On some phones it says "Install app" or "ပင်မ မျက်နှာပြင်သို့ ထည့်ရန်".','မတွေ့ပါက menu ကို အောက်သို့ နည်းနည်း ဆွဲပါ။ ဖုန်းအချို့တွင် "Install app" သို့မဟုတ် "ပင်မ မျက်နှာပြင်သို့ ထည့်ရန်" ဟု ပြပါသည်။','ถ้าไม่เห็นให้เลื่อนเมนูลงเล็กน้อย บางเครื่องจะแสดงว่า "Install app" หรือ "เพิ่มไปยังหน้าจอหลัก"'],
 a4:['Tap "Add"','"Add" ကို နှိပ်ပါ','แตะ "Add"'],
 a4t:['You can change the name first. If asked again, tap "Add" or "Add automatically".','အမည်ကို အရင် ပြောင်းနိုင်ပါသည်။ ထပ်မေးပါက "Add" သို့မဟုတ် "Add automatically" ကို နှိပ်ပါ။','เปลี่ยนชื่อก่อนได้ ถ้าถามอีกครั้งให้แตะ "Add" หรือ "Add automatically"'],
 samsung:['Using Samsung Internet? Tap ≡ at the bottom → "Add page to" → "Home screen".','Samsung Internet သုံးပါက အောက်ခြေရှိ ≡ → "Add page to" → "Home screen" ကို နှိပ်ပါ။','ใช้ Samsung Internet? แตะ ≡ ด้านล่าง → "Add page to" → "Home screen"'],
 // iPhone · Safari
 i1:['Open the website in Safari','Website ကို Safari ဖြင့် ဖွင့်ပါ','เปิดเว็บไซต์ใน Safari'],
 i1t:['Safari is the blue compass app. On iOS 16.4 or newer, Chrome also works the same way.','Safari သည် အပြာရောင် compass icon app ဖြစ်သည်။ iOS 16.4 နှင့်အထက်တွင် Chrome ဖြင့်လည်း ဤအတိုင်း ရပါသည်။','Safari คือแอปเข็มทิศสีฟ้า ใน iOS 16.4 ขึ้นไป Chrome ก็ทำแบบเดียวกันได้'],
 i2:['Tap the Share button','Share button ကို နှိပ်ပါ','แตะปุ่มแชร์'],
 i2t:['The square with an arrow pointing up, in the bar at the bottom (top right on iPad). In Chrome it is in the address bar.','အောက်ခြေ bar ရှိ မြှားအပေါ်ညွှန်သော လေးထောင့် (iPad တွင် ညာဘက်အပေါ်)။ Chrome တွင် address bar ထဲတွင် ရှိသည်။','สี่เหลี่ยมที่มีลูกศรชี้ขึ้น ในแถบด้านล่าง (บน iPad อยู่มุมขวาบน) ใน Chrome อยู่ในแถบที่อยู่'],
 i3:['Choose "Add to Home Screen"','"Add to Home Screen" ကို ရွေးပါ','เลือก "Add to Home Screen"'],
 i3t:['Scroll down the list below the app icons. In Burmese it may read "ပင်မ မျက်နှာပြင်သို့ ထည့်ရန်".','App icon များ အောက်ရှိ စာရင်းကို အောက်သို့ ဆွဲပါ။ မြန်မာဘာသာဖြင့် "ပင်မ မျက်နှာပြင်သို့ ထည့်ရန်" ဟု ပြနိုင်သည်။','เลื่อนรายการใต้ไอคอนแอปลงมา ภาษาไทยจะเป็น "เพิ่มไปยังหน้าจอโฮม"'],
 i4:['Tap "Add" at the top right','ညာဘက်အပေါ်ရှိ "Add" ကို နှိပ်ပါ','แตะ "Add" ที่มุมขวาบน'],
 i4t:['You can change the name first. If you see "Open as Web App", leave it on.','အမည်ကို အရင် ပြောင်းနိုင်ပါသည်။ "Open as Web App" ပြပါက ဖွင့်ထားပါ။','เปลี่ยนชื่อก่อนได้ ถ้าเห็น "Open as Web App" ให้เปิดไว้'],
 // Computer · Chrome / Edge
 c1:['Open the website in Chrome or Edge','Website ကို Chrome သို့မဟုတ် Edge ဖြင့် ဖွင့်ပါ','เปิดเว็บไซต์ใน Chrome หรือ Edge'],
 c1t:['Sign in once so the shortcut opens straight into your projects.','Shortcut မှ project များသို့ တန်းဝင်နိုင်ရန် တစ်ကြိမ် login ဝင်ထားပါ။','เข้าสู่ระบบหนึ่งครั้งเพื่อให้ทางลัดเปิดเข้าโปรเจกต์ได้ทันที'],
 c2:['Chrome: ⋮ → "Cast, save and share" → "Create shortcut…"','Chrome: ⋮ → "Cast, save and share" → "Create shortcut…"','Chrome: ⋮ → "Cast, save and share" → "Create shortcut…"'],
 c2t:['Tick "Open as window" so it opens in its own window like an app, then tap "Create".','App ကဲ့သို့ သီးသန့် window ဖြင့် ပွင့်ရန် "Open as window" ကို အမှန်ခြစ်ပြီး "Create" ကို နှိပ်ပါ။','ติ๊ก "Open as window" เพื่อเปิดในหน้าต่างของตัวเองเหมือนแอป แล้วแตะ "Create"'],
 c3:['Edge: ··· → "Apps" → "Install this site as an app"','Edge: ··· → "Apps" → "Install this site as an app"','Edge: ··· → "Apps" → "Install this site as an app"'],
 c3t:['Then tap "Install". You can pin it to the taskbar or Start menu.','ထို့နောက် "Install" ကို နှိပ်ပါ။ Taskbar သို့မဟုတ် Start menu တွင် pin လုပ်ထားနိုင်ပါသည်။','จากนั้นแตะ "Install" ปักหมุดไว้ที่แถบงานหรือเมนู Start ได้'],
 // Done
 done:['Done — find {app} on your home screen','ပြီးပါပြီ — Home screen တွင် {app} ကို ရှာပါ','เสร็จแล้ว — หา {app} บนหน้าจอหลัก'],
 doneText:['Tap the icon to open it. You stay signed in, so next time it opens straight into your work.','Icon ကို နှိပ်၍ ဖွင့်ပါ။ Login ဝင်ထားပြီးဖြစ်၍ နောက်တစ်ကြိမ် တန်းဝင်ပါမည်။','แตะไอคอนเพื่อเปิด คุณยังเข้าสู่ระบบอยู่ ครั้งหน้าจะเปิดเข้างานได้ทันที'],
 tipsTitle:['Good to know','သိထားသင့်သည်','ควรรู้'],
 tipInternet:['It needs the internet, like the website.','Website ကဲ့သို့ပင် internet လိုအပ်ပါသည်။','ต้องใช้อินเทอร์เน็ตเหมือนเว็บไซต์'],
 tipRemove:['To remove it, press and hold the icon → "Remove".','ဖယ်ရှားလိုပါက icon ကို ဖိထားပြီး "Remove" ကို နှိပ်ပါ။','หากต้องการลบ ให้กดไอคอนค้างไว้ → "Remove"'],
 tipSame:['Same account, projects and minutes as the website.','Website နှင့် account၊ project၊ မိနစ် အတူတူပင် ဖြစ်ပါသည်။','บัญชี โปรเจกต์ และนาทีเดียวกับเว็บไซต์'],
 close:['Close','ပိတ်ရန်','ปิด'],
} as const;
export type InstallWord=keyof typeof words;
export function useInstallText(){
 const {locale,app}=usePage<SharedData>().props;const index=locale==='my'?1:locale==='th'?2:0;const name=app?.name||'ZATLAN';
 return {t:(key:InstallWord,vars:Record<string,string>={})=>Object.entries({app:name,...vars}).reduce((text,[k,v])=>text.replaceAll('{'+k+'}',v),words[key][index] as string),name};
}
