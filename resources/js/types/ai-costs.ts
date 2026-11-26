export type AiCostState = 'completed' | 'in_progress' | 'failed' | 'cancelled' | 'not_exported' | 'deleted';
export type AiCostPage<T> = { data: T[]; page: number; pages: number; total: number };
export type AiCostCustomer = { id: number; name: string; email: string; videos: number; tokens: number; ai_mmk: number; storage_bytes: number; storage_mmk: number; revenue_mmk: number; profit_mmk: number };
export type AiCostVideo = { id: string; title: string | null; customer: { name: string; email: string } | null; workflow: 'movie' | 'translate' | 'subtitles' | null; source_seconds: number; tokens: number; ai_mmk: number; state: AiCostState; last_day: string };
export type AiCostReportData = {
    range: { start: string; end: string; days: number; timezone: string };
    summary: { calls: number; input_tokens: number; output_tokens: number; total_tokens: number; ai_mmk: number; check_mmk: number; storage_bytes: number; storage_added: number; storage_removed: number; storage_mmk: number; wasted_mmk: number; wasted_tokens: number; revenue_mmk: number; profit_mmk: number };
    daily: { day: string; ai_mmk: number; storage_mmk: number; tokens: number }[];
    stages: { key: string; calls: number; tokens: number; ai_mmk: number }[];
    customers: AiCostPage<AiCostCustomer>;
    videos: AiCostPage<AiCostVideo>;
    pricing: { missing_models: string[]; rates_missing: boolean; configured: boolean };
};
export type AiCostFilters = { range: '1' | '7' | '30' | '90' | 'custom'; from?: string | null; to?: string | null; q?: string | null; customers_page?: number; videos_page?: number };
export type AiModelPrices = { input?: string | null; audio_input?: string | null; cached?: string | null; output?: string | null };
export type AiCostPricing = { revision: number; models: Record<string, AiModelPrices>; storage_gb_month: string; usd_mmk: string | null; thb_mmk: string | null; known_models: string[]; speech_model: string | null };
export type AiCostProject = { project: { id: string; title: string | null; customer: { name: string; email: string } | null; state: AiCostState }; calls: { at: string; stage: string; model: string; input_tokens: number; audio_input_tokens: number; output_tokens: number; total_tokens: number; ai_mmk: number | null }[]; total_tokens: number; ai_mmk: number; retention_days: number };
