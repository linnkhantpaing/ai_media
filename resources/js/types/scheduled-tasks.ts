export type TaskState='running'|'queued'|'failed'|'late'|'success'|'never';
export interface TaskRun {id:number;status:'queued'|'running'|'success'|'failed';trigger:'schedule'|'manual';requested_by:string|null;created_at:string|null;started_at:string|null;finished_at:string|null;duration_ms:number|null;output:string|null;error:string|null}
export interface ScheduledTask {key:string;cron:string;state:TaskState;next_run:string;last:TaskRun|null;current:TaskRun|null;last_success_at:string|null;day:{success:number;failed:number}}
export interface ScheduledOverview {scheduler:{state:'running'|'stopped'|'never';last_tick:string|null;seconds_ago:number|null;cron_line:string};workers:{online:number;state:'online'|'standby'|'starting'|'waiting'|'failed'|'manual';automatic:boolean;restart:string};tasks:ScheduledTask[];timezone:string;keep_days:number;refreshed_at:string}
export interface TaskHistory {task:string;items:TaskRun[];pagination:{page:number;pages:number;total:number};status:string}
