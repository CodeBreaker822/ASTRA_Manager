<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Human-readable names for the CMS content editor. Centralised so the same
 * content key is worded identically on every page that edits it.
 */
class CmsLabels
{
    /** Icon names the marketing content may reference. */
    public const ICONS = [
        'Network',
        'Mic',
        'Languages',
        'FileAudio',
        'Sparkles',
        'FileSpreadsheet',
        'ShieldCheck',
        'Scissors',
        'Users',
        'Laptop',
        'Cpu',
        'HardDrive',
    ];

    private const FIELDS = [
        'seo' => 'Search engine preview',
        'cta' => 'Final call to action',
        'faq' => 'Frequently asked questions',
        'faq_heading' => 'FAQ heading',
        'hero' => 'Hero section',
        'intro' => 'Introduction',
        'body' => 'Description',
        'eyebrow' => 'Small heading',
        'button_url' => 'Button destination',
        'primary_button_url' => 'Primary button destination',
        'secondary_button_url' => 'Secondary button destination',
        'online_button_url' => 'Online button destination',
        'desktop_button_url' => 'Desktop button destination',
        'home_url' => 'Home page destination',
        'aria_label' => 'Accessibility label',
        'mobile_open_label' => 'Mobile menu accessibility label',
        'seo_title_template' => 'Article SEO title template',
        'free_active_label' => 'Label when free minutes are configured',
        'free_fallback_label' => 'Label when no free minutes are configured',
        'upload_active_label' => 'Label when upload pricing is configured',
        'upload_fallback_label' => 'Label when upload pricing is unavailable',
        'live_active_label' => 'Label when live pricing is configured',
        'live_fallback_label' => 'Label when live pricing is unavailable',
        'audio_to_text' => 'Audio to text',
        'workspace_preview' => 'Workspace preview',
        'pricing_proof' => 'Shared pricing facts',
        'paths_intro' => 'Workflow choices heading',
        'benefits_intro' => 'Benefits heading',
        'requirements_intro' => 'Requirements heading',
        'feature_rows' => 'Feature sections',
        'feature_visual' => 'Feature illustration labels',
        'article_cta' => 'Article call to action',
        'plans_ui' => 'Pricing-card labels',
    ];

    private const SECTIONS = [
        'seo' => 'Search preview',
        'schema' => 'Search data',
        'brand' => 'Brand',
        'navigation' => 'Header navigation',
        'footer' => 'Footer',
        'pricing_proof' => 'Shared pricing facts',
        'hero' => 'Hero section',
        'workspace_preview' => 'Workspace preview',
        'pricing_note' => 'Pricing note',
        'paths_intro' => 'Workflow choices heading',
        'paths' => 'Transcription choices',
        'workflow' => 'How it works',
        'use_cases' => 'Use cases',
        'vad' => 'Voice activity detection',
        'benefits' => 'Benefits',
        'benefits_intro' => 'Benefits heading',
        'hero_preview' => 'Hero illustration',
        'comparison' => 'Comparison',
        'limitations' => 'Important limitations',
        'guides' => 'Recommended guides',
        'feature_rows' => 'Feature sections',
        'feature_visual' => 'Feature illustration labels',
        'download_card' => 'Download card',
        'models' => 'Whisper models',
        'requirements' => 'System requirements',
        'requirements_intro' => 'Requirements heading',
        'steps' => 'Getting started steps',
        'account' => 'Online account option',
        'topics' => 'Blog topics',
        'index' => 'Blog landing labels',
        'article' => 'Article layout',
        'article_cta' => 'Article call to action',
        'plans_ui' => 'Pricing-card labels',
        'faq_heading' => 'FAQ heading',
        'faq' => 'Frequently asked questions',
        'cta' => 'Final call to action',
    ];

    private const SECTION_DESCRIPTIONS = [
        'seo' => 'Control the page title and summary shown by search engines and social previews.',
        'schema' => 'Advanced names and descriptions supplied to search engines as structured data.',
        'navigation' => 'Edit the main menu, sign-in link, and primary header button.',
        'pricing_proof' => 'These five fact boxes appear on several marketing pages.',
        'workspace_preview' => 'Edit the example workspace shown beside the home-page headline.',
        'article' => 'Labels and safety copy shared by every published blog article.',
        'plans_ui' => 'Edit public labels only. Use Pricing Manager to change rates and allowances.',
        'faq' => 'Add, reorder, or remove questions. Keep answers direct and honest.',
    ];

    public static function humanize(string $key): string
    {
        return self::FIELDS[$key] ?? self::titleize($key);
    }

    public static function section(string $key): string
    {
        return self::SECTIONS[$key] ?? self::titleize($key);
    }

    public static function sectionDescription(string $key): string
    {
        return self::SECTION_DESCRIPTIONS[$key]
            ?? 'Edit the content used in the '.Str::lower(self::section($key)).' section.';
    }

    private static function titleize(string $key): string
    {
        return Str::title(str_replace('_', ' ', $key));
    }
}
