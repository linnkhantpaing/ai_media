import assert from 'node:assert/strict';
import {test} from 'node:test';
import {isNewCompletedVideo} from '../../resources/js/lib/recap-completion.ts';
const state=(status='rendering',overrides={})=>({id:'project-a',status,hasOutput:false,run:{id:'run-a',phase:'generate',status:'running'},...overrides});
const finished=(overrides={})=>state('completed',{hasOutput:true,run:{id:'run-a',phase:'generate',status:'completed'},...overrides});

test('a final video export completing triggers feedback',()=>assert.equal(isNewCompletedVideo(state(),finished()),true));
test('opening an already completed project or switching projects does not announce old success',()=>{
 assert.equal(isNewCompletedVideo(null,finished()),false);
 assert.equal(isNewCompletedVideo(finished(),finished()),false);
 assert.equal(isNewCompletedVideo(state(),finished({id:'project-b'})),false);
});
test('analysis completion and preview completion cannot announce final video success',()=>{
 assert.equal(isNewCompletedVideo(state(),finished({run:{id:'preview-a',phase:'preview',status:'completed'}})),false);
 assert.equal(isNewCompletedVideo(state(),state('awaiting_review',{run:{id:'analysis-a',phase:'analyze',status:'completed'}})),false);
});
test('audio-only, subtitle-only and incomplete publishing cannot claim a video is ready',()=>{
 assert.equal(isNewCompletedVideo(state(),finished({hasOutput:false})),false);
 assert.equal(isNewCompletedVideo(state(),finished({run:{id:'run-a',phase:'generate',status:'running'}})),false);
 assert.equal(isNewCompletedVideo(state(),state('failed',{hasOutput:true})),false);
 assert.equal(isNewCompletedVideo(state(),null),false);
});
test('retained prior output does not cause premature success during a new export',()=>{
 assert.equal(isNewCompletedVideo(finished(),state('rendering',{hasOutput:true})),false);
 assert.equal(isNewCompletedVideo(state('rendering',{hasOutput:true}),finished()),true);
});
test('a new immediately completed run is announced even if the previous export was also complete',()=>{
 assert.equal(isNewCompletedVideo(finished(),finished({run:{id:'run-b',phase:'generate',status:'completed'}})),true);
});
