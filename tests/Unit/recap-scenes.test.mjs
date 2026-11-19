import assert from 'node:assert/strict';
import {test} from 'node:test';
import {identifyScenes,reorderScene,sceneTimeline,sourceAt,splitScene,fitSceneTiming,trimScene} from '../../resources/js/lib/recap-scenes.ts';
const movie={workflow:'movie',max_video_speed:1.35};
const make=()=>identifyScenes([
 {scene_id:'scene-a',index:0,start:0,end:15,text:'A brave traveler enters the forest.',subtitle_text:'ခရီးသည်တစ်ဦး တောထဲသို့ ဝင်လာသည်။',planned_duration:20,note:'Keep this note with A',included:true},
 {scene_id:'scene-b',index:1,start:240,end:260,text:'The traveler returns safely to the village.',planned_duration:40,note:'Keep this note with B',included:true}
]);
test('scene identity and notes survive reordering, editing, exclusion and reloading',()=>{
 const original=make(),ordered=reorderScene(original,'scene-a',1);
 assert.equal(ordered[0].scene_id,'scene-b');assert.equal(ordered[0].note,'Keep this note with B');assert.equal(ordered[1].note,'Keep this note with A');assert.equal(ordered[1].text,original[0].text);
 ordered[1]={...ordered[1],note:'Edited A',included:false};
 const reloaded=identifyScenes(JSON.parse(JSON.stringify(ordered)));assert.equal(reloaded[1].scene_id,'scene-a');assert.equal(reloaded[1].note,'Edited A');assert.equal(reloaded[0].note,'Keep this note with B');assert.equal(original[0].note,'Keep this note with A');
});
test('a five-minute source produces a sixty-second recap timeline with correct seek mapping',()=>{
 const clips=sceneTimeline(make(),movie);assert.equal(clips.at(-1).end,60);assert.equal(clips[1].start,20);assert.equal(sourceAt(clips[1],40),250);assert.equal(sourceAt(clips[0],10),7.5);
 const omitted=make();omitted[0].included=false;assert.equal(sceneTimeline(omitted,movie)[0].start,0);
});
test('subtitle mode retains source time and gaps while movie mode omits the gaps',()=>{
 const rows=make();assert.equal(sceneTimeline(rows,{...movie,workflow:'subtitles'})[1].start,240);assert.equal(sceneTimeline(rows,movie)[1].start,20);
});
test('splitting preserves notes and divides text without losing Myanmar graphemes',()=>{
 const rows=make(),split=splitScene(rows,'scene-a',7.5);assert.equal(split.length,3);assert.equal(split[0].end,split[1].start);assert.equal(split[0].note,split[1].note);assert.notEqual(split[0].scene_id,split[1].scene_id);assert.equal(split[0].planned_duration+split[1].planned_duration,20);assert.equal((split[0].subtitle_text+split[1].subtitle_text).replace(/\s/g,''),rows[0].subtitle_text.replace(/\s/g,''));assert.equal(rows.length,2);
 assert.throws(()=>splitScene(rows,'scene-a',0));assert.throws(()=>splitScene(rows,'scene-a',14.99));
});
test('locked scenes block reorder across them and cannot be trimmed or split',()=>{
 const rows=make();rows[1].locked=true;assert.equal(reorderScene(rows,'scene-a',1),rows);assert.deepEqual(trimScene(rows,'scene-b',241,250,300),rows);assert.equal(splitScene(rows,'scene-b',250),rows);
});
test('fit timing trims selected source ranges without rewriting notes or silently exceeding target',()=>{
 const rows=make(),fit=fitSceneTiming(rows,30,movie);assert.ok(Math.abs(sceneTimeline(fit,movie).at(-1).end-30)<.01);assert.equal(fit[1].note,rows[1].note);assert.equal(fit[0].text,rows[0].text);assert.ok(fit.every(s=>s.end>s.start));
 rows[1].locked=true;assert.throws(()=>fitSceneTiming(rows,30,movie));
});
test('trim rejects out-of-source and empty ranges and does not alter unrelated scenes',()=>{
 const rows=make();assert.throws(()=>trimScene(rows,'scene-a',-1,5,300));assert.throws(()=>trimScene(rows,'scene-a',4,4,300));assert.throws(()=>trimScene(rows,'scene-a',0,301,300));
 const result=trimScene(rows,'scene-a',2,8,300);assert.equal(result[0].note,rows[0].note);assert.equal(result[1],rows[1]);assert.equal(rows[0].start,0);
});