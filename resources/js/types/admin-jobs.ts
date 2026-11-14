import type {OperationJob} from './operations';
export interface AdminJob extends OperationJob {customer:{id:number;name:string;email:string};retryBlocked:string|null;audioFormat:string;resultsExpired:boolean;}
export interface AdminJobFilters {q:string;status:string;type:string;date:string;from:string;to:string;sort:string;per_page:number;page:number;}
export interface AdminJobList {
 items:AdminJob[];counts:Record<string,number>;filters:AdminJobFilters;
 pagination:{page:number;pages:number;total:number;from:number|null;to:number|null};
 today:string;timezone:string;refreshed_at:string;poll_milliseconds:number;
 worker:{enabled:boolean;supported:boolean;state:string;idleSeconds:number;errorCode:string|null};
 endpoints:{index:string;workers:string};
}
export interface AdminJobDetails extends AdminJob {
 admin_actions:{id:number;action:'retry'|'cancel';actor:string|null;reason:string;at:string}[];
 source:'local'|'youtube';settings:Record<string,string|boolean>;
 customer_url:string;
 usage:{package:{name:string;version:number}|null;reserved_seconds:number;charged_seconds:number;videos:number;reserved_videos:number;status:string|null;period_start:string|null;period_end:string|null;ledger_url:string};
}
