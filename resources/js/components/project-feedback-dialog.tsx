import {useEffect, useId, useRef, useState, type ReactNode} from 'react';
import {MediaDialog} from '@/components/media-workflow';
import {StudioIcon} from '@/components/studio-icon';
import {useMediaText} from '@/lib/media-i18n';
import '../../css/project-feedback.css';

const copy = {
    sourceTitle: ['Remove this source?', 'ဒီမူရင်းဗီဒီယိုကို ဖယ်မလား?', 'นำวิดีโอต้นฉบับนี้ออกหรือไม่?'],
    sourceHint: ['Remove “{title}” from this screen and choose another video? Your saved project and files will remain in All Projects.', '“{title}” ကို ဒီနေရာမှဖယ်ပြီး အခြားဗီဒီယို ရွေးမလား? သိမ်းထားသော ပရောဂျက်နှင့် ဖိုင်များကို All Projects မှာ ပြန်ကြည့်နိုင်ပါသည်။', 'นำ “{title}” ออกจากหน้านี้แล้วเลือกวิดีโอใหม่หรือไม่? โปรเจกต์และไฟล์ที่บันทึกไว้จะยังอยู่ในโปรเจกต์ทั้งหมด'],
    removeSource: ['Remove source', 'မူရင်းဗီဒီယို ဖယ်ရန်', 'นำต้นฉบับออก'],
    confirm: ['Move to Trash?', 'အမှိုက်ပုံးသို့ ရွှေ့မလား?', 'ย้ายไปถังขยะหรือไม่?'],
    confirmHint: ['Move “{title}” to Trash? This will permanently delete the project and its source, saved script, outputs and cached files. This cannot be undone.', '“{title}” ကို အမှိုက်ပုံးသို့ ရွှေ့မလား? ပရောဂျက်၊ မူရင်းဗီဒီယို၊ စာမူ၊ ထုတ်ထားသည့်ဖိုင်နှင့် cache များကို အပြီးတိုင်ဖျက်မည်။ ပြန်ယူ၍မရပါ။', 'ย้าย “{title}” ไปถังขยะหรือไม่? โปรเจกต์ วิดีโอต้นฉบับ บท ผลลัพธ์ และแคชจะถูกลบถาวร ไม่สามารถกู้คืนได้'],
    move: ['Move to Trash', 'အမှိုက်ပုံးသို့ ရွှေ့ရန်', 'ย้ายไปถังขยะ'],
    deleting: ['Deleting…', 'ဖျက်နေသည်…', 'กำลังลบ…'],
    cancel: ['Cancel', 'မလုပ်တော့ပါ', 'ยกเลิก'],
    videoTitle: ['Video Generated Successfully!', 'ဗီဒီယို အောင်မြင်စွာ ထုတ်ပြီးပါပြီ!', 'สร้างวิดีโอสำเร็จแล้ว!'],
    videoHint: ['Your recap video is ready to watch, download and share.', 'သင့် recap ဗီဒီယိုကို ကြည့်ရှု၊ ဒေါင်းလုဒ်နှင့် မျှဝေနိုင်ပါပြီ။', 'วิดีโอสรุปของคุณพร้อมรับชม ดาวน์โหลด และแชร์แล้ว'],
    watch: ['Watch Video', 'ဗီဒီယိုကြည့်ရန်', 'ดูวิดีโอ'],
    download: ['Download Video', 'ဗီဒီယိုဒေါင်းလုဒ်', 'ดาวน์โหลดวิดีโอ'],
    deletedTitle: ['Project Delete Successfully!', 'ပရောဂျက် အောင်မြင်စွာ ဖျက်ပြီးပါပြီ!', 'ลบโปรเจกต์สำเร็จแล้ว!'],
    deletedHint: ['The project has been permanently deleted from your workspace', 'ပရောဂျက်ကို သင့် workspace မှ အပြီးတိုင် ဖျက်ပြီးပါပြီ။', 'โปรเจกต์ถูกลบออกจากพื้นที่ทำงานของคุณอย่างถาวรแล้ว'],
    ok: ['OK', 'အိုကေ', 'ตกลง'],
};

type Props = {
    open: boolean; variant: 'confirm' | 'source' | 'video' | 'deleted'; onClose: () => void;
    projectTitle?: string; busy?: boolean; issue?: ReactNode; onConfirm?: () => void;
    onWatch?: () => void; downloadHref?: string;
};

export function ProjectFeedbackDialog({open, variant, onClose, projectTitle = '', busy = false, issue, onConfirm, onWatch, downloadHref}: Props) {
    const {locale} = useMediaText();
    const index = locale === 'my' ? 1 : locale === 'th' ? 2 : 0;
    const t = (key: keyof typeof copy) => copy[key][index];
    const [artReady, setArtReady] = useState(false);
    const descriptionId = useId();
    const closeRef = useRef(onClose);
    closeRef.current = onClose;
    useEffect(() => {
        if (!open || variant !== 'video' || !artReady) return;
        const timer = setTimeout(() => closeRef.current(), 3000);
        return () => clearTimeout(timer);
    }, [open, variant, artReady]);
    const confirmation = variant === 'confirm' || variant === 'source';
    const title = t(variant === 'source' ? 'sourceTitle' : variant === 'confirm' ? 'confirm' : variant === 'video' ? 'videoTitle' : 'deletedTitle');
    const illustration = confirmation ? 'project-trash-confirm' : variant === 'video' ? 'video-export-success' : 'project-delete-success';
    useEffect(() => {const image = new Image(); image.src = '/images/' + illustration + '.png';}, [illustration]);
    return <MediaDialog open={open} title={title} onClose={() => {if (!busy) onClose();}} animated className={'project-feedback is-' + variant + ' locale-' + locale} descriptionId={descriptionId}>
        <div className="project-feedback-content" aria-busy={busy}>
            <div className="project-feedback-art" aria-hidden="true"><img src={'/images/' + illustration + '.png'} alt="" width="200" height="200" draggable={false} onLoad={()=>setArtReady(true)} onError={()=>setArtReady(true)}/></div>
            <h3 aria-hidden="true">{title.split(' ').map((word,index)=><span key={index}>{index>0?' ':''}<span>{word}</span></span>)}</h3>
            <p id={descriptionId}>{confirmation ? t(variant === 'source' ? 'sourceHint' : 'confirmHint').replace('{title}', projectTitle) : t(variant === 'video' ? 'videoHint' : 'deletedHint')}</p>
            {issue && <div className="project-feedback-issue">{issue}</div>}
            <div className="project-feedback-actions">
                {confirmation ? <><button type="button" className="media-button" autoFocus onClick={onClose} disabled={busy}>{t('cancel')}</button><button type="button" className="media-button feedback-danger" onClick={onConfirm} disabled={busy}><StudioIcon name={busy ? 'loader' : 'trash'} className={busy ? 'is-spinning' : undefined}/>{t(busy ? 'deleting' : variant === 'source' ? 'removeSource' : 'move')}</button></> : variant === 'video' ? <><button type="button" className="media-button" onClick={onWatch}><StudioIcon name="play"/>{t('watch')}</button><a className="media-button feedback-success" href={downloadHref} onClick={onClose}><StudioIcon name="download"/>{t('download')}</a></> : <button type="button" autoFocus className="media-button feedback-success" onClick={onClose}>{t('ok')}</button>}
            </div>
            {variant === 'video' && artReady && <span key={String(open)} className="project-feedback-countdown" aria-hidden="true"/>}
        </div>
    </MediaDialog>;
}
