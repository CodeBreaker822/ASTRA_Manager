<?php

return [
    'pages' => [
        'pricing' => [
            'hero' => [
                'eyebrow' => 'Price',
                'title' => 'Pay as you go',
                'intro' => 'Every account gets 60 free transcription minutes each day. Buy extra minute credits only when a busy day needs more.',
            ],
            'faq' => [
                ['question' => 'Can I use JERVA Web offline?', 'answer' => 'No. The web edition is online-only and uses the server provider pipeline. Offline Whisper remains in the desktop app.'],
                ['question' => 'Is there recurring billing?', 'answer' => 'No. JERVA Web uses pay-as-you-go minute credits instead of monthly or yearly billing.'],
                ['question' => 'What happens when I reach my free minutes?', 'answer' => 'Your free 60 minutes reset the next day, or you can buy extra minute credits to keep working today.'],
            ],
        ],
        'features' => [
            'hero' => [
                'eyebrow' => 'Features',
                'title' => 'Online transcription, shaped like the desktop workspace.',
                'intro' => 'JERVA Web keeps the familiar light workspace, but every transcription action runs through the server. That means upload, live capture, polish, summarize, and export without shipping local models to the browser.',
            ],
            'feature_rows' => [
                [
                    'eyebrow' => 'Live',
                    'icon' => 'Mic',
                    'title' => 'Live transcription in the browser',
                    'body' => 'Record from the browser and send short chunks to the server for online transcription.',
                    'bullets' => ['MediaRecorder capture', 'Queued pending clips', 'Server-side processing'],
                ],
                [
                    'eyebrow' => 'Upload',
                    'icon' => 'FileAudio',
                    'title' => 'Upload audio',
                    'body' => 'Process common audio formats through the same provider pipeline already used by the server.',
                    'bullets' => ['WAV, MP3, M4A, AAC, OGG, FLAC', 'Async job polling', 'Progress states'],
                ],
                [
                    'eyebrow' => 'Polish',
                    'icon' => 'Languages',
                    'title' => 'Grammar fixes and translation',
                    'body' => 'Use preset instructions for English, Filipino, grammar cleanup, or a custom instruction.',
                    'bullets' => ['Translate to English', 'Translate to Filipino', 'Custom cleanup prompts'],
                ],
                [
                    'eyebrow' => 'Summaries',
                    'icon' => 'Sparkles',
                    'title' => 'Summarize transcripts',
                    'body' => 'Summarize either raw or cleaned text with the existing LLM cleaner services.',
                    'bullets' => ['Raw or cleaned source', 'Long transcript splitting', 'Provider fallback'],
                ],
                [
                    'eyebrow' => 'Export',
                    'icon' => 'FileSpreadsheet',
                    'title' => 'Export to TXT, Word, and Excel',
                    'body' => 'Move transcript work into the formats teams already use for review and reporting.',
                    'bullets' => ['Raw exports', 'Cleaned exports', 'Signed downloads'],
                ],
                [
                    'eyebrow' => 'Fallback',
                    'icon' => 'Network',
                    'title' => 'Multi-provider accuracy',
                    'body' => 'The web workspace reuses configured server providers and fallback behavior.',
                    'bullets' => ['AssemblyAI, Deepgram, Groq, and more', 'Admin-managed ordering', 'API request logs'],
                ],
            ],
            'cta' => [
                'title' => 'Build your first online transcript.',
                'body' => 'Start with 60 free minutes each day, then add pay-as-you-go credits for heavier transcript days.',
                'button_label' => 'Create account',
            ],
        ],
        'download' => [
            'hero' => [
                'eyebrow' => 'Desktop app',
                'title' => 'Get JERVA for desktop',
                'intro' => 'Use JERVA Web for online transcription anywhere. Use the desktop app when you need offline Whisper, local VAD, speaker separation, and files that stay on your machine.',
            ],
            'download_card' => [
                'title' => 'Download for Windows',
                'body' => 'The current desktop distribution channel is Windows-only until additional platform packages are uploaded.',
                'button_label' => 'Download for Windows',
                'empty_label' => 'No package uploaded',
            ],
            'requirements' => [
                ['icon' => 'Laptop', 'title' => 'OS', 'body' => 'Windows desktop build for v1 distribution.'],
                ['icon' => 'Cpu', 'title' => 'Memory', 'body' => '8 GB RAM minimum. More memory helps local offline models.'],
                ['icon' => 'HardDrive', 'title' => 'Disk space', 'body' => 'Reserve space for the app package and optional offline models.'],
                ['icon' => 'ShieldCheck', 'title' => 'Account', 'body' => 'Pair with your JERVA account when online features are enabled.'],
            ],
            'account' => [
                'title' => 'Pair with your account',
                'body' => 'The desktop app can pair with the same account as the web workspace for online services. Offline transcription still runs on your machine.',
                'bullets' => ['Web: online, browser-based, no install', 'Desktop: offline models and local files'],
                'button_label' => 'Create account',
            ],
            'faq' => [
                ['question' => 'Is the desktop app free?', 'answer' => 'The download channel can publish the desktop app package. Online web transcription uses daily free minutes and pay-as-you-go credits.'],
                ['question' => 'What is different from the web version?', 'answer' => 'Web transcription is online-only. Desktop keeps the offline-capable model workflow and local processing features.'],
                ['question' => 'How do offline models work?', 'answer' => 'Offline models are downloaded and managed by the desktop application, not by the web workspace.'],
            ],
        ],
    ],
];
