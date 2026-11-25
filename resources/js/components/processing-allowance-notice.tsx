import {Link} from '@inertiajs/react';
import {StudioIcon} from '@/components/studio-icon';
import {useUsageText} from '@/lib/usage-i18n';
import type {MediaProject} from '@/types/media';

/**
 * What this export will use from the plan, before processing starts. When the workflow has
 * its own minutes, the smaller of the workflow and package balances is shown.
 */
export function ProcessingAllowanceNotice({project,available,billingUrl}:{project:MediaProject;available?:number|null;billingUrl?:string}){
 const {t,locale}=useUsageText();if(!project.allowance)return null;
 const format=(value:number)=>new Intl.NumberFormat(locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US',{maximumFractionDigits:2}).format(value);
 const left=available==null?project.allowance.available_seconds:Math.min(available,project.allowance.available_seconds);
 const short=project.allowance.estimated_seconds>left;
 return <aside className={'processing-allowance-notice'+(short?' is-short':'')} role={short?'alert':undefined}><StudioIcon name={short?'alert':'info'}/><div><strong>{t('estimate')}: {format(project.allowance.estimated_seconds/60)} {t('minutes')} · {format(project.allowance.videos)} {t('videos')}</strong><span>{t('available')}: {format(left/60)} {t('minutes')}. {short?t('notEnough'):t('eachExport')}</span>{short&&billingUrl&&<Link href={billingUrl} className="processing-allowance-buy"><StudioIcon name="plus"/>{t('buyMinutes')}</Link>}</div></aside>;
}
