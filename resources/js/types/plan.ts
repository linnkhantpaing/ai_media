export type PlanWorkflow={access:'included'|'locked'|'hidden';minutes:number|null;max_video_seconds:number|null;max_target_seconds:number|null;credits:number;total:number|null;used:number;reserved:number;available:number|null};
export type PlanMetric={total:number|null;used:number;reserved:number;available:number|null;exceeded:number};
export type PersonalPackage={id?:number;name:string;code?:string;version:number;type:string};
export type PlanOverview={
 package:PersonalPackage|null;status:string;expiring:boolean;start:string|null;end:string|null;days_left:number;
 next:{package:PersonalPackage;start:string;end:string}|null;pending_payment:boolean;testing:boolean;updated_at:string;
 processing:PlanMetric;videos:PlanMetric;storage:PlanMetric;features:Record<string,boolean>;
 workflows:Record<'movie'|'translate'|'subtitles',PlanWorkflow>|null;credits:number;watermark:boolean;
 jobs:{running:number;queued:number;concurrency:number|null;queue_limit:number|null};
 limits:{duration:number|null;upload:number|null;retention_days:number|null;cleanup_enabled:boolean};
};
export type PlanExport={id:string;title:string;type:'movie'|'translate'|'subs';poster:string|null;duration?:number;completed_at:string;charged_seconds:number|null;counted_videos:number|null;outputs:string[];url:string;detail_url:string};
export type PlanProps={overview:PlanOverview;exports:{data:PlanExport[];current_page:number;last_page:number;total:number;from:number|null;to:number|null};filters:{q:string;type:string;period:string};availablePlans:{id:number;type?:string;name:string;currency:string;price:string;days:number;seconds:number;storage:number;videos:number|null}[]};
export type PlanDetail={export:PlanExport;package:{name:string;version:number}|null;period_start:string|null;period_end:string|null;events:{type:string;at:string}[]};
