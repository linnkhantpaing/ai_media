import {StudioIcon} from '@/components/studio-icon';
import {useUsageText} from '@/lib/usage-i18n';
import type {MediaProject} from '@/types/media';
export function ProcessingAllowanceNotice({project}:{project:MediaProject}){
 const {t,locale}=useUsageText();if(!project.allowance)return null;
 const format=(value:number)=>new Intl.NumberFormat(locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US',{maximumFractionDigits:2}).format(value);
 return <aside className="processing-allowance-notice"><StudioIcon name="info"/><div><strong>{t('estimate')}: {format(project.allowance.estimated_seconds/60)} {t('minutes')} ? {format(project.allowance.videos)} {t('videos')}</strong><span>{t('available')}: {format(project.allowance.available_seconds/60)} {t('minutes')}. {t('eachExport')}</span></div></aside>;
}
