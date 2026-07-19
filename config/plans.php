<?php

return [
    'default' => 'free',

    'tiers' => [
        'free' => [
            'name' => 'Free',
            'tagline' => 'Start with clean upload transcription.',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'price_label' => '$0',
            'minutes' => 30,
            'cta' => 'Start free',
            'featured' => false,
            'features' => [
                '30 transcription minutes each month',
                'Upload audio transcription',
                'TXT export',
                'Community support',
            ],
            'entitlements' => [
                'upload' => true,
                'live' => false,
                'polish' => false,
                'summarize' => false,
                'exports' => ['txt'],
            ],
        ],

        'pro' => [
            'name' => 'Pro',
            'tagline' => 'For daily transcription work.',
            'monthly_price' => 19,
            'yearly_price' => 190,
            'price_label' => '$19',
            'minutes' => 600,
            'cta' => 'Create Pro workspace',
            'featured' => true,
            'features' => [
                '600 transcription minutes each month',
                'Live browser transcription',
                'Polish and summarize transcripts',
                'TXT, Word, and Excel exports',
            ],
            'entitlements' => [
                'upload' => true,
                'live' => true,
                'polish' => true,
                'summarize' => true,
                'exports' => ['txt', 'docx', 'xlsx'],
            ],
        ],

        'team' => [
            'name' => 'Team',
            'tagline' => 'Shared minutes and admin controls.',
            'monthly_price' => null,
            'yearly_price' => null,
            'price_label' => 'Custom',
            'minutes' => 3000,
            'cta' => 'Start team setup',
            'featured' => false,
            'features' => [
                'Pooled transcription minutes',
                'Seats and workspace controls',
                'Priority processing options',
                'Centralized exports and audit trail',
            ],
            'entitlements' => [
                'upload' => true,
                'live' => true,
                'polish' => true,
                'summarize' => true,
                'exports' => ['txt', 'docx', 'xlsx'],
                'team' => true,
            ],
        ],
    ],

    'comparison' => [
        'Upload audio transcription' => ['free', 'pro', 'team'],
        'Live browser transcription' => ['pro', 'team'],
        'Polish instructions' => ['pro', 'team'],
        'Transcript summaries' => ['pro', 'team'],
        'TXT export' => ['free', 'pro', 'team'],
        'Word and Excel exports' => ['pro', 'team'],
        'Team seats' => ['team'],
    ],
];
