import assert from 'node:assert/strict';
import {test} from 'node:test';
import {voiceLabel} from '../../resources/js/lib/media-voices.ts';
import {syncAudioControls,updateAudioControls} from '../../resources/js/lib/recap-audio.ts';

test('all six narrator IDs show localized names and the correct gender without changing provider IDs',()=>{
    for(const [id,gender] of [['Kore','female'],['Charon','male'],['Fenrir','male'],['Aoede','female'],['Orus','male'],['Leda','female']]){
        for(const [locale,label,script] of [['en',gender==='male'?'Male':'Female',/[A-Za-z]/],['my',gender==='male'?'ကျား':'မ',/[\u1000-\u109f]/],['th',gender==='male'?'ชาย':'หญิง',/[\u0e00-\u0e7f]/]]){
            const name=voiceLabel(id,locale);
            assert.match(name,script);assert.ok(name.endsWith('('+label+')'));assert.notEqual(name,id);
        }
    }
    assert.equal(voiceLabel('Charon','my'),'သီဟ (ကျား)');
    assert.equal(voiceLabel('Kore','my'),'မြမြ (မ)');
    assert.equal(voiceLabel('Future voice','my'),'Future voice');
    assert.equal(voiceLabel('Kore','unknown'),voiceLabel('Kore','en'));
});

test('an old mix selection never claims music while the background switch is off',()=>{
    const old={mode:'dub',keep_background:false,audio_track:'mix',background_volume:.35,voice:'Kore'};
    const state=syncAudioControls(old);
    assert.equal(state.audio_track,'narration');assert.equal(state.keep_background,false);
    assert.equal(old.audio_track,'mix');
});

test('selecting mix enables music and either control keeps both settings synchronized',()=>{
    const initial={mode:'dub',keep_background:false,audio_track:'narration',background_volume:.35,duck_background:true};
    const mix=updateAudioControls(initial,'audio_track','mix');
    assert.equal(mix.keep_background,true);assert.equal(mix.audio_track,'mix');
    const off=updateAudioControls(mix,'keep_background',false);
    assert.equal(off.audio_track,'narration');assert.equal(off.keep_background,false);
    const on=updateAudioControls(off,'keep_background',true);
    assert.equal(on.audio_track,'mix');
    const narration=updateAudioControls(on,'audio_track','narration');
    assert.equal(narration.keep_background,false);
    assert.equal(narration.background_volume,.35);assert.equal(narration.duck_background,true);
    assert.deepEqual(initial,{mode:'dub',keep_background:false,audio_track:'narration',background_volume:.35,duck_background:true});
});