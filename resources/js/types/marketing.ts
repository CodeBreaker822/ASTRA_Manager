export type MarketingPricing = {
    currency: string;
    free_minutes_per_day: number | null;
    upload_price_per_hour: number | null;
    live_price_per_hour: number | null;
};

export type MarketingSeo = {
    title: string;
    description: string;
    canonical_url: string;
    image_url: string;
    type: string;
    robots: string;
    structured_data: Record<string, unknown> | null;
};

export type FaqItem = {
    question: string;
    answer: string;
};
