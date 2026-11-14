import { useId } from 'react';

export function AdminLoginArt() {
    const id = useId().replaceAll(':', '');
    return <svg className="admin-cinema-art" viewBox="0 0 1672 941" fill="none" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient id={id + '-film'} x1="0" y1="0" x2="1" y2=".5"><stop stopColor="#160E35" /><stop offset=".35" stopColor="#6E3EC0" /><stop offset=".53" stopColor="#A575F2" /><stop offset=".72" stopColor="#301552" /><stop offset="1" stopColor="#100C25" /></linearGradient>
            <linearGradient id={id + '-body'} x1="0" y1="0" x2="1" y2="1"><stop stopColor="#8050BC" stopOpacity=".6" /><stop offset=".45" stopColor="#201337" stopOpacity=".8" /><stop offset="1" stopColor="#6F35A8" stopOpacity=".45" /></linearGradient>
            <linearGradient id={id + '-edge'}><stop stopColor="#AE84F5" stopOpacity="0" /><stop offset=".45" stopColor="#C4A1FF" stopOpacity=".85" /><stop offset="1" stopColor="#9A5DE1" stopOpacity=".12" /></linearGradient>
            <pattern id={id + '-stripes'} width="57" height="40" patternUnits="userSpaceOnUse" patternTransform="skewX(25)"><rect width="28" height="40" fill="#A16DEA" fillOpacity=".65" /><rect x="28" width="29" height="40" fill="#25173F" fillOpacity=".8" /></pattern>
            <filter id={id + '-glow'} x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="20" /></filter>
        </defs>
        <ellipse cx="1460" cy="500" rx="180" ry="80" fill="#8B5CF6" opacity=".17" filter={'url(#' + id + '-glow)'} />
        <g opacity=".62">
            <path d="M-75 584C68 451 219 447 285 581S411 800 499 749" stroke={'url(#' + id + '-film)'} strokeWidth="116" />
            <path d="M-75 526C68 393 219 389 285 523S411 742 499 691M-75 642C68 509 219 505 285 639S411 858 499 807" stroke={'url(#' + id + '-edge)'} strokeWidth="2" />
            <path d="M-75 538C68 405 219 401 285 535S411 754 499 703M-75 630C68 497 219 493 285 627S411 846 499 795" stroke="#100C24" strokeWidth="13" strokeDasharray="16 12" />
        </g>
        <g transform="translate(1355 350) rotate(-9)" opacity=".83">
            <rect y="6" width="212" height="176" rx="12" fill={'url(#' + id + '-body)'} stroke="#AA7CE3" strokeWidth="1.6" />
            <rect y="6" width="212" height="34" rx="6" fill={'url(#' + id + '-stripes)'} stroke="#9570C2" />
            <g transform="rotate(-12 5 7)"><rect x="0" y="-34" width="222" height="37" rx="5" fill={'url(#' + id + '-stripes)'} stroke="#BE99EF" strokeWidth="1.5" /></g>
            <rect x="1" y="-10" width="27" height="52" rx="5" fill="#683791" stroke="#B48BDD" />
            <circle cx="13" cy="2" r="3" fill="#D1ACF7" /><circle cx="13" cy="29" r="3" fill="#D1ACF7" />
            <path d="M80 81Q80 75 87 78L129 106Q135 110 129 114L87 140Q80 144 80 137Z" fill="#9B67DC" fillOpacity=".75" stroke="#C49AF4" strokeWidth="1.5" />
            <path d="M5 47V167Q5 176 16 176" stroke="#CC9BFF" strokeOpacity=".65" strokeWidth="2" />
        </g>
        <g opacity=".7">
            <path d="M1144 617C1322 685 1540 566 1740 400" stroke={'url(#' + id + '-film)'} strokeWidth="98" />
            <path d="M1144 568C1322 636 1540 517 1740 351M1144 666C1322 734 1540 615 1740 449" stroke={'url(#' + id + '-edge)'} strokeWidth="2" />
            <path d="M1144 579C1322 647 1540 528 1740 362M1144 655C1322 723 1540 604 1740 438" stroke="#170C31" strokeWidth="14" strokeDasharray="17 13" />
        </g>
    </svg>;
}