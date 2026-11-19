import {ActionTooltip} from '@/components/action-tooltip';
import {StudioIcon} from '@/components/studio-icon';
import '../../css/filter-actions.css';

export function ClearFiltersButton({label, disabled, onClick}: {label:string; disabled:boolean; onClick:()=>void}) {
 return <ActionTooltip label={label}><button type="button" className="filter-clear-button" aria-label={label} disabled={disabled} onClick={onClick}><StudioIcon name="filter-clear"/></button></ActionTooltip>;
}
