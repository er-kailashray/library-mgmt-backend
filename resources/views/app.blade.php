<!DOCTYPE html>
<html lang="en-IN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#FAF7F2">
        @isset($meta)
            <meta name="description" content="{{ $meta['description'] }}">
            <link rel="canonical" href="{{ $meta['url'] }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="LibriSpace">
            <meta property="og:title" content="{{ $meta['title'] }}">
            <meta property="og:description" content="{{ $meta['description'] }}">
            <meta property="og:url" content="{{ $meta['url'] }}">
            <meta property="og:image" content="{{ url('/brand/og-image.png') }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="LibriSpace: run your study library from one app">
            <meta property="og:locale" content="en_IN">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $meta['title'] }}">
            <meta name="twitter:description" content="{{ $meta['description'] }}">
        @endisset
        @if (! empty($noindex))
            <meta name="robots" content="noindex, nofollow">
        @endif
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=jost:300,400,500,600|cormorant-garamond:500,600&display=swap" rel="stylesheet">
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
        {{-- Without the SSR server, @inertiaHead prints nothing: keep a real <title> for search engines. --}}
        @if (empty($__inertiaSsrResponse))
            <title>{{ $meta['title'] ?? 'LibriSpace' }}</title>
        @endif
        @isset($jsonLd)
            <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
        @endisset
    </head>
    <body>
        @inertia
    </body>
</html>
