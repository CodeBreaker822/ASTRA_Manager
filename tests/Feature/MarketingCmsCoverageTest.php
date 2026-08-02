<?php

use Illuminate\Support\Facades\File;

test('public marketing components contain no unmanaged visible copy', function () {
    $files = [
        resource_path('js/layouts/MarketingLayout.vue'),
        resource_path('js/components/WorkspacePreview.vue'),
        resource_path('js/components/marketing/PricingProof.vue'),
        resource_path('js/pages/marketing/Landing.vue'),
        resource_path('js/pages/marketing/AudioToText.vue'),
        resource_path('js/pages/marketing/Features.vue'),
        resource_path('js/pages/marketing/Price.vue'),
        resource_path('js/pages/marketing/Download.vue'),
        resource_path('js/pages/marketing/BlogIndex.vue'),
        resource_path('js/pages/marketing/BlogShow.vue'),
    ];

    foreach ($files as $file) {
        $source = File::get($file);
        preg_match('/<template>(.*)<\/template>/s', $source, $templateMatch);
        preg_match('/<script[^>]*>(.*)<\/script>/s', $source, $scriptMatch);
        $template = $templateMatch[1] ?? '';
        $script = $scriptMatch[1] ?? '';

        preg_match_all('/>\s*([A-Za-z][^<{]*?)\s*</s', $template, $textMatches);
        preg_match_all('/(?<!:)(?:aria-label|alt|title|placeholder|href)="[^"{]*[A-Za-z][^"]*"/', $template, $attributeMatches);
        preg_match_all('/([\'\"])([^\'\"\r\n]*[A-Za-z][^\'\"\r\n]*\s+[^\'\"\r\n]*)\1/', $script, $scriptMatches);

        expect($textMatches[1] ?? [])
            ->toBe([], basename($file).' contains a hardcoded text node.')
            ->and($attributeMatches[0] ?? [])
            ->toBe([], basename($file).' contains a hardcoded visible attribute.')
            ->and($scriptMatches[0] ?? [])
            ->toBe([], basename($file).' contains hardcoded copy in its script.');
    }
});
