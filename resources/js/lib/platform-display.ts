import {usePage} from '@inertiajs/react';
import type {Locale,SharedData} from '@/types';
export function formatPlatformDate(value:string,locale:Locale,settings:SharedData['app'],time=false):string {
 const date=new Date(value);if(!Number.isFinite(date.getTime()))return '—';
 const language=locale==='my'?'my-MM':locale==='th'?'th-TH':'en-US';
 const options:Intl.DateTimeFormatOptions={timeZone:settings.timezone??'UTC',calendar:'gregory'};
 const fmt=settings.date_format??'medium';let result:string;
 if(fmt==='medium')result=new Intl.DateTimeFormat(language,{...options,dateStyle:'medium'}).format(date);
 else {const parts=new Intl.DateTimeFormat('en-GB',{...options,day:'2-digit',month:'2-digit',year:'numeric'}).formatToParts(date);const get=(key:string)=>parts.find(p=>p.type===key)?.value??'';result=fmt==='iso'?get('year')+'-'+get('month')+'-'+get('day'):fmt==='mdy'?get('month')+'/'+get('day')+'/'+get('year'):get('day')+'/'+get('month')+'/'+get('year');}
 return time?result+', '+new Intl.DateTimeFormat(language,{...options,hour:'2-digit',minute:'2-digit',hour12:settings.time_format==='12'}).format(date):result;
}
export function useDisplayDate(){const {app,locale}=usePage<SharedData>().props;return(value:string,time=true)=>formatPlatformDate(value,locale,app,time);}
