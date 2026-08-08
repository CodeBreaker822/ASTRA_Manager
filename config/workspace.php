<?php

/*
|--------------------------------------------------------------------------
| Workspace options
|--------------------------------------------------------------------------
|
| Choices the workspace offers for polishing and exporting a transcript. Kept
| server-side so blade renders the buttons and their instructions directly.
|
*/

return [

    'default_polish_preset' => 'grammar',

    'polish_presets' => [
        'english' => [
            'label' => 'Translate to English',
            'instruction' => 'Translate every non-English part of the transcript into clear English. Treat Cebuano, Bisaya, Filipino, Tagalog, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
        ],
        'filipino' => [
            'label' => 'Translate to Filipino',
            'instruction' => 'Translate every non-Filipino part of the transcript into clear Filipino. Treat English, Cebuano, Bisaya, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
        ],
        'grammar' => [
            'label' => 'Fix grammar',
            'instruction' => 'Fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes without translating the transcript. Preserve the original language choices, meaning, names, titles, numbers, and time order.',
        ],
        'translate_fix' => [
            'label' => 'Translate and fix',
            'instruction' => 'Translate every non-English sentence, phrase, or word into polished English, then fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes. Preserve meaning, speaker intent, names, titles, numbers, and time order.',
        ],
    ],

    'export_formats' => [
        'txt' => 'TXT',
        'docx' => 'Word',
        'xlsx' => 'Excel',
    ],

    'export_sources' => [
        'raw' => 'Raw transcript',
        'cleaned' => 'Polished transcript',
        'summary' => 'Summary',
    ],

];
