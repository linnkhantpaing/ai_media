import {useEffect,useLayoutEffect,useMemo,useRef,useState,type CSSProperties,type ReactNode,type TextareaHTMLAttributes,type PointerEvent as ReactPointerEvent} from 'react';
import {useSceneThumbnails} from '@/components/use-scene-thumbnails';
import {ActionTooltip} from '@/components/action-tooltip';
import {MediaDialog} from '@/components/media-workflow';
import {StudioIcon,type IconName} from '@/components/studio-icon';
import {useRecapText} from '@/lib/recap-i18n';
import {liveCaption} from '@/lib/recap-preview';
import {useToast} from '@/lib/toast';
import {formatTime} from '@/lib/media-api';
import {clipAt,fitSceneTiming,identifyScenes,reorderScene,sceneSeconds,sceneTimeline,sourceAt,splitScene,trimScene,type Scene} from '@/lib/recap-scenes';
import type {MediaLine,MediaProject,MediaSettings} from '@/types/media';
import '../../css/review-scene-editor.css';

type Suggestion={sceneId:string;text:string;original:string};
type Props={project:MediaProject;lines:MediaLine[];settings:MediaSettings;selected:number;disabled:boolean;busy:boolean;dirty:boolean;navigation:ReactNode;suggestion:Suggestion|null;onDismissSuggestion:()=>void;onChange:(rows:Scene[],selected:number)=>void;onSelect:(index:number)=>void;onRewrite:(action:string)=>void;onDictionary:()=>void};
type Snapshot={rows:Scene[];selectedId:string};
function NumberField({value,onCommit,label,disabled=false,min=0,max=86400}:{value:number;onCommit:(value:number)=>void;label:string;disabled?:boolean;min?:number;max?:number}){
 const [text,setText]=useState(String(Number(value.toFixed(2))));
 useEffect(()=>setText(String(Number(value.toFixed(2)))),[value]);
 return <input type="number" aria-label={label} min={min} max={max} step="0.1" value={text} disabled={disabled} onClick={e=>e.stopPropagation()} onChange={e=>setText(e.target.value)} onKeyDown={e=>{if(e.key==='Enter')e.currentTarget.blur();}} onBlur={()=>{const n=Number(text);if(!text.trim()||!Number.isFinite(n)){setText(String(Number(value.toFixed(2))));return;}onCommit(n);setText(String(Number(value.toFixed(2))));}}/>;
}
function NarrationField(props:TextareaHTMLAttributes<HTMLTextAreaElement>){
 const field=useRef<HTMLTextAreaElement>(null);
 useLayoutEffect(()=>{
  const el=field.current;if(!el)return;
  const fit=()=>{el.style.height='auto';el.style.height=(el.scrollHeight+2)+'px';};
  fit();let width=el.clientWidth;
  const observer=new ResizeObserver(()=>{if(el.clientWidth!==width){width=el.clientWidth;fit();}});observer.observe(el);
  document.fonts.addEventListener('loadingdone',fit);
  return()=>{observer.disconnect();document.fonts.removeEventListener('loadingdone',fit);};
 },[props.value]);
 return <textarea {...props} ref={field} rows={2}/>;
}
export function ReviewSceneEditor(props:Props){
 const t=useRecapText(),toast=useToast();
 const rows=useMemo(()=>identifyScenes(props.lines),[props.lines]);
 const movie=props.settings.workflow==='movie',subtitles=props.settings.mode==='subs',duration=props.project.metadata?.duration??0;
 const clips=useMemo(()=>sceneTimeline(rows,props.settings),[rows,props.settings.workflow,props.settings.max_video_speed]);
 const total=movie?(clips.at(-1)?.end??0):duration,target=movie?(props.settings.target_seconds??total):duration;
 const selected=rows[props.selected]??rows[0],editable=!props.disabled,canChange=editable&&!selected?.locked;
 const [sourceMode,setSourceMode]=useState(false),[playing,setPlaying]=useState(false),[cursor,setCursor]=useState(0),[zoom,setZoom]=useState(1),[muted,setMuted]=useState(true),[excluded,setExcluded]=useState(false),[aiOpen,setAiOpen]=useState(false);
 const [past,setPast]=useState<Snapshot[]>([]),[future,setFuture]=useState<Snapshot[]>([]),[picker,setPicker]=useState<'add'|'replace'|null>(null),[fitOpen,setFitOpen]=useState(false),[fitRows,setFitRows]=useState<Scene[]|null>(null);
 const [draft,setDraft]=useState({start:0,end:10,text:'',subtitle:'',note:'',description:''}),[pickerError,setPickerError]=useState('');
 const [detailId,setDetailId]=useState<string|null>(null);
 const [trimDraft,setTrimDraft]=useState<{id:string;start:number;end:number}|null>(null);
 const video=useRef<HTMLVideoElement>(null),pickVideo=useRef<HTMLVideoElement>(null),menu=useRef<HTMLDivElement>(null),viewport=useRef<HTMLDivElement>(null),root=useRef<HTMLDivElement>(null);
 const current=useRef({rows,selected,clips,total,props});current.current={rows,selected,clips,total,props};
 const pickerPlaying=useRef(false);
 const playTime=useRef(0),clock=useRef(0),group=useRef({key:'',at:0}),seekGuard=useRef('');
 const [width,setWidth]=useState(800),[loadError,setLoadError]=useState(false);
 const thumbnails=useSceneThumbnails(props.project.links.source,clips,viewport);
 const audioOff=clips.length>0&&clips.every(c=>c.scene.audio_muted), captionsOff=clips.length>0&&clips.every(c=>c.scene.subtitle_hidden);
 const px=Math.max(1,(width-100)/Math.max(1,total))*zoom,timelineWidth=Math.max(width-100,total*px);
 const shown=rows.filter(s=>excluded?s.included===false:s.included!==false);
 function problem(error:unknown){toast.error(t(error instanceof Error?error.message:'Choose a valid source range.'));}
 function commit(next:Scene[],id=selected?.scene_id,key=''){
  if(!editable||next===current.current.rows||JSON.stringify(next)===JSON.stringify(current.current.rows))return;
  const now=Date.now();if(!key||group.current.key!==key||now-group.current.at>800)setPast(items=>[...items,{rows:current.current.rows,selectedId:current.current.selected?.scene_id??''}].slice(-60));
  group.current={key,at:now};setFuture([]);setPlaying(false);
  props.onChange(next.map((s,index)=>({...s,index})),Math.max(0,next.findIndex(s=>s.scene_id===id)));
 }
 function patch(id:string,changes:Partial<Scene>,key=''){
  const scene=rows.find(s=>s.scene_id===id);if(!scene||scene.locked&&!('locked'in changes)&&!('audio_muted'in changes)&&!('subtitle_hidden'in changes))return;
  commit(rows.map(s=>s.scene_id===id?{...s,...changes}:s),id,key);
 }
 function toggleTrack(kind:'audio_muted'|'subtitle_hidden'){const off=kind==='audio_muted'?audioOff:captionsOff;commit(rows.map(scene=>({...scene,[kind]:!off})));}
 function history(back:boolean){
  const stack=back?past:future,item=stack.at(-1);if(!item||!editable)return;
  const snapshot={rows,selectedId:selected?.scene_id??''};
  if(back){setPast(stack.slice(0,-1));setFuture(items=>[...items,snapshot]);}else{setFuture(stack.slice(0,-1));setPast(items=>[...items,snapshot]);}
  setPlaying(false);group.current={key:'',at:0};props.onChange(item.rows,Math.max(0,item.rows.findIndex(s=>s.scene_id===item.selectedId)));
 }
 function sync(time:number,selectScene=true){
  const state=current.current,v=video.current;if(!v)return;
  const clip=clipAt(state.clips,time);
  const src=clip?sourceAt(clip,time):time;
  const speed=clip?(clip.scene.end-clip.scene.start)/clip.duration:1;
  v.playbackRate=Math.max(.0625,Math.min(16,speed));
  const clipKey=clip?.scene.scene_id??'gap';
  if(Number.isFinite(src)&&(!v.seeking&&Math.abs(v.currentTime-src)>.3||seekGuard.current!==clipKey)){
   v.currentTime=Math.max(0,Math.min(duration-.01,src));seekGuard.current=clipKey;
  }
  if(selectScene&&clip&&state.props.selected!==clip.index)state.props.onSelect(clip.index);
 }
 function seek(time:number){const next=Math.max(0,Math.min(total,time));playTime.current=next;clock.current=performance.now();setCursor(next);if(!sourceMode)sync(next);}
 function choose(scene:Scene){props.onSelect(scene.index);if(!sourceMode){const clip=clips.find(c=>c.scene.scene_id===scene.scene_id);if(clip)seek(clip.start);}else if(video.current)video.current.currentTime=scene.start;}
 function togglePlay(){if(!video.current)return;if(sourceMode){if(video.current.paused)void video.current.play().catch(()=>setPlaying(false));else video.current.pause();return;}if(cursor>=total-.02)seek(0);setPlaying(v=>!v);}

 useEffect(()=>{
  const el=root.current,table=el?.querySelector('table'),pane=el?.querySelector('.review-script-pane');if(!el||!table||!pane)return;
  const measure=()=>{const heights=[...table.querySelectorAll('tbody tr')].slice(0,10).reduce((height,row)=>height+row.getBoundingClientRect().height,0);const headers=(table.querySelector('thead')?.getBoundingClientRect().height??0)+(pane.querySelector('header')?.getBoundingClientRect().height??0);el.style.setProperty('--review-pane-height',Math.ceil(Math.max(600,Math.min(1100,heights+headers+6)))+'px');};
  const observer=new ResizeObserver(measure);observer.observe(table);observer.observe(pane);measure();return()=>observer.disconnect();
 },[]);
 useEffect(()=>{const box=viewport.current;if(!box)return;const observer=new ResizeObserver(entries=>setWidth(entries[0].contentRect.width));observer.observe(box);return()=>observer.disconnect();},[]);
 useLayoutEffect(()=>{
  if(!aiOpen)return;
  const place=()=>{const anchor=menu.current,panel=anchor?.querySelector<HTMLElement>('[role="menu"]');if(!anchor||!panel)return;const bounds=anchor.getBoundingClientRect();const left=Math.max(12,Math.min(bounds.right-panel.offsetWidth,window.innerWidth-panel.offsetWidth-12));panel.style.left=(left-bounds.left)+'px';panel.style.right='auto';};
  place();window.addEventListener('resize',place);return()=>window.removeEventListener('resize',place);
 },[aiOpen]);
 useEffect(()=>{
  if(!aiOpen)return;const close=(event:PointerEvent)=>{if(!menu.current?.contains(event.target as Node))setAiOpen(false);};const escape=(event:KeyboardEvent)=>{if(event.key==='Escape')setAiOpen(false);};
  document.addEventListener('pointerdown',close);document.addEventListener('keydown',escape);return()=>{document.removeEventListener('pointerdown',close);document.removeEventListener('keydown',escape);};
 },[aiOpen]);
 useEffect(()=>{
  const v=video.current;if(!v||sourceMode)return;seekGuard.current='';
  const selectedClip=clips.find(c=>c.scene.scene_id===current.current.selected?.scene_id);
  const active=clipAt(clips,playTime.current);
  const time=selectedClip&&active?.scene.scene_id!==selectedClip.scene.scene_id?selectedClip.start:Math.min(playTime.current,total);
  playTime.current=time;setCursor(time);sync(time,false);
 },[clips,sourceMode]);
 useEffect(()=>{
  const v=video.current;if(!v||sourceMode)return;
  if(!playing){v.pause();return;}
  let frame=0,stopped=false;clock.current=performance.now();
  void v.play().catch(()=>{if(!stopped)setPlaying(false);});
  const tick=(now:number)=>{
   if(stopped)return;
   const delta=(now-clock.current)/1000;clock.current=now;
   if(v.readyState>=2&&!v.seeking)playTime.current=Math.min(current.current.total,playTime.current+delta);
   setCursor(playTime.current);sync(playTime.current);
   if(playTime.current>=current.current.total){setPlaying(false);v.pause();return;}
   if(v.paused&&!v.ended)void v.play().catch(()=>setPlaying(false));
   if(v.ended&&playTime.current<current.current.total){sync(playTime.current);void v.play().catch(()=>setPlaying(false));}
   frame=requestAnimationFrame(tick);
  };frame=requestAnimationFrame(tick);
  return()=>{stopped=true;cancelAnimationFrame(frame);v.pause();};
 },[playing,sourceMode]);
 useEffect(()=>{if(playTime.current>total)seek(total);},[total]);
 useEffect(()=>{if(!selected)return;const row=root.current?.querySelector<HTMLElement>('[data-scene-id="'+selected.scene_id+'"]'),pane=row?.closest('.review-script-scroll');if(row&&pane){const top=row.offsetTop,bottom=top+row.offsetHeight;if(top<pane.scrollTop+25)pane.scrollTop=Math.max(0,top-25);else if(bottom>pane.scrollTop+pane.clientHeight)pane.scrollTop=bottom-pane.clientHeight;}},[props.selected]);
 const activeClip=clipAt(clips,cursor),caption=activeClip?liveCaption([{...activeClip.scene,start:activeClip.start,end:activeClip.end}],cursor):'';
 const number=(n:number)=>String(Number(n.toFixed(2)));
 function icon(label:string,name:IconName,click:()=>void,disabled=false){return <ActionTooltip label={t(label)}><button type="button" className="review-icon" aria-label={t(label)} disabled={disabled} onClick={click}><StudioIcon name={name}/></button></ActionTooltip>;}
 function move(delta:number){if(selected&&movie)commit(reorderScene(rows,selected.scene_id,selected.index+delta));}
 function trim(id:string,start:number,end:number){try{
  const i=rows.findIndex(s=>s.scene_id===id);
  if(!movie&&(start<(rows[i-1]?.end??0)||end>(rows[i+1]?.start??duration)))throw new Error('Dialogue clips cannot overlap.');
  commit(trimScene(rows,id,start,end,duration),id);
 }catch(error){problem(error);}}
 function split(){if(!selected)return;try{const clip=clips.find(c=>c.scene.scene_id===selected.scene_id);const time=sourceMode?(video.current?.currentTime??selected.start):clip?sourceAt(clip,cursor):selected.start;commit(splitScene(rows,selected.scene_id,time));}catch(error){problem(error);}}
 function openPicker(kind:'add'|'replace'){
  if(!selected)return;setPlaying(false);setPickerError('');pickerPlaying.current=false;
  const start=kind==='replace'?selected.start:selected.end<duration-.5?selected.end:0;
  setDraft({start,end:kind==='replace'?selected.end:Math.min(duration,start+10),text:kind==='replace'?selected.text:'',subtitle:kind==='replace'?selected.subtitle_text??selected.text:'',note:kind==='replace'?selected.note??'':'',description:kind==='replace'?selected.source_text:''});setPicker(kind);
 }
 function insert(){
  if(!Number.isFinite(draft.start)||!Number.isFinite(draft.end)||draft.start<0||draft.end>duration||draft.end-draft.start<.25){setPickerError(t('Choose a valid source range.'));return;}
  if(!draft.text.trim()){setPickerError(t('Enter narration text.'));return;}
  if(!selected)return;
  if(picker==='replace'){
   const next=rows.map(s=>s.scene_id===selected.scene_id?{...s,start:draft.start,end:draft.end,text:draft.text.trim(),subtitle_text:props.settings.subtitle_lang==='same'?draft.text.trim():draft.subtitle.trim()||draft.text.trim(),note:draft.note,source_text:draft.description.trim()||s.source_text}:s);commit(next);
  }else{
   if(rows.length>=120){setPickerError(t('A recap can contain up to 120 scenes.'));return;}
   const scene:Scene={scene_id:crypto.randomUUID(),index:0,start:draft.start,end:draft.end,planned_duration:Math.min(180,draft.end-draft.start),source_text:draft.description.trim()||t('Manually selected source clip'),text:draft.text.trim(),subtitle_text:draft.subtitle.trim()||draft.text.trim(),note:draft.note,included:true,locked:false};
   const next=[...rows];next.splice(selected.index+1,0,scene);commit(next,scene.scene_id);
  }setPicker(null);setExcluded(false);
 }
 function beginTrim(event:ReactPointerEvent<HTMLButtonElement>,scene:Scene,edge:'start'|'end',clipWidth:number){
  if(!editable||scene.locked)return;event.preventDefault();event.stopPropagation();event.currentTarget.setPointerCapture(event.pointerId);
  const initial=event.clientX,ratio=(scene.end-scene.start)/Math.max(1,clipWidth),button=event.currentTarget;
  const bounds=(x:number)=>{const shift=(x-initial)*ratio;return {id:scene.scene_id,start:edge==='start'?Math.max(0,Math.min(scene.end-.1,scene.start+shift)):scene.start,end:edge==='end'?Math.min(duration,Math.max(scene.start+.1,scene.end+shift)):scene.end};};
  const move=(moveEvent:PointerEvent)=>setTrimDraft(bounds(moveEvent.clientX));
  const finish=(endEvent:PointerEvent)=>{const draft=bounds(endEvent.clientX);trim(scene.scene_id,draft.start,draft.end);cleanup();};
  const cleanup=()=>{setTrimDraft(null);button.removeEventListener('pointermove',move);button.removeEventListener('pointerup',finish);button.removeEventListener('pointercancel',cleanup);};button.addEventListener('pointermove',move);button.addEventListener('pointerup',finish,{once:true});button.addEventListener('pointercancel',cleanup,{once:true});
 }
 const sceneActions=(<div className="review-actions">
 {icon('Undo','undo',()=>history(true),!editable||!past.length)}{icon('Redo','redo',()=>history(false),!editable||!future.length)}<i/>
 {movie&&<><button type="button" className="review-small-action" disabled={!canChange||rows.length>=120} onClick={()=>openPicker('add')}><StudioIcon name="plus"/>{t('Add Scene')}</button>{icon('Replace Scene','swap',()=>openPicker('replace'),!canChange)}{icon('Move up','move-up',()=>move(-1),!canChange||selected?.index===0||!!rows[(selected?.index??0)-1]?.locked)}{icon('Move down','move-down',()=>move(1),!canChange||selected?.index===rows.length-1||!!rows[(selected?.index??0)+1]?.locked)}</>}
 {icon('Split at playhead','scissors',split,!canChange)}{icon(selected?.included===false?'Restore Scene':'Exclude Scene',selected?.included===false?'eye':'trash',()=>{if(selected)patch(selected.scene_id,{included:selected.included===false});},!canChange)}
 <div className="review-ai-menu" ref={menu}><button type="button" className="review-small-action" aria-haspopup="menu" aria-expanded={aiOpen} disabled={!canChange||props.busy} onClick={()=>setAiOpen(!aiOpen)}>{t('AI Tools')}<StudioIcon name="chevron"/></button>{aiOpen&&<div role="menu">{[['regenerate','Regenerate'],['hook','Improve Hook'],['shorten','Shorten'],['expand','Expand'],['names','Check Names']].map(([action,label])=><button key={action} type="button" role="menuitem" onClick={()=>{setAiOpen(false);props.onRewrite(action);}}>{t(label)}</button>)}<button type="button" role="menuitem" onClick={()=>{setAiOpen(false);props.onDictionary();}}>{t('Name Dictionary')}</button></div>}</div>
 </div>);
 return <div ref={root} className="review-workspace" onKeyDown={e=>{
  if(e.target instanceof HTMLElement&&e.target.closest('input,textarea,select,[contenteditable=true]'))return;
  if(e.code==='Space'&&!(e.target instanceof HTMLElement&&e.target.closest('button'))){e.preventDefault();togglePlay();}
  if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='z'){e.preventDefault();history(!e.shiftKey);}
 }}>
 <div className="review-commandbar"><div className="review-counters"><strong>{t('Review Script')}</strong><span>{clips.length} {t('Scenes')}</span><span>{t('Target')} <b>{formatTime(target)}</b></span><span className={Math.abs(total-target)>1?'review-warning':''}>{t('Planned')} <b>{formatTime(total)}</b></span><small className={props.dirty?'review-warning':'review-saved'}>{t(props.dirty?'Unsaved changes':'Saved')}</small></div>{props.navigation&&<div className="review-navigation">{props.navigation}</div>}</div>
 <div className="review-top"><section className="review-script-pane" aria-label={t('Script & Notes')}>
 <div className="review-script-scroll"><table><thead><tr><th>#</th><th className="review-time-column">{t('Start')}</th><th className="review-time-column">{t('End')}</th>{movie&&<th className="review-output-column">{t('Output')}</th>}<th>{t('Script & Notes')}</th><th><StudioIcon name="shield"/></th></tr></thead><tbody>{shown.map(scene=><tr key={scene.scene_id} data-scene-id={scene.scene_id} className={(selected?.scene_id===scene.scene_id?'active ':'')+(scene.included===false?'excluded':'')} onClick={()=>choose(scene)}><td><button type="button" className="review-scene-number" onClick={e=>{e.stopPropagation();choose(scene);}} aria-label={t('Scene')+' '+(scene.index+1)}>{scene.index+1}</button><input type="checkbox" aria-label={t('Included')+' '+(scene.index+1)} checked={scene.included!==false} disabled={!editable||scene.locked} onClick={e=>e.stopPropagation()} onChange={e=>patch(scene.scene_id,{included:e.target.checked})}/></td>
 <td className="review-time-cell"><NumberField label={t('Start')+' '+(scene.index+1)} value={scene.start} onCommit={start=>trim(scene.scene_id,start,scene.end)} disabled={!editable||scene.locked} max={duration}/></td><td className="review-time-cell"><NumberField label={t('End')+' '+(scene.index+1)} value={scene.end} onCommit={end=>trim(scene.scene_id,scene.start,end)} disabled={!editable||scene.locked} max={duration}/></td>
 {movie&&<td className="review-output-time"><NumberField label={t('Output seconds')+' '+(scene.index+1)} value={scene.planned_duration??scene.end-scene.start} onCommit={planned=>{if(planned<=0||planned>180){toast.error(t('Output must be between 0 and 180 seconds.'));return;}patch(scene.scene_id,{planned_duration:planned});}} disabled={!editable||scene.locked} min={.1} max={180}/><small>{t('sec')}</small></td>}
 <td className="review-text-cell"><NarrationField aria-label={t('Narration')+' '+(scene.index+1)} value={scene.text} maxLength={1500} disabled={!editable||scene.locked} onFocus={()=>choose(scene)} onClick={e=>e.stopPropagation()} onChange={e=>patch(scene.scene_id,{text:e.target.value,...(subtitles||props.settings.subtitle_lang==='same'?{subtitle_text:e.target.value}:{})},scene.scene_id+':text')}/>
 {detailId===scene.scene_id&&<div className="review-row-details">{!subtitles&&props.settings.subtitle_lang!=='same'&&<label>{t('Subtitles')}<textarea aria-label={t('Subtitles')} rows={2} maxLength={1500} value={scene.subtitle_text??scene.text} disabled={!editable||scene.locked} onClick={e=>e.stopPropagation()} onChange={e=>patch(scene.scene_id,{subtitle_text:e.target.value},scene.scene_id+':sub')}/></label>}<label>{t('Scene Note')}<textarea aria-label={t('Scene Note')} rows={1} maxLength={1000} placeholder={t('Optional direction for this scene…')} value={scene.note??''} disabled={!editable||scene.locked} onClick={e=>e.stopPropagation()} onChange={e=>patch(scene.scene_id,{note:e.target.value},scene.scene_id+':note')}/></label><details onClick={e=>e.stopPropagation()}><summary>{t('Source scene / transcript')}</summary><p>{scene.source_text}</p></details></div>}</td>
 <td>{icon('Scene Note','file',()=>{choose(scene);setDetailId(detailId===scene.scene_id?null:scene.scene_id);})}{icon(scene.locked?'Unlock Scene':'Lock Scene','shield',()=>patch(scene.scene_id,{locked:!scene.locked}),!editable)}</td></tr>)}</tbody></table>{!shown.length&&<p className="review-empty">{t('No scenes in this view.')}</p>}</div></section>
 <section className="review-player-pane"><header><div className="review-view-toggle"><button type="button" className={!sourceMode?'selected':''} onClick={()=>{setPlaying(false);setSourceMode(false);}}>{t('Recap Preview')}</button><button type="button" className={sourceMode?'selected':''} onClick={()=>{setPlaying(false);setSourceMode(true);if(video.current)video.current.playbackRate=1;}}>{t('Original Source')}</button></div>{icon('Fullscreen','monitor',()=>void video.current?.requestFullscreen())}</header>
 <div className="review-player-stage"><video ref={video} src={props.project.links.source} playsInline preload="auto" muted={muted||(!sourceMode&&(audioOff||!!activeClip?.scene.audio_muted))} controls={sourceMode} onLoadedMetadata={()=>{setLoadError(false);if(!sourceMode)sync(playTime.current);}} onError={()=>{setPlaying(false);setLoadError(true);}} onClick={()=>{if(!sourceMode)togglePlay();}}/>{!sourceMode&&caption&&<div className="review-live-caption">{caption}</div>}{loadError&&<span className="review-player-error">{t('Video could not be loaded.')}</span>}</div>
 <div className="review-player-details">{!sourceMode&&<div className="review-transport">{icon(playing?'Pause':'Play',playing?'pause':'play',togglePlay,!total||loadError)}<span>{formatTime(cursor)} <small>/ {formatTime(total)}</small></span><input aria-label={t('Recap playhead')} type="range" min={0} max={Math.max(.01,total)} step=".01" value={Math.min(cursor,total)} onChange={e=>seek(Number(e.target.value))}/>{icon(muted?'Enable source audio':'Mute source audio','headphones',()=>setMuted(!muted))}</div>}

 {selected&&<div className="review-selected-info"><strong>{t('Scene')} {selected.index+1}</strong>{icon(sourceMode?'Original source video. Select clips for the recap.':'Clip timing preview · original audio. Final voice timing is checked during rendering.','info',()=>{})}<span>{formatTime(selected.start)} → {formatTime(selected.end)}</span><span>{t('Planned')} {number(sceneSeconds(selected,props.settings))}s</span></div>}
 <div className="review-target-meter"><div><span>{t('Target')} {formatTime(target)}</span><span>{t('Planned')} {formatTime(total)}</span></div><progress value={Math.min(total,target)} max={Math.max(.01,target)}/>{movie&&<button type="button" className="review-small-action" disabled={!editable} onClick={()=>{try{setFitRows(fitSceneTiming(rows,target,props.settings));setFitOpen(true);}catch(error){problem(error);}}}><StudioIcon name="sliders"/>{t('Fit clip timing to target')}</button>}</div></div>
 </section></div>
 <section className="review-timeline" aria-label={t('Timeline')}><header><div><span>{t('Source')} {formatTime(duration)}</span><span>{t('Recap')} <b>{formatTime(total)}</b></span>{trimDraft&&<span>{formatTime(trimDraft.start)} → {formatTime(trimDraft.end)}</span>}<button type="button" className={excluded?'selected':''} onClick={()=>setExcluded(!excluded)}>{t(excluded?'Show included':'Excluded')} · {rows.filter(s=>s.included===false).length}</button></div><div className="review-timeline-controls">{sceneActions}<label>{t('Zoom')}<input type="range" min={1} max={8} step=".25" value={zoom} onChange={e=>setZoom(Number(e.target.value))}/></label>{icon('Fit timeline','reset',()=>setZoom(1))}</div></header>
 <div className="review-timeline-viewport" ref={viewport}><div className="review-timeline-inner" style={{width:timelineWidth+100}}><div className="review-ruler"><span>{t('Recap time')}</span><div style={{width:timelineWidth}} onClick={e=>{const rect=e.currentTarget.getBoundingClientRect();seek((e.clientX-rect.left)/px);}}>{Array.from({length:11},(_,i)=><small key={i} style={{left:(i*10)+'%'}}>{formatTime(total*i/10)}</small>)}</div></div>
 {(['Video','Audio','Subtitles'] as const).map(name=><div className={'review-track track-'+name.toLowerCase()} key={name}><div className="review-track-label"><span>{t(name==='Audio'&&!subtitles?'Narration':name)}</span>{name==='Video'?<StudioIcon name="play"/>:icon(name==='Audio'?(audioOff?'Unmute all audio':'Mute all audio'):(captionsOff?'Show all subtitles':'Hide all subtitles'),name==='Audio'?(audioOff?'volume-off':'volume'):captionsOff?'eye-off':'eye',()=>toggleTrack(name==='Audio'?'audio_muted':'subtitle_hidden'),!editable)}</div><div className="review-track-lane" style={{width:timelineWidth}}>{clips.map(clip=>{const off=name==='Audio'?clip.scene.audio_muted:name==='Subtitles'?clip.scene.subtitle_hidden:false;const image=thumbnails[clip.scene.scene_id+':'+clip.scene.start+':'+clip.scene.end];return <div key={clip.scene.scene_id} data-thumbnail-scene={name==='Video'?clip.scene.scene_id:undefined} className={'review-clip'+(selected?.scene_id===clip.scene.scene_id?' selected':'')+(clip.scene.locked?' locked':'')+(off?' track-disabled':'')} style={{left:clip.start*px,width:Math.max(2,clip.duration*px),...(name==='Video'&&image?{backgroundImage:'linear-gradient(0deg,#050610dc,transparent 65%),url("'+image+'")'}:{})} as CSSProperties} draggable={name==='Video'&&movie&&editable&&!clip.scene.locked} onDragStart={e=>e.dataTransfer.setData('text/x-recap-scene',clip.scene.scene_id)} onDragOver={e=>{if(name==='Video'&&movie&&editable&&!clip.scene.locked)e.preventDefault();}} onDrop={e=>{e.preventDefault();const id=e.dataTransfer.getData('text/x-recap-scene');if(id&&movie)commit(reorderScene(rows,id,clip.index),id);}} onClick={()=>choose(clip.scene)} role="button" tabIndex={0} aria-label={t(name)+' · '+t('Scene')+' '+(clip.index+1)} onKeyDown={e=>{if(e.key==='Enter'&&e.target===e.currentTarget)choose(clip.scene);}}>
 {name==='Video'?<>{selected?.scene_id===clip.scene.scene_id&&editable&&!clip.scene.locked&&<button type="button" className="review-trim-handle start" aria-label={t('Trim start')} onPointerDown={e=>beginTrim(e,clip.scene,'start',clip.duration*px)}/>}<span>{clip.index+1} · {trimDraft?.id===clip.scene.scene_id?formatTime(trimDraft.end-trimDraft.start):formatTime(clip.duration)}</span>{selected?.scene_id===clip.scene.scene_id&&editable&&!clip.scene.locked&&<button type="button" className="review-trim-handle end" aria-label={t('Trim end')} onPointerDown={e=>beginTrim(e,clip.scene,'end',clip.duration*px)}/>}</>:<><ActionTooltip label={t(name==='Audio'?(off?'Unmute scene audio':'Mute scene audio'):(off?'Show scene subtitles':'Hide scene subtitles'))+' '+(clip.index+1)}><button type="button" className="review-track-switch" disabled={!editable} aria-label={t(name==='Audio'?(off?'Unmute scene audio':'Mute scene audio'):(off?'Show scene subtitles':'Hide scene subtitles'))+' '+(clip.index+1)} aria-pressed={!!off} onClick={e=>{e.stopPropagation();patch(clip.scene.scene_id,name==='Audio'?{audio_muted:!off}:{subtitle_hidden:!off});}}><StudioIcon name={name==='Audio'?(off?'volume-off':'volume'):off?'eye-off':'eye'}/></button></ActionTooltip><span className="review-clip-text">{name==='Audio'?clip.scene.text:clip.scene.subtitle_text??clip.scene.text}</span></>}</div>;})}</div></div>)}
 <div className="review-playhead" style={{left:100+Math.min(cursor,total)*px}}><i/></div></div></div></section>
 <MediaDialog open={picker!==null} title={t(picker==='replace'?'Replace Scene':'Add Scene')} onClose={()=>setPicker(null)}><div className="review-picker"><video ref={pickVideo} src={props.project.links.source} controls playsInline preload="metadata" onTimeUpdate={()=>{if(pickerPlaying.current&&pickVideo.current&&pickVideo.current.currentTime>=draft.end){pickVideo.current.pause();pickerPlaying.current=false;}}} onLoadedMetadata={()=>{if(pickVideo.current)pickVideo.current.currentTime=draft.start;}}/><div className="review-picker-times"><label>{t('Start')}<NumberField label={t('Clip start')} value={draft.start} onCommit={start=>setDraft(d=>({...d,start}))} max={duration}/><button type="button" onClick={()=>setDraft(d=>({...d,start:Math.round((pickVideo.current?.currentTime??0)*100)/100}))}>{t('Use playhead')}</button></label><label>{t('End')}<NumberField label={t('Clip end')} value={draft.end} onCommit={end=>setDraft(d=>({...d,end}))} max={duration}/><button type="button" onClick={()=>setDraft(d=>({...d,end:Math.round((pickVideo.current?.currentTime??0)*100)/100}))}>{t('Use playhead')}</button></label><button type="button" className="review-small-action" onClick={()=>{const v=pickVideo.current;if(v){pickerPlaying.current=true;v.currentTime=draft.start;void v.play().catch(()=>{});}}}>{t('Play selection')}</button></div>
 <label>{t('Narration')} *<textarea aria-label={t('New scene narration')} maxLength={1500} rows={3} value={draft.text} onChange={e=>{setDraft(d=>({...d,text:e.target.value}));setPickerError('');}}/></label><label>{t('Source scene / transcript')}<textarea rows={2} maxLength={1500} value={draft.description} onChange={e=>setDraft(d=>({...d,description:e.target.value}))}/></label>{props.settings.subtitle_lang!=='same'&&<label>{t('Subtitles')}<textarea maxLength={1500} rows={2} value={draft.subtitle} onChange={e=>setDraft(d=>({...d,subtitle:e.target.value}))}/></label>}<label>{t('Scene Note')}<textarea maxLength={1000} rows={1} value={draft.note} onChange={e=>setDraft(d=>({...d,note:e.target.value}))}/></label><small>{t('Select a real source range and write the narration. AI Tools can refine it after insertion.')}</small>{pickerError&&<p role="alert" className="review-warning">{pickerError}</p>}<div className="review-dialog-actions"><button type="button" className="media-button" onClick={()=>setPicker(null)}>{t('Cancel')}</button><button type="button" className="media-button primary" onClick={insert}>{t(picker==='replace'?'Replace Scene':'Insert Scene')}</button></div></div></MediaDialog>
 <MediaDialog open={fitOpen} title={t('Fit clip timing to target')} onClose={()=>setFitOpen(false)}><div className="review-picker"><p>{formatTime(total)} → <strong>{formatTime(target)}</strong></p><p>{t('This adjusts unlocked clip lengths and planned timing. Your script and notes are preserved; narration may need shortening. Locked scenes remain unchanged.')}</p><div className="review-dialog-actions"><button type="button" className="media-button" onClick={()=>setFitOpen(false)}>{t('Cancel')}</button><button type="button" className="media-button primary" onClick={()=>{if(fitRows)commit(fitRows);setFitOpen(false);}}>{t('Apply timing')}</button></div></div></MediaDialog>
 <MediaDialog open={!!props.suggestion} title={t('Review AI suggestion')} onClose={props.onDismissSuggestion}><div className="review-picker"><p className="review-suggestion-text">{props.suggestion?.text}</p><div className="review-dialog-actions"><button type="button" className="media-button" onClick={props.onDismissSuggestion}>{t('Cancel')}</button><button type="button" className="media-button primary" onClick={()=>{const suggestion=props.suggestion;if(!suggestion)return;const scene=rows.find(s=>s.scene_id===suggestion.sceneId);if(!scene||scene.locked||scene.text!==suggestion.original){toast.error(t('The scene has changed. Generate a new suggestion.'));return;}patch(scene.scene_id,{text:suggestion.text,...(subtitles||props.settings.subtitle_lang==='same'?{subtitle_text:suggestion.text}:{})});props.onDismissSuggestion();}}>{t('Apply suggestion')}</button></div></div></MediaDialog>
 </div>;
}