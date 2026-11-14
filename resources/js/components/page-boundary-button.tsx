import {usePage} from '@inertiajs/react';
import {ActionTooltip} from '@/components/action-tooltip';
import {StudioIcon} from '@/components/studio-icon';
import type {SharedData} from '@/types';
import '../../css/pagination.css';

const labels = {
 en: {first: 'First page', last: 'Last page'},
 my: {first: 'ပထမစာမျက်နှာသို့', last: 'နောက်ဆုံးစာမျက်နှာသို့'},
 th: {first: 'หน้าแรก', last: 'หน้าสุดท้าย'},
};

export function PageBoundaryButton({edge,page,pages,onNavigate,className='',disabled=false}:{
 edge:'first'|'last';page:number;pages:number;onNavigate:(page:number)=>void;className?:string;disabled?:boolean;
}){
 const {locale}=usePage<SharedData>().props;
 const label=(labels[locale]??labels.en)[edge];
 const last=Math.max(1,pages),target=edge==='first'?1:last;
 return <ActionTooltip label={label}><button type="button" className={'pagination-boundary-button '+className} aria-label={label}
  disabled={disabled||(edge==='first'?page<=1:page>=last)} onClick={()=>onNavigate(target)}>
  <StudioIcon name={edge==='first'?'first-page':'last-page'}/>
 </button></ActionTooltip>;
}
