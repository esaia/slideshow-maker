export interface StepData {
    name: string;
    status: 'pending' | 'running' | 'done' | 'failed';
    progress: number;
    log: string | null;
}

export interface SegmentData {
    id: number;
    video_id: number;
    video: string;
    start_s: number;
    end_s: number;
    used_in_render: boolean;
}

export interface SourceVideoData {
    id: number;
    name: string;
    duration: number | null;
    shot_at: string | null;
    has_proxy: boolean;
}

export interface ProjectData {
    id: number;
    name: string;
    status: 'pending' | 'preparing' | 'ready' | 'rendering' | 'done' | 'failed';
    error: string | null;
    aspect: 'landscape' | 'vertical' | null;
    music_path: string;
    music_duration: number;
    created_at: string;
    updated_at: string;
    source_videos: SourceVideoData[];
    steps: StepData[];
    segments: SegmentData[];
    outputs: string[];
}

export const STEP_LABELS: Record<string, string> = {
    ingest: 'Reading video metadata',
    proxies: 'Creating preview videos',
    music: 'Analyzing music beats',
    plan: 'Planning the edit',
    render: 'Rendering the video',
};

export function fmtTime(s: number): string {
    const m = Math.floor(s / 60);
    const sec = (s % 60).toFixed(1).padStart(4, '0');
    return `${m}:${sec}`;
}
