@push('seo')
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical_url'] }}">
    <meta property="og:type" content="{{ $seo['type'] }}">
    <meta property="og:site_name" content="{{ $seo['site_name'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical_url'] }}">
    <meta property="og:image" content="{{ $seo['image_url'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    <meta name="twitter:image" content="{{ $seo['image_url'] }}">

    @if (! empty($seo['structured_data']))
        <script type="application/ld+json">
            {!! str_replace('<', '<', json_encode($seo['structured_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !!}
        </script>
    @endif
@endpush
