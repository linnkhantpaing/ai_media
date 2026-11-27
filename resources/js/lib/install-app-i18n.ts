import {usePage} from '@inertiajs/react';
import type {SharedData} from '@/types';

// Add-to-Home-screen guide (sidebar footer and login page). Words follow the real menu labels of
// Chrome, Samsung Internet, Safari and Edge so customers can match them on their own phone.
// {chip} marks where a button-shaped label is drawn inside the sentence.
const words={
 entry:['Add as an app','App အဖြစ် ထည့်ရန်','เพิ่มเป็นแอป'],
 entryHint:['Home screen shortcut · no install','Home screen တွင် shortcut · Install မလို','ทางลัดบนหน้าจอหลัก · ไม่ต้องติดตั้ง'],
 title:['{app} App setup','{app} App ထည့်သွင်းရန်','ติดตั้งแอป {app}'],
 subtitle:['Keep a shortcut on your home screen, the easy way','Home screen တွင် shortcut တစ်ခု အလွယ်တကူ ထားနိုင်သည်','วางทางลัดบนหน้าจอหลักได้ง่าย ๆ'],
 heroText:['No download from Play Store or App Store. Put the {app} icon on your home screen and use the website with one tap.','Play Store / App Store မှ download မလုပ်ဘဲ Home screen တွင် {app} icon ထည့်ပြီး website ကို အလွယ်တကူ အသုံးပြုနိုင်ပါသည်။','ไม่ต้องดาวน์โหลดจาก Play Store หรือ App Store วางไอคอน {app} บนหน้าจอหลักแล้วใช้เว็บไซต์ได้ในแตะเดียว'],
 perkFree:['Free','အခမဲ့','ฟรี'],
 perkSpace:['No phone storage','ဖုန်း storage မကုန်','ไม่ใช้พื้นที่เครื่อง'],
 perkUpdate:['Always up to date','အမြဲ နောက်ဆုံး version','อัปเดตเสมอ'],
 iconPreview:['This icon appears on your home screen','Home screen တွင် ဤ icon ပေါ်ပါမည်','ไอคอนนี้จะแสดงบนหน้าจอหลัก'],
 android:['Android','Android','Android'],
 iphone:['iPhone / iPad','iPhone / iPad','iPhone / iPad'],
 computer:['Computer','ကွန်ပျူတာ','คอมพิวเตอร์'],
 detected:['Your device','သင့် Device','อุปกรณ์ของคุณ'],
 inAppTitle:['Open this page in {browser} first','ဤစာမျက်နှာကို {browser} ဖြင့် အရင်ဖွင့်ပါ','เปิดหน้านี้ใน {browser} ก่อน'],
 inAppText:['You opened the link inside Messenger, Viber or Facebook. A shortcut cannot be added from there.','Link ကို Messenger / Viber / Facebook ထဲမှ ဖွင့်ထားသောကြောင့် ဤနေရာမှ shortcut ထည့်၍ မရပါ။','คุณเปิดลิงก์ใน Messenger, Viber หรือ Facebook จึงเพิ่มทางลัดจากที่นี่ไม่ได้'],
 inAppHow:['Tap ⋮ or ··· at the top and choose “Open in browser”, or copy the link below and paste it into {browser}.','အပေါ်ရှိ ⋮ သို့မဟုတ် ··· ကို နှိပ်ပြီး “Open in browser” ကို ရွေးပါ။ သို့မဟုတ် အောက်ပါ link ကို copy ယူပြီး {browser} တွင် paste လုပ်ပါ။','แตะ ⋮ หรือ ··· ด้านบนแล้วเลือก “Open in browser” หรือคัดลอกลิงก์ด้านล่างไปวางใน {browser}'],
 link:['Website Link','Website Link','ลิงก์เว็บไซต์'],
 copy:['Copy','Copy','คัดลอก'],
 copied:['Copied','Copy ပြီး','คัดลอกแล้ว'],
 step:['Step','အဆင့်','ขั้นที่'],
 // Android · Chrome
 a1:['Open the website in Chrome','Website ကို Chrome ဖြင့် ဖွင့်ပါ','เปิดเว็บไซต์ใน Chrome'],
 a1t:['Open the {app} website link in the Chrome app.','Chrome app မှာ {app} website link ကို ဖွင့်ပါ။','เปิดลิงก์เว็บไซต์ {app} ในแอป Chrome'],
 a2:['Tap the menu','Menu ကို နှိပ်ပါ','แตะเมนู'],
 a2t:['Tap the menu ( ⋮ ) at the top right, next to the address bar.','Address bar ဘေး ညာဘက်ရှိ Menu ( ⋮ ) ကို နှိပ်ပါ။','แตะเมนู ( ⋮ ) มุมขวาบน ข้างแถบที่อยู่'],
 a3:['Choose “Add to Home screen”','“Add to Home screen” ကို ရွေးပါ','เลือก “Add to Home screen”'],
 a3t:['Tap {chip} to put a shortcut on your home screen.','{chip} ကို နှိပ်ပြီး Home screen တွင် shortcut တစ်ခု ထည့်ပါ။','แตะ {chip} เพื่อวางทางลัดบนหน้าจอหลัก'],
 a4:['Tap “Add”','“Add” ကို နှိပ်ပါ','แตะ “Add”'],
 a4t:['Tap {chip} to confirm.','အတည်ပြုရန် {chip} ကို နှိပ်ပါ။','แตะ {chip} เพื่อยืนยัน'],
 samsung:['Samsung Internet users: tap ≡ → “Add page to” → “Home screen”.','Samsung Internet အသုံးပြုသူများ: ≡ → “Add page to” → “Home screen” ကို ရွေးပါ။','ผู้ใช้ Samsung Internet: แตะ ≡ → “Add page to” → “Home screen”'],
 // iPhone · Safari
 i1:['Open the website in Safari','Website ကို Safari ဖြင့် ဖွင့်ပါ','เปิดเว็บไซต์ใน Safari'],
 i1t:['Open the {app} website link in the Safari app.','Safari app မှာ {app} website link ကို ဖွင့်ပါ။','เปิดลิงก์เว็บไซต์ {app} ในแอป Safari'],
 i2:['Tap Share','Share ကို နှိပ်ပါ','แตะแชร์'],
 i2t:['Tap Share ( □↑ ) in the bottom bar (top right on iPad).','အောက်ခြေ bar ရှိ Share ( □↑ ) ကို နှိပ်ပါ (iPad တွင် ညာဘက်အပေါ်)။','แตะแชร์ ( □↑ ) ในแถบด้านล่าง (บน iPad อยู่มุมขวาบน)'],
 i3:['Choose “Add to Home Screen”','“Add to Home Screen” ကို ရွေးပါ','เลือก “Add to Home Screen”'],
 i3t:['Scroll down and tap {chip}.','အောက်သို့ ဆွဲပြီး {chip} ကို နှိပ်ပါ။','เลื่อนลงแล้วแตะ {chip}'],
 i4:['Tap “Add”','“Add” ကို နှိပ်ပါ','แตะ “Add”'],
 i4t:['Tap {chip} at the top right to confirm.','အတည်ပြုရန် ညာဘက်အပေါ်ရှိ {chip} ကို နှိပ်ပါ။','แตะ {chip} มุมขวาบนเพื่อยืนยัน'],
 iosNote:['On iOS 16.4 or newer, Chrome works the same way: Share is in the address bar.','iOS 16.4 နှင့်အထက်တွင် Chrome ဖြင့်လည်း ရပါသည် — Share သည် address bar ထဲတွင် ရှိသည်။','ใน iOS 16.4 ขึ้นไปใช้ Chrome ได้เช่นกัน — ปุ่มแชร์อยู่ในแถบที่อยู่'],
 // Computer · Chrome / Edge
 c1:['Open the website in Chrome or Edge','Website ကို Chrome / Edge ဖြင့် ဖွင့်ပါ','เปิดเว็บไซต์ใน Chrome หรือ Edge'],
 c1t:['Open the {app} website link and sign in once.','{app} website link ကို ဖွင့်ပြီး တစ်ကြိမ် Login ဝင်ထားပါ။','เปิดลิงก์เว็บไซต์ {app} แล้วเข้าสู่ระบบหนึ่งครั้ง'],
 c2:['Chrome: tap the menu ( ⋮ )','Chrome: Menu ( ⋮ ) ကို နှိပ်ပါ','Chrome: แตะเมนู ( ⋮ )'],
 c2t:['“Cast, save and share” → {chip} → tick “Open as window” → “Create”.','“Cast, save and share” → {chip} → “Open as window” ကို အမှန်ခြစ် → “Create” ကို နှိပ်ပါ။','“Cast, save and share” → {chip} → ติ๊ก “Open as window” → “Create”'],
 c3:['Edge: tap the menu ( ··· )','Edge: Menu ( ··· ) ကို နှိပ်ပါ','Edge: แตะเมนู ( ··· )'],
 c3t:['“Apps” → {chip} → “Install”.','“Apps” → {chip} → “Install” ကို နှိပ်ပါ။','“Apps” → {chip} → “Install”'],
 // Done
 done:['Done — {app} App is on your home screen','ပြီးပါပြီ — Home screen တွင် {app} App ရပါပြီ','เสร็จแล้ว — แอป {app} อยู่บนหน้าจอหลัก'],
 doneText:['Tap the icon on your home screen and sign in to start.','Home screen ရှိ icon ကို နှိပ်ပြီး Login ဝင်ကာ အသုံးပြုနိုင်ပါသည်။','แตะไอคอนบนหน้าจอหลักแล้วเข้าสู่ระบบเพื่อเริ่มใช้'],
 doneComputer:['Done — {app} App is on your computer','ပြီးပါပြီ — ကွန်ပျူတာတွင် {app} App ရပါပြီ','เสร็จแล้ว — แอป {app} อยู่บนคอมพิวเตอร์'],
 doneComputerText:['Open it from the desktop, Start menu or taskbar.','Desktop၊ Start menu သို့မဟုတ် taskbar မှ ဖွင့်နိုင်ပါသည်။','เปิดได้จากเดสก์ท็อป เมนู Start หรือแถบงาน'],
 tipsTitle:['Good to know','သိထားသင့်သည်','ควรรู้'],
 tipSame:['Same account, projects and minutes as the website.','Website နှင့် account၊ project၊ မိနစ် အတူတူ အသုံးပြုနိုင်ပါသည်။','บัญชี โปรเจกต์ และนาทีเดียวกับเว็บไซต์'],
 tipInternet:['It needs an internet connection, like the website.','Website ကဲ့သို့ပင် အင်တာနက် ချိတ်ဆက်ထားရန် လိုအပ်သည်။','ต้องเชื่อมต่ออินเทอร์เน็ตเหมือนเว็บไซต์'],
 tipRemove:['To remove it, press and hold the icon and choose “Remove”.','မလိုအပ်တော့လျှင် Home screen ရှိ icon ကို ကြာကြာဖိပြီး “Remove” ကို ရွေးပါ။','หากไม่ต้องการแล้ว ให้กดไอคอนค้างไว้แล้วเลือก “Remove”'],
 close:['Close','ပိတ်ရန်','ปิด'],
} as const;
export type InstallWord=keyof typeof words;
export function useInstallText(){
 const {locale,app}=usePage<SharedData>().props;const index=locale==='my'?1:locale==='th'?2:0;const name=app?.name||'ZATLAN';
 return {t:(key:InstallWord,vars:Record<string,string>={})=>Object.entries({app:name,...vars}).reduce((text,[k,v])=>text.replaceAll('{'+k+'}',v),words[key][index] as string),name};
}
