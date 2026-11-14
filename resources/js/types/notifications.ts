export type NoticeCategory='payments'|'jobs'|'subscriptions'|'system'|'support';
export type Notice={id:number;code:string;category:NoticeCategory;kind:string;priority:'critical'|'warning'|'info';issue:'attention'|'resolved'|'info';unread:boolean;archived:boolean;revision:number;customer:{name:string|null;email:string|null};reference:string;context:Record<string,string|number|boolean|null>;received_at:string;resolved_at:string|null;detail_url:string};
export type NoticeDetail=Notice&{target_type:string;related_url:string|null;current:Record<string,string|number|null>};
export type NoticeSummary={unread:number;attention:number;critical:number;today:number};
export type NoticeFeed={summary:NoticeSummary;items:Notice[]};
export type NoticeFilters={q:string;category:string;read:string;priority:string;issue:string;archived:boolean;dates:string;from:string;to:string;sort:string;per_page:number};
export type NoticeList={items:Notice[];summary:NoticeSummary;filters:NoticeFilters;tabs:Record<string,number>;today:string;pagination:{page:number;pages:number;total:number;from:number|null;to:number|null};endpoints:{index:string}};
export type NoticeAction='read'|'unread'|'archive'|'restore'|'read_all';
