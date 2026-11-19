import type {MediaLine, MediaSettings} from '@/types/media';

export type Scene = MediaLine & {scene_id: string};
export type TimelineClip = {scene: Scene; index: number; start: number; end: number; duration: number};
const round = (n: number) => Math.round(n * 1000) / 1000;
export function identifyScenes(lines: MediaLine[]): Scene[] {
 const used = new Set<string>();
 return lines.map((line, index) => {
  const id = line.scene_id && !used.has(line.scene_id) ? line.scene_id : crypto.randomUUID(); used.add(id);
  return {...line, scene_id:id, index, note:line.note ?? '', included:line.included ?? true, locked:line.locked ?? false};
 });
}
export function sceneSeconds(line: MediaLine, settings: Pick<MediaSettings,'workflow'|'max_video_speed'>): number {
 const length = Math.max(.01,line.end-line.start);
 return settings.workflow === 'movie' ? Math.max(.01,line.planned_duration ?? length,length / Math.max(1,settings.max_video_speed)) : length;
}
export function sceneTimeline(lines: Scene[], settings: Pick<MediaSettings,'workflow'|'max_video_speed'>): TimelineClip[] {
 let cursor=0;
 return lines.flatMap((scene,index) => {
  if(scene.included===false)return [];
  const start=settings.workflow==='movie'?cursor:scene.start;
  const duration=sceneSeconds(scene,settings);cursor=start+duration;
  return [{scene,index,start,end:cursor,duration}];
 });
}
export function clipAt(clips: TimelineClip[], time: number): TimelineClip|undefined {
 return clips.find(c=>time>=c.start&&time<c.end) ?? (time>= (clips.at(-1)?.end??0) ? clips.at(-1) : undefined);
}
export function sourceAt(clip: TimelineClip,time: number): number {
 return clip.scene.start+Math.min(1,Math.max(0,(time-clip.start)/clip.duration))*(clip.scene.end-clip.scene.start);
}
export function reorderScene(lines:Scene[],id:string,to:number):Scene[] {
 const from=lines.findIndex(s=>s.scene_id===id);to=Math.max(0,Math.min(lines.length-1,to));
 if(from<0||lines.slice(Math.min(from,to),Math.max(from,to)+1).some(s=>s.locked))return lines;
 const next=[...lines];next.splice(to,0,next.splice(from,1)[0]);return next.map((s,index)=>({...s,index}));
}
export function trimScene(lines:Scene[],id:string,start:number,end:number,duration:number):Scene[] {
 if(!Number.isFinite(start)||!Number.isFinite(end)||start<0||end>duration||end-start<.1)throw new Error('Choose a valid source range.');
 return lines.map(s=>s.scene_id!==id||s.locked?s:{...s,start:round(start),end:round(end),planned_duration:s.planned_duration===undefined?undefined:round(Math.max(.1,s.planned_duration*(end-start)/(s.end-s.start)))});
}
function splitText(text:string,ratio:number):[string,string] {
 const segments=Array.from(new Intl.Segmenter(undefined,{granularity:'grapheme'}).segment(text),s=>s.segment);
 if(segments.length<2)throw new Error('Add more text before splitting this scene.');
 let position=Math.max(1,Math.min(segments.length-1,Math.round(segments.length*ratio)));
 const low=Math.max(1,position-12),high=Math.min(segments.length-1,position+12);
 for(let i=low;i<=high;i++){if(/\s/u.test(segments[i])&&Math.abs(i-segments.length*ratio)<Math.abs(position-segments.length*ratio)+2)position=i;}
 let left=segments.slice(0,position).join('').trim(),right=segments.slice(position).join('').trim();
 if(!left||!right){position=Math.max(1,Math.min(segments.length-1,Math.round(segments.length*ratio)));left=segments.slice(0,position).join('').trim();right=segments.slice(position).join('').trim();}
 if(!left||!right)throw new Error('Add more text before splitting this scene.');
 return [left,right];
}
export function splitScene(lines:Scene[],id:string,sourceTime:number):Scene[] {
 const i=lines.findIndex(s=>s.scene_id===id),scene=lines[i];if(!scene||scene.locked)return lines;
 if(sourceTime-scene.start<.25||scene.end-sourceTime<.25)throw new Error('Place the playhead inside the scene to split.');
 const ratio=(sourceTime-scene.start)/(scene.end-scene.start);
 const text=splitText(scene.text,ratio),sub=scene.subtitle_text?.trim()?splitText(scene.subtitle_text,ratio):text;
 const planned=scene.planned_duration??scene.end-scene.start;
 const left={...scene,end:round(sourceTime),text:text[0],subtitle_text:sub[0],planned_duration:round(planned*ratio)};
 const right={...scene,scene_id:crypto.randomUUID(),start:round(sourceTime),text:text[1],subtitle_text:sub[1],planned_duration:round(planned*(1-ratio))};
 return [...lines.slice(0,i),left,right,...lines.slice(i+1)].map((s,index)=>({...s,index}));
}
export function fitSceneTiming(lines:Scene[],target:number,settings:Pick<MediaSettings,'workflow'|'max_video_speed'>):Scene[] {
 const included=lines.filter(s=>s.included!==false),locked=included.filter(s=>s.locked).reduce((n,s)=>n+sceneSeconds(s,settings),0);
 const editable=included.filter(s=>!s.locked);const remaining=target-locked;
 if(!editable.length||remaining<editable.length*.25||remaining>editable.length*180)throw new Error('The target cannot fit the locked scenes.');
 const sum=editable.reduce((n,s)=>n+sceneSeconds(s,settings),0);
 const times=editable.map(s=>({id:s.scene_id,seconds:.25+(remaining-editable.length*.25)*sceneSeconds(s,settings)/sum}));
 if(times.some(t=>t.seconds>180))throw new Error('Add another scene for this target duration.');
 return lines.map(s=>{
  const allocation=times.find(t=>t.id===s.scene_id);if(!allocation)return s;
  const seconds=round(allocation.seconds),length=Math.min(s.end-s.start,seconds*Math.max(1,settings.max_video_speed));
  return {...s,end:round(s.start+length),planned_duration:seconds};
 });
}