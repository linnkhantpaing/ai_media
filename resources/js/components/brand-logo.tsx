import {useEffect} from 'react';
import {usePage} from '@inertiajs/react';
import {useTheme} from '@/lib/theme';
import type {SharedData} from '@/types';
export function BrandLogo(){
 const {app}=usePage<SharedData>().props;const {theme}=useTheme();
 useEffect(()=>{document.documentElement.dataset.appName=app.name;const icon=document.querySelector<HTMLLinkElement>('link[rel="icon"]');if(icon&&app.favicon)icon.href=app.favicon;const description=document.querySelector<HTMLMetaElement>('meta[name="description"]');if(description&&app.tagline)description.content=app.tagline;},[app.name,app.favicon,app.tagline]);
 return <img className="zatlan-logo" src={theme==='light'&&app.lightLogo?app.lightLogo:app.logo??'/images/zatlan-3d-film-logo.png'} alt={app.name} width={2172} height={724} decoding="async" draggable={false}/>;
}
