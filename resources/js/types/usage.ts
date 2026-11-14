export interface UsageRow {id:number;code:string;kind:'processing'|'storage';status:string;revision:number;customer:{name:string;email:string};project:{title:string;duration?:number;file?:string;category?:string};package:{name:string;code:string;version:number;type?:string;definition?:Record<string,unknown>}|null;period_start:string|null;period_end:string|null;released_seconds?:number;released_videos?:number;reserved_seconds:number;charged_seconds:number;returned_seconds:number;reserved_videos:number;charged_videos:number;returned_videos:number;storage_bytes:number;created_at:string;updated_at:string;run_id:string|null;thumbnail:string|null;detail_url:string;adjust_url:string;can_adjust:boolean;}
export interface UsageFilters {q:string;package_id:string;kind:string;status:string;period:string;dates:string;from:string;to:string;sort:string;per_page:number;}
export interface UsageList {items:UsageCustomer[];summary:{seconds:number;videos:number;reserved_seconds:number;storage_bytes:number};packages:{id:number;name:string}[];today:string;timezone:string;pagination:{page:number;pages:number;total:number;from:number|null;to:number|null};filters:UsageFilters;endpoints:{index:string;export:string};}
export interface UsageDetails extends UsageRow {balances:{seconds:number;videos:number;reserved_seconds:number;reserved_videos:number};definition:{included_seconds?:number;included_minutes?:number;video_limit?:number|null}|null;current_period:{package:string;starts_at:string;ends_at:string;status:string}|null;runtime_seconds:number|null;files:{name:string;bytes:number;state:string;category:string}[];events:{id:number;kind:string;changes:Record<string,number>;actor_name:string|null;reason:string|null;created_at:string}[];period_status:string;}

export interface UsageVideo {id:number;project_id:string;title:string;duration:number|null;thumbnail:string|null;video_url:string;completed_at:string;detail_url:string;}
export interface UsageCustomer {
 id:number;customer:{name:string;email:string};package:{name:string;code:string;version:number;type?:string}|null;
 period_start:string|null;period_end:string|null;status:string;account_status:string;
 allowance:{seconds:number|null;videos:number|null;storage_bytes:number|null};
 used:{seconds:number;videos:number;storage_bytes:number};
 reserved:{seconds:number;videos:number;storage_bytes:number};
 remaining:{seconds:number|null;videos:number|null;storage_bytes:number|null};
 recent:UsageVideo[];recent_count:number;detail_url:string;
}
export interface CustomerUsageDetails {customer:UsageCustomer;records:UsageRow[];recent:UsageVideo[];records_pagination:{page:number;pages:number;total:number};recent_pagination:{page:number;pages:number;total:number};}
