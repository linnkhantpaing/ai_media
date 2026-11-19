import type {MediaLine, MediaSettings} from '@/types/media';

// Keep these dimensions and caption chunks aligned with MediaRenderer.
export function previewDimensions(settings: MediaSettings, width = 1920, height = 1080) {
    let w = Math.max(2, Math.floor(width / 2) * 2), h = Math.max(2, Math.floor(height / 2) * 2);
    const ceiling = settings.quality === '720p' ? 720 : settings.quality === '1080p' ? 1080 : Math.min(w, h);
    const factor = Math.min(1, ceiling / Math.min(w, h));
    w = Math.max(2, Math.floor(w * factor / 2) * 2); h = Math.max(2, Math.floor(h * factor / 2) * 2);
    if (settings.aspect === 'original') return [w, h];
    const short = Math.min(1080, w, h);
    return settings.aspect === '9:16' ? [short, Math.round(short * 16 / 9 / 2) * 2] : settings.aspect === '16:9' ? [Math.round(short * 16 / 9 / 2) * 2, short] : [short, short];
}

export function liveCaption(lines: MediaLine[], time: number) {
    const line = lines.find(line => line.included !== false && !line.subtitle_hidden && time >= line.start && time < line.end);
    if (!line) return '';
    const text = Array.from((line.subtitle_text ?? line.text).trim());
    const offset = Math.min(text.length - 1, Math.floor((time - line.start) / (line.end - line.start) * text.length));
    return text.slice(Math.floor(Math.max(0, offset) / 100) * 100, Math.floor(Math.max(0, offset) / 100) * 100 + 100).join('');
}

export function formatSettingNumber(value: number, fixed = false) {
    return Number.isFinite(value) ? (fixed ? value.toFixed(2) : String(Number(value.toFixed(2)))) : '0';
}
