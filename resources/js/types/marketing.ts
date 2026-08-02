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
    site_name: string;
    type: string;
    robots: string;
    structured_data: Record<string, unknown> | null;
    head: string[];
};

export type MarketingSite = {
    brand: {
        name: string;
        home_url: string;
    };
    navigation: {
        aria_label: string;
        mobile_open_label: string;
        items: Array<{ label: string; url: string }>;
        sign_in_label: string;
        sign_in_url: string;
        primary_button_label: string;
        primary_button_url: string;
    };
    footer: {
        brand_body: string;
        columns: Array<{
            title: string;
            links: Array<{ label: string; url: string }>;
        }>;
        workspace_title: string;
        workspace_body: string;
        copyright: string;
    };
    pricing_proof: {
        aria_label: string;
        free_value_fallback: string;
        free_minutes_suffix: string;
        free_active_label: string;
        free_fallback_label: string;
        upload_value_fallback: string;
        upload_active_label: string;
        upload_fallback_label: string;
        live_value_fallback: string;
        live_active_label: string;
        live_fallback_label: string;
        languages_value: string;
        languages_label: string;
        desktop_value: string;
        desktop_label: string;
    };
};

export type FaqItem = {
    question: string;
    answer: string;
};
