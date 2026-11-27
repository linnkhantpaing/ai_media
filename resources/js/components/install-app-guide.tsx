import {usePage} from '@inertiajs/react';
import {useEffect,useMemo,useState,type ReactNode} from 'react';
import {MediaDialog} from '@/components/media-workflow';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {useInstallText,type InstallWord} from '@/lib/install-app-i18n';
import type {SharedData} from '@/types';
import '../../css/install-app.css';

/**
 * "Add as an app" guide: shows customers how to put a home-screen shortcut to the website on
 * Android, iPhone/iPad or a computer, with the platform favicon as the icon. Nothing is installed
 * from a store. Optional screenshots go in public/images/install/; a missing file is not shown.
 */
type Device='android'|'iphone'|'computer';
type Step={title:InstallWord;text:InstallWord;tile:ReactNode;chip?:{label:string;tone?:'accent'};shot?:string};

function detect():{device:Device;inApp:boolean;standalone:boolean;browser:string}{
 if(typeof navigator==='undefined')return {device:'computer',inApp:false,standalone:false,browser:'Chrome'};
 const ua=navigator.userAgent;
 const ios=/iPhone|iPad|iPod/i.test(ua)||(/Macintosh/.test(ua)&&navigator.maxTouchPoints>1);
 const device:Device=ios?'iphone':/Android/i.test(ua)?'android':'computer';
 const inApp=/FBAN|FBAV|FB_IAB|FBIOS|Messenger|Instagram|Line\/|Viber|MicroMessenger|; wv\)/i.test(ua);
 const standalone=window.matchMedia?.('(display-mode: standalone)').matches||(navigator as Navigator&{standalone?:boolean}).standalone===true;
 return {device,inApp,standalone,browser:ios?'Safari':'Chrome'};
}

/** True when the page already runs from a home-screen shortcut, so the entry can be hidden. */
export function useRunsAsApp(){const [value,setValue]=useState(false);useEffect(()=>setValue(detect().standalone),[]);return value;}

const Kebab=()=><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>;
const Dots=()=><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>;
const Share=()=><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4"/><path d="M7 10H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2h-1"/></svg>;
const PlusBox=()=><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M12 8.5v7M8.5 12h7"/></svg>;
const Plus=()=><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>;
const HomeBox=()=><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M8.5 12h7"/></svg>;
const Window=()=><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01"/></svg>;
const Monitor=()=><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>;
const AndroidLogo=()=><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18a.38.38 0 0 0-.66-.38l-1.86 3.22A11.4 11.4 0 0 0 12 8.2c-1.77 0-3.43.35-4.92.94L5.22 5.92a.38.38 0 0 0-.66.38L6.4 9.48A9.9 9.9 0 0 0 1.5 17.2h21a9.9 9.9 0 0 0-4.9-7.72zM7 14.6a1.05 1.05 0 1 1 0-2.1 1.05 1.05 0 0 1 0 2.1zm10 0a1.05 1.05 0 1 1 0-2.1 1.05 1.05 0 0 1 0 2.1z"/></svg>;
const AppleLogo=()=><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.6c-.02-2.3 1.88-3.4 1.96-3.46-1.07-1.56-2.730-1.78-3.32-1.8-1.41-.14-2.76.83-3.48.83-.72 0-1.82-.81-3-.79-1.54.02-2.96.9-3.75 2.27-1.6 2.78-.41 6.88 1.15 9.13.76 1.1 1.67 2.34 2.86 2.3 1.15-.05 1.58-.74 2.97-.74 1.38 0 1.77.74 2.98.72 1.230-.02 2.01-1.12 2.76-2.23.87-1.28 1.23-2.52 1.25-2.58-.03-.01-2.4-.92-2.42-3.65zM14.1 5.86c.63-.77 1.06-1.83.94-2.89-.91.04-2.01.61-2.67 1.37-.58.67-1.1 1.76-.96 2.8 1.02.08 2.06-.52 2.69-1.28z"/></svg>;
const Chrome=()=><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="#34A853"/><path d="M12 2a10 10 0 0 1 8.66 5H12z" fill="#EA4335"/><path d="M3.34 7A10 10 0 0 1 12 2v5H7.5z" fill="#EA4335"/><path d="M20.66 7A10 10 0 0 1 12 22l4.33-7.5z" fill="#FBBC05"/><circle cx="12" cy="12" r="4.6" fill="#fff"/><circle cx="12" cy="12" r="3.6" fill="#4285F4"/></svg>;
const Safari=()=><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="#1E88E5"/><circle cx="12" cy="12" r="8" fill="#fff"/><path d="M15.8 8.2l-2.3 5.3-5.3 2.3 2.3-5.3z" fill="#E53935"/><path d="M8.2 15.8l2.3-5.3 3 3z" fill="#90A4AE"/></svg>;

/** Optional screenshot; shown only once the file exists in public/images/install/. */
function Shot({src,alt}:{src?:string;alt:string}){const [ready,setReady]=useState(false);useEffect(()=>{setReady(false);if(!src)return;let live=true;const probe=new Image();probe.onload=()=>{if(live&&probe.naturalWidth>0)setReady(true);};probe.src=src;return()=>{live=false;};},[src]);return ready&&src?<img className="ia-shot" src={src} alt={alt}/>:null;}

/** Sentence with a button-shaped label where the text says {chip}. */
function Sentence({text,chip}:{text:string;chip?:Step['chip']}){
 if(!chip||!text.includes('{chip}'))return <p>{text}</p>;
 const [before,after]=text.split('{chip}');
 return <p>{before}<span className={'ia-pill'+(chip.tone?' is-'+chip.tone:'')}>{chip.label}</span>{after}</p>;
}

export function InstallAppDialog({open,onClose}:{open:boolean;onClose:()=>void}){
 const {t,name}=useInstallText();const {app}=usePage<SharedData>().props;
 const env=useMemo(detect,[]);const [device,setDevice]=useState<Device>(env.device);const [copied,setCopied]=useState(false);
 useEffect(()=>{if(open)setDevice(env.device);},[open,env.device]);
 const icon=app?.favicon||'/favicon.svg';
 const link=typeof window==='undefined'?'':window.location.origin+'/';
 async function copy(){try{await navigator.clipboard.writeText(link);}catch{const input=document.createElement('input');input.value=link;document.body.appendChild(input);input.select();document.execCommand('copy');input.remove();}setCopied(true);setTimeout(()=>setCopied(false),1800);}
 const steps:Record<Device,Step[]>={
  android:[
   {title:'a1',text:'a1t',tile:<Chrome/>,shot:'/images/install/android-1.png'},
   {title:'a2',text:'a2t',tile:<Kebab/>,shot:'/images/install/android-2.png'},
   {title:'a3',text:'a3t',tile:<PlusBox/>,chip:{label:'Add to Home screen'},shot:'/images/install/android-3.png'},
   {title:'a4',text:'a4t',tile:<Plus/>,chip:{label:'Add',tone:'accent'},shot:'/images/install/android-4.png'},
  ],
  iphone:[
   {title:'i1',text:'i1t',tile:<Safari/>,shot:'/images/install/iphone-1.png'},
   {title:'i2',text:'i2t',tile:<Share/>,shot:'/images/install/iphone-2.png'},
   {title:'i3',text:'i3t',tile:<PlusBox/>,chip:{label:'Add to Home Screen'},shot:'/images/install/iphone-3.png'},
   {title:'i4',text:'i4t',tile:<Plus/>,chip:{label:'Add',tone:'accent'},shot:'/images/install/iphone-4.png'},
  ],
  computer:[
   {title:'c1',text:'c1t',tile:<Chrome/>,shot:'/images/install/computer-1.png'},
   {title:'c2',text:'c2t',tile:<Kebab/>,chip:{label:'Create shortcut…'},shot:'/images/install/computer-2.png'},
   {title:'c3',text:'c3t',tile:<Dots/>,chip:{label:'Install this site as an app'},shot:'/images/install/computer-3.png'},
  ],
 };
 const tabs:[Device,InstallWord,ReactNode][]=[['android','android',<AndroidLogo key="a"/>],['iphone','iphone',<AppleLogo key="i"/>],['computer','computer',<Monitor key="c"/>]];
 const perks:[IconName,InstallWord][]=[['bolt','perkFree'],['layers','perkSpace'],['refresh','perkUpdate']];
 const computer=device==='computer';
 return <MediaDialog open={open} title={t('title')} headerIcon="download" headerDetail={t('subtitle')} className="accounts-dialog ia-dialog" animated onClose={onClose}>
  <div className="accounts-dialog-body ia-body">
   <section className="ia-hero">
    <div className="ia-home" aria-label={t('iconPreview')}><img src={icon} alt=""/><small>{name}</small></div>
    <div className="ia-hero-copy"><p>{t('heroText')}</p><ul className="ia-perks">{perks.map(([glyph,word])=><li key={word}><StudioIcon name={glyph}/>{t(word)}</li>)}</ul></div>
   </section>

   {env.inApp&&<section className="ia-warning" role="alert"><StudioIcon name="alert"/><div><strong>{t('inAppTitle',{browser:env.browser})}</strong><p>{t('inAppText')}</p><p>{t('inAppHow',{browser:env.browser})}</p></div></section>}

   <div className="ia-link"><span className="ia-link-label"><StudioIcon name="link"/>{t('link')}</span><code>{link}</code><button type="button" onClick={()=>void copy()}><StudioIcon name={copied?'check':'copy'}/>{t(copied?'copied':'copy')}</button></div>

   <nav className="ia-tabs" role="tablist" aria-label={t('title')}>{tabs.map(([key,label,glyph])=><button key={key} type="button" role="tab" aria-selected={device===key} className={device===key?'is-active':''} onClick={()=>setDevice(key)}>{env.device===key&&<em>{t('detected')}</em>}<span className="ia-tab-icon">{glyph}</span>{t(label)}</button>)}</nav>

   <ol className="ia-steps" role="tabpanel">
    {steps[device].map((step,index)=><li key={step.title} className="ia-step"><span className="ia-step-number" aria-label={t('step')+' '+(index+1)}>{index+1}</span><span className="ia-tile">{step.tile}</span><div className="ia-step-body"><strong>{t(step.title)}</strong><Sentence text={t(step.text)} chip={step.chip}/><Shot src={step.shot} alt={t(step.title)}/></div></li>)}
    <li className="ia-step is-done"><span className="ia-step-number"><StudioIcon name="check"/></span><span className="ia-tile">{computer?<Window/>:<HomeBox/>}</span><div className="ia-step-body"><strong>{t(computer?'doneComputer':'done')}<img className="ia-done-logo" src={icon} alt=""/></strong><p>{t(computer?'doneComputerText':'doneText')}</p></div></li>
   </ol>
   {device==='android'&&<p className="ia-note"><StudioIcon name="info"/>{t('samsung')}</p>}
   {device==='iphone'&&<p className="ia-note"><StudioIcon name="info"/>{t('iosNote')}</p>}

   <section className="ia-tips"><h3><StudioIcon name="sparkles"/>{t('tipsTitle')}</h3><ul><li>{t('tipSame')}</li><li>{t('tipInternet')}</li><li>{t('tipRemove')}</li></ul></section>
  </div>
  <footer className="accounts-dialog-footer ia-footer"><button type="button" className="media-button primary" onClick={onClose}>{t('close')}</button></footer>
 </MediaDialog>;
}

/** Sidebar-footer entry for customers. */
export function InstallAppEntry(){
 const {t}=useInstallText();const {app}=usePage<SharedData>().props;const [open,setOpen]=useState(false);const asApp=useRunsAsApp();
 if(asApp)return null;
 return <><button type="button" className="ia-entry" title={t('entry')} onClick={()=>setOpen(true)}><span className="ia-entry-icon"><img src={app?.favicon||'/favicon.svg'} alt=""/></span><span className="ia-entry-text"><strong>{t('entry')}</strong><small>{t('entryHint')}</small></span><StudioIcon name="download"/></button><InstallAppDialog open={open} onClose={()=>setOpen(false)}/></>;
}

/** Login-page link. */
export function InstallAppLink(){
 const {t}=useInstallText();const [open,setOpen]=useState(false);const asApp=useRunsAsApp();
 if(asApp)return null;
 return <><button type="button" className="ia-login-link" onClick={()=>setOpen(true)}><StudioIcon name="download"/><span>{t('entry')}</span><small>{t('entryHint')}</small></button><InstallAppDialog open={open} onClose={()=>setOpen(false)}/></>;
}
