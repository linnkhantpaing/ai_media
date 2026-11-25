import { useEffect, useState, type ReactNode } from 'react';

/** Customer profile photo with an initials fallback (also used when the image cannot load). */
export function AvatarPhoto({ src, fallback }: { src?: string | null; fallback: ReactNode }) {
    const [failed, setFailed] = useState(false);
    useEffect(() => setFailed(false), [src]);

    return src && !failed
        ? <img className="avatar-photo" src={src} alt="" loading="lazy" decoding="async" draggable={false} onError={() => setFailed(true)} />
        : <>{fallback}</>;
}
