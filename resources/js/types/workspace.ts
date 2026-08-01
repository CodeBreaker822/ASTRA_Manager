export type WorkspaceMode = 'choose' | 'live' | 'upload';
export type TranscriptContentSource = 'raw' | 'cleaned' | 'summary';
export type ExportFormat = 'txt' | 'docx' | 'xlsx';

export type Project = {
    id: number;
    title: string;
    updated_at: string | null;
    transcripts_count: number;
};

export type TranscriptSection = {
    id: number;
    position: number;
    text: string;
    cleaned_text: string | null;
    started_at_ms: number | null;
    ended_at_ms: number | null;
};

export type ProcessingLogEntry = {
    status: string;
    message: string;
    created_at: string;
    context?: Record<string, unknown>;
};

export type Transcript = {
    id: number;
    source: string;
    status: string;
    duration_seconds: number;
    transcription_progress: {
        processed_clips: number;
        total_clips: number;
        percentage: number;
    };
    raw_text: string | null;
    cleaned_text: string | null;
    can_undo_polish: boolean;
    summary_text: string | null;
    polish_status: 'idle' | 'processing' | 'complete' | 'failed';
    polish_error_message: string | null;
    summary_status: 'idle' | 'processing' | 'complete' | 'failed';
    summary_error_message: string | null;
    processing_log: ProcessingLogEntry[];
    sections: TranscriptSection[];
};

export type ActiveProject = Project & {
    transcripts: Transcript[];
};

export type Entitlements = {
    plan: {
        key: string;
        name: string;
        minutes: number;
        free_polish_uses_per_day: number;
        free_summary_uses_per_day: number;
        features: Record<string, unknown>;
    };
    usage: {
        period: string;
        minutes_used: number;
        minutes_remaining: number;
        seconds_transcribed: number;
        polish_count: number;
        summary_count: number;
        free_polish_remaining: number;
        free_summary_remaining: number;
        wallet_balance: number;
        wallet_balance_cents: number;
    };
};

export type TranscriptRow = {
    id: string;
    range: string;
    text: string;
    transcript: Transcript;
};

export type EmptyWorkspacePanel = {
    eyebrow: string;
    title: string;
    copy: string;
};
