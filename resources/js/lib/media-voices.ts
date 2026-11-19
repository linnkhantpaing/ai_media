/** UI aliases only: stable provider voice IDs are always submitted to the server. */
const voices: Record<string, {gender: 'male'|'female'; names: Record<'en'|'my'|'th', string>}> = {
    Kore: {gender:'female', names:{en:'Mya Mya',my:'မြမြ',th:'มะลิ'}},
    Charon: {gender:'male', names:{en:'Thiha',my:'သီဟ',th:'ธีร์'}},
    Fenrir: {gender:'male', names:{en:'Ye Min',my:'ရဲမင်း',th:'ภาคิน'}},
    Aoede: {gender:'female', names:{en:'Thazin',my:'သဇင်',th:'ลลิน'}},
    Orus: {gender:'male', names:{en:'Aung Min',my:'အောင်မင်း',th:'นรินทร์'}},
    Leda: {gender:'female', names:{en:'Nilar',my:'နီလာ',th:'นลิน'}},
};
const genders = {en:{male:'Male',female:'Female'},my:{male:'ကျား',female:'မ'},th:{male:'ชาย',female:'หญิง'}};
export function voiceLabel(id: string, locale: string): string {
    const voice=voices[id];
    if (!voice) return id;
    const language=locale==='my'||locale==='th'?locale:'en';
    return voice.names[language]+' ('+genders[language][voice.gender]+')';
}
