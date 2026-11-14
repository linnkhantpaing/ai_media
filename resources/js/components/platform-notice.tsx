import {usePage} from '@inertiajs/react';
import {StudioIcon} from '@/components/studio-icon';
import type {SharedData} from '@/types';
export function PlatformNotice({adminArea}:{adminArea:boolean}){const {app}=usePage<SharedData>().props;return !adminArea&&app.maintenance?.enabled?<div className="platform-maintenance" role="status"><StudioIcon name="wrench"/><span>{app.maintenance.message}</span></div>:null;}
export function PlatformSupport(){const {app}=usePage<SharedData>().props;if(!app.support_email&&!app.support_phone&&!app.support_url)return null;return <div className="platform-support">{app.support_email&&<a href={'mailto:'+app.support_email}>{app.support_email}</a>}{app.support_phone&&<a href={'tel:'+app.support_phone.replace(/[^+0-9]/g,'')}>{app.support_phone}</a>}{app.support_url?.startsWith('https://')&&<a href={app.support_url} target="_blank" rel="noopener noreferrer">{app.support_url}</a>}</div>;}
