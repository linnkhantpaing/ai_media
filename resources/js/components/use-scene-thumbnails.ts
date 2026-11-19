import {useEffect,useRef,useState,type RefObject} from 'react';
import type {Scene} from '@/lib/recap-scenes';

// Decode only visible clips, sequentially, without competing decoders per scene.
export function useSceneThumbnails(source:string|undefined,clips:{scene:Scene}[],viewport:RefObject<HTMLDivElement|null>){
 const cache=useRef(new Map<string,string>()), [images,setImages]=useState<Record<string,string>>({});
 const signature=clips.map(({scene:s})=>s.scene_id+':'+s.start+':'+s.end).join('|');
 useEffect(()=>{
  const box=viewport.current;if(!box||!source)return;
  const valid=new Set(clips.map(({scene:s})=>s.scene_id+':'+s.start+':'+s.end));
  setImages(previous=>Object.fromEntries(Object.entries(previous).filter(([key])=>valid.has(key))));
  let stopped=false,running=false;const pending=new Map<string,Scene>(),requested=new Set<string>();
  const video=document.createElement('video');video.muted=true;video.playsInline=true;video.preload='metadata';
  const canvas=document.createElement('canvas');canvas.width=160;canvas.height=90;
  let cancelWait:(()=>void)|undefined;
  function wait(event:string,action:()=>void){return new Promise<void>((resolve,reject)=>{
   const cleanup=()=>{clearTimeout(timer);video.removeEventListener(event,done);video.removeEventListener('error',fail);cancelWait=undefined;};
   const done=()=>{cleanup();resolve();},fail=()=>{cleanup();reject(new Error('Thumbnail unavailable'));};
   const timer=setTimeout(fail,8000);cancelWait=fail;video.addEventListener(event,done,{once:true});video.addEventListener('error',fail,{once:true});action();
  });}
  let ready:Promise<void>|null=null;
  async function pump(){
   if(running||stopped)return;running=true;
   while(pending.size&&!stopped){
    const [key,scene]=pending.entries().next().value!;pending.delete(key);
    try{
     ready??=wait('loadeddata',()=>{video.src=source!;video.load();});await ready;if(stopped)break;
     const at=Math.max(0,Math.min(video.duration-.05,scene.start+Math.min(.25,(scene.end-scene.start)/2)));
     if(Math.abs(video.currentTime-at)>.01)await wait('seeked',()=>{video.currentTime=at;});
     if(stopped)break;const context=canvas.getContext('2d');if(!context||!video.videoWidth)continue;
     context.fillStyle='#070910';context.fillRect(0,0,160,90);const scale=Math.min(160/video.videoWidth,90/video.videoHeight);
     context.drawImage(video,(160-video.videoWidth*scale)/2,(90-video.videoHeight*scale)/2,video.videoWidth*scale,video.videoHeight*scale);
     const data=canvas.toDataURL('image/jpeg',.65);cache.current.set(key,data);if(cache.current.size>240)cache.current.delete(cache.current.keys().next().value!);
     setImages(previous=>({...previous,[scene.scene_id+':'+scene.start+':'+scene.end]:data}));
    }catch{if(stopped)break;}
   }
   running=false;
  }
  const scenes=new Map(clips.map(({scene})=>[scene.scene_id,scene]));
  const observer=new IntersectionObserver(entries=>{for(const entry of entries){if(!entry.isIntersecting)continue;const scene=scenes.get((entry.target as HTMLElement).dataset.thumbnailScene!);if(!scene)continue;const id=scene.scene_id+':'+scene.start+':'+scene.end,key=source+'|'+id;if(requested.has(key))continue;requested.add(key);const cached=cache.current.get(key);if(cached)setImages(previous=>({...previous,[id]:cached}));else pending.set(key,scene);}void pump();},{root:box,rootMargin:'0px 180px'});
  box.querySelectorAll('[data-thumbnail-scene]').forEach(el=>observer.observe(el));
  return()=>{stopped=true;observer.disconnect();pending.clear();cancelWait?.();video.pause();video.removeAttribute('src');video.load();};
 },[source,signature,viewport]);
 return images;
}
