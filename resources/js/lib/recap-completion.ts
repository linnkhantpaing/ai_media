import type {MediaProject} from '../types/media';

type ExportState = Pick<MediaProject, 'id' | 'status' | 'hasOutput' | 'run'>;

export function isNewCompletedVideo(previous: ExportState | null, current: ExportState | null): boolean {
    if (!previous || !current || previous.id !== current.id) return false;
    if (current.status !== 'completed' || !current.hasOutput || current.run?.phase !== 'generate' || current.run.status !== 'completed') return false;
    return previous.status !== 'completed' || previous.run?.id !== current.run.id;
}
