import type {ReactNode} from 'react';
import {StudioIcon} from '@/components/studio-icon';
import {useRecapText} from '@/lib/recap-i18n';

const labels:Record<string,string>={queued:'Queued',checking:'Checking video',downloading:'Downloading video',preparing:'Preparing video',validating:'Validating video',uploading:'Uploading video',prepare:'Preparing video',transcribe:'Analyzing speech and scenes',context:'Building story context',translate:'Writing and translating script',separate:'Separating background audio',voice:'Generating voice and scenes',subtitles:'Preparing subtitles',render:'Rendering output',verify:'Verifying output',store:'Saving files',stopping:'Stopping',cancel_requested:'Stopping',retry_waiting:'Waiting to retry'};
export function processingLabel(stage:string){return labels[stage]??stage;}
export function RecapWorkProgress({stage,progress,detail,children}:{stage:string;progress:number;detail?:string;children?:ReactNode}){
 const t=useRecapText();const percent=Math.min(100,Math.max(0,Math.floor(Number.isFinite(progress)?progress:0)));
 return <div className="recap-work-progress" role="status" aria-live="polite"><div className="recap-work-heading"><StudioIcon name={percent===100?'check':'loader'} className={percent===100?'':'is-spinning'}/><strong>{t(processingLabel(stage))}</strong><b>{percent}%</b>{children}</div><progress max={100} value={percent} aria-label={t('Overall progress')}/>{detail&&<small>{detail}</small>}</div>;
}
