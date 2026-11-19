import assert from 'node:assert/strict';
import {test} from 'node:test';
import {liveCaption, previewDimensions, formatSettingNumber} from '../../resources/js/lib/recap-preview.ts';

const settings = {aspect:'original', quality:'source'};
test('portrait output uses the same short edge and even dimensions as export', () => {
    assert.deepEqual(previewDimensions({...settings, aspect:'9:16'},1920,1080),[1080,1920]);
    assert.deepEqual(previewDimensions({...settings,aspect:'9:16',quality:'720p'},1920,1080),[720,1280]);
    assert.deepEqual(previewDimensions({...settings,aspect:'16:9'},360,640),[640,360]);
});
test('source quality does not upscale and odd source dimensions become encoder-safe', () => {
    assert.deepEqual(previewDimensions({...settings,quality:'1080p'},641,361),[640,360]);
    assert.deepEqual(previewDimensions({...settings,aspect:'1:1'},1920,1080),[1080,1080]);
});
const line={index:0,start:2,end:12,text:'Narration',subtitle_text:'Translated caption',included:true};
test('live captions use subtitle translation and source intervals with exclusive ends', () => {
    assert.equal(liveCaption([line],1.99),'');
    assert.equal(liveCaption([line],2),'Translated caption');
    assert.equal(liveCaption([line],11.99),'Translated caption');
    assert.equal(liveCaption([line],12),'');
});
test('excluded scenes never leak captions and missing translations use narration', () => {
    assert.equal(liveCaption([{...line,included:false}],5),'');
    assert.equal(liveCaption([{...line,subtitle_text:undefined}],5),'Narration');
});
test('long Myanmar captions are chunked by Unicode code points with proportional timing', () => {
    const long={...line,start:0,end:10,subtitle_text:'က'.repeat(100)+'🙂tail'};
    assert.equal(liveCaption([long],0),'က'.repeat(100));
    assert.equal(liveCaption([long],9.99),'🙂tail');
    assert.equal(liveCaption([long],10),'');
});
test('dragged margins show exactly two decimals without changing the underlying value',()=>{
 assert.equal(formatSettingNumber(.10586323020968968,true),'0.11');
 assert.equal(formatSettingNumber(.1,true),'0.10');
 assert.equal(formatSettingNumber(1.35000000004),'1.35');
 assert.equal(formatSettingNumber(32),'32');
});

test('hidden scene subtitles stay absent while adjacent visible scenes keep their captions',()=>{
 const row={index:0,start:0,end:1,source_text:'source',text:'hidden',subtitle_hidden:true};
 assert.equal(liveCaption([row,{...row,index:1,start:1,end:2,text:'visible',subtitle_hidden:false}],.5),'');
 assert.equal(liveCaption([row,{...row,index:1,start:1,end:2,text:'visible',subtitle_hidden:false}],1.5),'visible');
});
