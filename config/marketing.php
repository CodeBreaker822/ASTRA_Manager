<?php

return [
    'pages' => [
        'home' => [
            'seo' => [
                'title' => 'Free AI Transcription Online & Offline | JERVA',
                'description' => 'Transcribe audio in 99+ languages online or with JERVA’s free Windows app. Get free online minutes daily, pay as you go, and export clean text.',
            ],
            'hero' => [
                'eyebrow' => 'Free Windows app + low-cost online transcription',
                'title' => 'Free audio transcription, online or offline',
                'intro' => 'Turn meetings, interviews, lectures, podcasts, and voice recordings into useful text. Work in your browser when you want speed without setup, or use the free Windows app when you want Whisper transcription on your own computer.',
                'online_button_label' => 'Start transcribing online',
                'desktop_button_label' => 'Download free for Windows',
            ],
            'paths' => [
                [
                    'eyebrow' => 'Online',
                    'title' => 'Transcribe in your browser',
                    'body' => 'Upload a recording or capture live audio without installing a speech model. Online processing is a practical choice for lightweight computers and people who want to work from any modern browser.',
                    'bullets' => ['Free minutes refresh daily', 'No subscription required', 'Pay only when you need more'],
                    'button_label' => 'Start online',
                    'button_url' => '/register',
                ],
                [
                    'eyebrow' => 'Windows',
                    'title' => 'Keep transcription on your PC',
                    'body' => 'Download JERVA for free and run multilingual Whisper models locally. Once a model is installed, offline transcription does not need an account or an internet connection.',
                    'bullets' => ['Free desktop app', 'Local audio processing', 'Tiny through Large v3 and Turbo models'],
                    'button_label' => 'Download the app',
                    'button_url' => '/download',
                ],
            ],
            'workflow' => [
                'title' => 'From recording to finished transcript',
                'intro' => 'JERVA keeps the workflow simple whether the audio is processed online or on your computer.',
                'steps' => [
                    ['title' => 'Record or upload', 'body' => 'Capture a conversation live or add an existing WAV, MP3, M4A, AAC, OGG, or FLAC recording.'],
                    ['title' => 'Choose online or offline', 'body' => 'Use hosted transcription for convenience or a local Whisper model for free, offline processing.'],
                    ['title' => 'Review and export', 'body' => 'Clean the transcript, translate it, create a summary, and export raw or polished text to TXT, Word, or Excel.'],
                ],
            ],
            'use_cases' => [
                'title' => 'Built for real recordings',
                'intro' => 'Use one workspace for the audio you already create at work, in class, and while producing content.',
                'items' => [
                    ['title' => 'Professionals', 'body' => 'Create searchable notes from meetings, client calls, briefings, and project updates.'],
                    ['title' => 'Students and researchers', 'body' => 'Turn lectures, study sessions, and research interviews into text that is easier to review.'],
                    ['title' => 'Podcasters and creators', 'body' => 'Prepare episode transcripts, summaries, show notes, and source material for new content.'],
                ],
            ],
            'vad' => [
                'eyebrow' => 'Smarter local processing',
                'title' => 'Skip quiet sections in the Windows app',
                'body' => 'In the desktop workflow, Silero Voice Activity Detection finds spoken sections and skips quiet ranges before local transcription. This keeps long pauses out of the Whisper workload.',
                'note' => 'VAD is a desktop feature. Speech detection results depend on the recording and selected settings.',
            ],
            'faq' => [
                ['question' => 'Is JERVA really free?', 'answer' => 'Yes. The Windows desktop app is free, and local Whisper transcription does not use paid online minutes. Online accounts also receive a free transcription allowance that refreshes daily.'],
                ['question' => 'Do I need a subscription?', 'answer' => 'No. There is no required monthly or yearly subscription. Use the free desktop workflow, the daily online allowance, or add pay-as-you-go credit when you need more online processing.'],
                ['question' => 'Does JERVA support 99+ languages?', 'answer' => 'JERVA’s multilingual Whisper-based offline workflow can recognize more than 99 languages. Accuracy is not identical in every language and can change with the model, accent, speakers, background noise, and recording quality.'],
                ['question' => 'Do I need a powerful computer?', 'answer' => 'Not necessarily. The desktop app offers several Whisper model sizes and CPU fallback. If local transcription is too slow for your hardware, you can sign in and switch to online processing.'],
                ['question' => 'Where are offline recordings processed?', 'answer' => 'When offline mode is selected, transcription runs on your Windows computer. Online features send the required audio or text to JERVA’s hosted services.'],
            ],
            'cta' => [
                'title' => 'Choose the way you want to transcribe',
                'body' => 'Start online from any modern browser or download the free Windows app for local Whisper transcription.',
                'online_button_label' => 'Start online',
                'desktop_button_label' => 'Download for Windows',
            ],
        ],
        'pricing' => [
            'hero' => [
                'eyebrow' => 'Pricing',
                'title' => 'Use JERVA Transcriber as you go',
                'intro' => 'Every JERVA Transcriber account gets a free transcription allowance each day. Buy extra credits only when a busy day needs more.',
            ],
            'faq' => [
                ['question' => 'Can I use JERVA Transcriber offline?', 'answer' => 'Yes. The free Windows app can run Whisper locally after you download a model. The browser workspace uses online processing.'],
                ['question' => 'Is there recurring billing?', 'answer' => 'No. JERVA Transcriber uses a free daily allowance and pay-as-you-go credits instead of required monthly or yearly billing.'],
                ['question' => 'What happens when I reach my free minutes?', 'answer' => 'Your free allowance refreshes the next day, or you can buy credit to keep using online transcription immediately.'],
            ],
        ],
        'features' => [
            'seo' => [
                'title' => 'AI Transcription Features for Audio & Meetings | JERVA',
                'description' => 'Explore JERVA’s online and offline transcription, Whisper models, silence removal, speaker separation, transcript cleanup, summaries and exports.',
            ],
            'hero' => [
                'eyebrow' => 'Features',
                'title' => 'Everything you need to turn audio into useful text',
                'intro' => 'Choose a fast browser workflow or free local transcription on Windows. JERVA handles the practical work around a transcript too: speech detection, speaker grouping, cleanup, translation, summaries, and exports.',
            ],
            'feature_rows' => [
                [
                    'eyebrow' => 'Online or offline',
                    'icon' => 'Network',
                    'title' => 'Transcribe in your browser or on your PC',
                    'body' => 'Use online processing when you want convenience or have limited hardware. Switch to a local Whisper model in the free Windows app when you want offline transcription.',
                    'bullets' => ['Browser-based online workspace', 'Free local Windows workflow', 'One familiar transcript workspace'],
                ],
                [
                    'eyebrow' => 'Live and uploaded audio',
                    'icon' => 'Mic',
                    'title' => 'Record live or upload a file',
                    'body' => 'Capture live speech in the workspace or bring in an existing recording. Progress and pending sections stay visible while longer audio is processed.',
                    'bullets' => ['WAV, MP3, M4A, AAC, OGG, and FLAC', 'Live microphone capture', 'Pause, retry, and progress controls'],
                ],
                [
                    'eyebrow' => 'Multilingual',
                    'icon' => 'Languages',
                    'title' => 'Transcribe speech in 99+ languages',
                    'body' => 'Multilingual Whisper models can identify and transcribe speech across more than 99 languages. Results vary by language, model, accent, background noise, and recording quality.',
                    'bullets' => ['Automatic language detection', 'Multiple Whisper model sizes', 'Translation and cleanup tools'],
                ],
                [
                    'eyebrow' => 'Smarter audio',
                    'icon' => 'FileAudio',
                    'title' => 'Skip silence and separate speakers',
                    'body' => 'Silero VAD finds spoken ranges so quiet sections do not need to be transcribed. Speaker separation groups text by detected voice when the recording supports it.',
                    'bullets' => ['Desktop voice activity detection', 'Less local Whisper work on quiet recordings', 'Detected speaker grouping'],
                ],
                [
                    'eyebrow' => 'Ready to use',
                    'icon' => 'Sparkles',
                    'title' => 'Polish, translate, and summarize',
                    'body' => 'Fix punctuation and obvious speech-to-text errors, translate a transcript, or turn a long recording into concise notes and action items.',
                    'bullets' => ['Raw text stays available', 'Custom cleanup instructions', 'Summaries from raw or polished text'],
                ],
                [
                    'eyebrow' => 'Flexible export',
                    'icon' => 'FileSpreadsheet',
                    'title' => 'Export to TXT, Word, and Excel',
                    'body' => 'Download the version you need for editing, reporting, research, publishing, or sharing with a team.',
                    'bullets' => ['Raw or cleaned transcript', 'TXT and Microsoft Word', 'Structured Excel export'],
                ],
            ],
            'comparison' => [
                'title' => 'Online and desktop transcription, compared',
                'intro' => 'Both options lead to the same JERVA workflow. Choose based on your hardware, privacy needs, and whether you want hosted processing.',
                'rows' => [
                    ['label' => 'Setup', 'online' => 'Works in a modern browser', 'desktop' => 'Install the free Windows app and a Whisper model'],
                    ['label' => 'Cost', 'online' => 'Free daily allowance, then pay as you go', 'desktop' => 'Free local transcription'],
                    ['label' => 'Hardware', 'online' => 'Good for lightweight computers', 'desktop' => 'Speed depends on CPU, RAM, GPU, and model'],
                    ['label' => 'Audio processing', 'online' => 'Processed by hosted transcription services', 'desktop' => 'Processed locally in offline mode'],
                    ['label' => 'Account', 'online' => 'JERVA account required', 'desktop' => 'No account required for offline transcription'],
                    ['label' => 'Best for', 'online' => 'Convenience and limited local hardware', 'desktop' => 'Free, private, offline-capable transcription'],
                ],
            ],
            'faq' => [
                ['question' => 'Which Whisper model should I use?', 'answer' => 'Start with Tiny or Small on limited hardware and move to Medium, Large v3, or Turbo when you have more memory or compatible GPU resources. Larger models can improve results but take more storage and processing power.'],
                ['question' => 'Will speaker separation always identify every person correctly?', 'answer' => 'No. Speaker grouping depends on voice differences, overlapping speech, microphone quality, noise, and the recording. Treat labels as a helpful first pass and review important transcripts.'],
                ['question' => 'Can JERVA translate a transcript?', 'answer' => 'Yes. You can translate and clean transcript text with presets or custom instructions. Keep the raw transcript available so names, numbers, and important wording can be checked.'],
                ['question' => 'Where is voice activity detection available?', 'answer' => 'Silero VAD is part of the Windows desktop workflow. It finds spoken ranges before local Whisper transcription. JERVA does not currently advertise VAD as a way to reduce online billing.'],
            ],
            'cta' => [
                'title' => 'Start with the workflow that suits you',
                'body' => 'Create an online account or download the free Windows app for local Whisper transcription.',
                'online_button_label' => 'Start online',
                'desktop_button_label' => 'Download for Windows',
            ],
        ],
        'download' => [
            'seo' => [
                'title' => 'Free Offline Whisper Transcription App for Windows | JERVA',
                'description' => 'Download JERVA free for Windows. Transcribe locally with Whisper, skip silence with VAD, separate speakers, and keep offline audio on your computer.',
            ],
            'hero' => [
                'eyebrow' => 'Free Windows transcription app',
                'title' => 'Free offline transcription for Windows',
                'intro' => 'Run multilingual Whisper models on your own computer without a subscription. JERVA can transcribe live or uploaded audio locally, skip silent ranges, separate detected speakers, and export finished text.',
            ],
            'download_card' => [
                'title' => 'Download JERVA for Windows',
                'body' => 'The app is free. No account is required for offline transcription, and you can add optional online processing whenever local hardware is not the right fit.',
                'button_label' => 'Download free for Windows',
                'empty_label' => 'Release temporarily unavailable',
            ],
            'benefits' => [
                ['icon' => 'ShieldCheck', 'title' => 'Local when you want it', 'body' => 'Select offline mode to process recordings on your Windows computer instead of sending the audio to hosted transcription.'],
                ['icon' => 'Mic', 'title' => 'Live and uploaded audio', 'body' => 'Record speech directly or transcribe existing WAV, MP3, M4A, AAC, OGG, and FLAC files.'],
                ['icon' => 'Scissors', 'title' => 'Skip silent sections', 'body' => 'Silero VAD detects speech and removes quiet ranges from the transcription workload.'],
                ['icon' => 'Users', 'title' => 'Group detected speakers', 'body' => 'Speaker separation can organize transcript sections by voice when the audio is clear enough.'],
            ],
            'models' => [
                'title' => 'Choose a Whisper model for your hardware',
                'intro' => 'Model downloads are optional and managed inside the desktop app. Smaller models run with fewer resources; larger models trade more memory and storage for stronger recognition.',
                'items' => [
                    ['name' => 'Tiny', 'size' => '42 MiB', 'best_for' => 'Fast tests and computers with limited memory'],
                    ['name' => 'Small', 'size' => '252 MiB', 'best_for' => 'A practical everyday balance of speed and quality'],
                    ['name' => 'Medium', 'size' => '785 MiB', 'best_for' => 'More demanding recordings on capable hardware'],
                    ['name' => 'Large v3', 'size' => '1.1 GiB', 'best_for' => 'Higher-quality local recognition with more resources'],
                    ['name' => 'Turbo', 'size' => '834 MiB', 'best_for' => 'Faster high-capability transcription'],
                ],
                'note' => 'Transcription speed and accuracy vary with language, model, hardware, speakers, and recording quality.',
            ],
            'requirements' => [
                ['icon' => 'Laptop', 'title' => 'Windows', 'body' => 'A current 64-bit Windows desktop or laptop. The public desktop release is Windows-only.'],
                ['icon' => 'Cpu', 'title' => '8 GB RAM recommended', 'body' => 'Smaller models use less memory. More RAM and an optional compatible GPU help larger models run smoothly.'],
                ['icon' => 'HardDrive', 'title' => 'Model storage', 'body' => 'Allow space for the app plus the models you choose, from about 42 MiB to 1.1 GiB each.'],
                ['icon' => 'ShieldCheck', 'title' => 'Internet for setup', 'body' => 'Internet is needed to download the app and a Whisper model. After that, offline transcription can run without an account.'],
            ],
            'steps' => [
                'title' => 'Create your first offline transcript',
                'items' => [
                    ['title' => 'Install JERVA', 'body' => 'Download the current Windows package and complete the installation.'],
                    ['title' => 'Choose a model', 'body' => 'Let JERVA match a Whisper model to your available resources or select one yourself.'],
                    ['title' => 'Record or upload', 'body' => 'Use live capture or add an audio file, then review, polish, summarize, and export the result.'],
                ],
            ],
            'account' => [
                'title' => 'Online transcription is there when you need it',
                'body' => 'Sign in from the desktop app to switch to hosted processing on a lightweight computer or when you do not want to wait for a local model.',
                'bullets' => ['Offline: free local Whisper transcription', 'Online: free daily allowance and pay-as-you-go credit'],
                'button_label' => 'Create an online account',
            ],
            'faq' => [
                ['question' => 'Is the Windows app completely free?', 'answer' => 'Yes. The app and offline transcription workflow are free. Optional hosted transcription uses the same free daily allowance and pay-as-you-go pricing as the browser workspace.'],
                ['question' => 'Do I need an account?', 'answer' => 'No account is required to use installed Whisper models in offline mode. An account is required only for online transcription and account-based services.'],
                ['question' => 'Will the app work without internet?', 'answer' => 'Yes, after the app and at least one Whisper model have been downloaded. Online transcription, model downloads, and account services still require internet access.'],
                ['question' => 'Does offline mode upload my recording?', 'answer' => 'No. When offline mode is selected, Whisper transcription runs on your computer. Be sure to check the selected mode before processing sensitive audio.'],
                ['question' => 'Which languages are supported?', 'answer' => 'The multilingual Whisper models used by JERVA can recognize more than 99 languages. Performance varies widely, so review important names, numbers, and quotations.'],
                ['question' => 'What if my computer is too slow?', 'answer' => 'Try a smaller Whisper model, adjust the resource settings, or sign in and use optional online processing. JERVA supports CPU fallback and can use compatible GPU resources when available.'],
            ],
        ],
    ],
];
