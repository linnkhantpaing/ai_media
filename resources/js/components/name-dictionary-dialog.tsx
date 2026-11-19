import {useEffect, useId, useRef, useState, type FormEvent} from 'react';
import {MediaDialog} from '@/components/media-workflow';
import {StudioIcon} from '@/components/studio-icon';
import {MediaApiError, failureCode, mediaApi} from '@/lib/media-api';
import {useMediaText} from '@/lib/media-i18n';
import {useRecapText} from '@/lib/recap-i18n';
import {useToast} from '@/lib/toast';
import type {NameDictionaryEntry} from '@/types/media';
import '../../css/name-dictionary.css';

type Props = {open:boolean; entries:NameDictionaryEntry[]; url:string; projectId?:string; onClose:()=>void; onSaved:(entries:NameDictionaryEntry[])=>void};
export function NameDictionaryDialog({open,entries,url,projectId,onClose,onSaved}:Props){
 const t=useRecapText(),{error}=useMediaText(),toast=useToast(),id=useId();
 const [rows,setRows]=useState<NameDictionaryEntry[]>([]),[saving,setSaving]=useState(false),[fields,setFields]=useState<Record<string,string[]>>({});
 const form=useRef<HTMLFormElement>(null);
 useEffect(()=>{if(open){setRows(entries.map(entry=>({...entry})));setFields({});}},[open,entries]);
 useEffect(()=>{if(Object.keys(fields).length)form.current?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus();},[fields]);
 function update(index:number,key:keyof NameDictionaryEntry,value:string){setRows(current=>current.map((row,i)=>i===index?{...row,[key]:value}:row));setFields({});}
 async function save(event:FormEvent){
  event.preventDefault();if(saving)return;setSaving(true);setFields({});
  try{const result=await mediaApi<{name_dictionary:NameDictionaryEntry[]}>(url,'PUT',{name_dictionary:rows,...(projectId?{project_id:projectId}:{})});onSaved(result.name_dictionary);onClose();toast.success(t('Dictionary saved'));}
  catch(reason){const issues=reason instanceof MediaApiError?reason.fields:{};setFields(issues);toast.error(Object.values(issues).flat().join(' ')||error(failureCode(reason)));}
  finally{setSaving(false);}
 }
 return <MediaDialog open={open} title={t('Name Dictionary')} headerIcon="file" headerDetail={t('Saved for your account. Reused in new videos.')} animated className="name-dictionary-dialog" onClose={()=>{if(!saving)onClose();}}>
  <form ref={form} onSubmit={save} noValidate aria-busy={saving} className="name-dictionary-form">
   <div className="name-dictionary-body">
    <p className="name-dictionary-help"><StudioIcon name="info"/>{t('Keep character names and pronunciation consistent.')}</p>
    {!rows.length&&<div className="name-dictionary-empty"><StudioIcon name="file"/><span>{t('Add the names you want AI to use.')}</span></div>}
    {rows.map((row,index)=><section className="name-dictionary-row" key={index} aria-label={t('Name')+' '+(index+1)}>
     <span className="name-dictionary-number">{index+1}</span>
     {(['original','translated','pronunciation'] as const).map((key,i)=>{const field='name_dictionary.'+index+'.'+key;return <label key={key} htmlFor={id+index+key}><span>{t(['Original name','Translated name','Pronunciation'][i])}{key!=='pronunciation'?<b aria-hidden="true"> *</b>:<small> {t('Optional')}</small>}</span><input id={id+index+key} value={row[key]??''} maxLength={100} autoComplete="off" disabled={saving} aria-required={key!=='pronunciation'} aria-invalid={!!fields[field]} aria-describedby={fields[field]?id+field:undefined} placeholder={t(['Original name','Translated name','Pronunciation'][i])} onChange={e=>update(index,key,e.target.value)}/>{fields[field]&&<small className="recap-error" id={id+field}>{fields[field].join(' ')}</small>}</label>;})}
     <button className="media-button name-dictionary-remove" type="button" disabled={saving} onClick={()=>{setRows(current=>current.filter((_,i)=>i!==index));setFields({});}}><StudioIcon name="trash"/>{t('Remove')}</button>
    </section>)}
    <button className="media-button name-dictionary-add" type="button" disabled={saving||rows.length>=50} onClick={()=>setRows(current=>[...current,{original:'',translated:'',pronunciation:''}])}><StudioIcon name="plus"/>{t('Add name')}<small>{rows.length} / 50</small></button>
   </div>
   <footer><small><StudioIcon name="shield"/>{t('Only your account can use these saved names.')}</small><button className="media-button primary" type="submit" disabled={saving}><StudioIcon name={saving?'loader':'check'} className={saving?'is-spinning':undefined}/>{t(saving?'Saving...':'Done')}</button></footer>
  </form>
 </MediaDialog>;
}
