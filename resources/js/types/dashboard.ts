export type DashboardFilters = {dates:string;from:string;to:string;currency:'MMK'|'THB'|'USD'};
export type DashboardData = {
 filters:DashboardFilters;
 summary:{active:number;gross:number;refunds:number;net:number;successful:number;failed:number;seconds:number};
 previous:null|{gross:number;refunds:number;net:number;successful:number;failed:number;seconds:number};
 range:{from:string;to:string;timezone:string};today:string;updated_at:string;
 trend:{group:string;points:{date:string;gross:number;refunds:number}[]};
 modes:{key:string;seconds:number}[];packages:{id:number|null;name:string|null;seconds:number;others?:boolean}[];package_total:number;
 recent:{id:number;title:string;customer:string;mode:string;status:string;duration:number|null;poster:string|null;url:string}[];
 attention:{key:string;count:number;url:string}[];
 system:{workers:{online:number;busy:number;idle:number;attention:number};processing:string;storage:number;budget:number;scheduler:{state:'running'|'stopped'|'never';failed:number};provider:string;provider_checked_at:string|null};
 links:Record<'index'|'users'|'payments'|'revenue'|'processing'|'jobs'|'workers'|'schedules'|'usage'|'support'|'storage'|'providers'|'notifications',string>;
};