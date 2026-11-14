import { useId } from 'react';

export function MediaMark() {
    const id = useId();
    return (
        <svg className="media-mark" viewBox="0 0 52 56" fill="none" aria-hidden="true">
            <defs>
                <linearGradient id={id} x1="2" y1="6" x2="44" y2="50" gradientUnits="userSpaceOnUse">
                    <stop stopColor="var(--primary-hover)" /><stop offset=".44" stopColor="var(--accent)" /><stop offset="1" stopColor="var(--primary-active)" />
                </linearGradient>
            </defs>
            <path d="M7 13C7 5 12.5 1.8 19.3 5.6l25 14.6c6.9 4 6.9 11.6 0 15.6l-25 14.6C12.5 54.2 7 51 7 43V13Zm12 3v24l20-12-20-12Z" fill={'url(#' + id + ')'} fillRule="evenodd" />
            <path d="m7.5 10 11.5 6 20 12 7-4.2L19.3 5.6C13.8 2.5 9.1 4 7.5 10Z" fill="var(--primary-hover)" fillOpacity=".36" />
            <path d="m7 43 12-3V16l-12-6v33Z" fill="var(--accent)" fillOpacity=".5" />
        </svg>
    );
}
